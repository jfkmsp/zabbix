/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

#include "poller.h"
#include "module.h"
#include "zbxserver.h"

#include "checks_agent.h"
#include "checks_external.h"
#include "checks_internal.h"
#include "checks_script.h"
#include "checks_simple.h"
#include "checks_snmp.h"
#include "checks_db.h"
#include "checks_ssh.h"
#include "checks_telnet.h"
#include "checks_java.h"
#include "checks_calculated.h"
#include "checks_http.h"

#include "zbxnix.h"
#include "zbxself.h"
#include "zbxrtc.h"
#include "zbxcrypto.h"
#include "zbxjson.h"
#include "zbxhttp.h"
#include "log.h"
#include "zbxavailability.h"
#include "zbx_availability_constants.h"
#include "zbxcomms.h"
#include "zbxnum.h"
#include "zbxtime.h"
#include "zbx_rtc_constants.h"
#include "zbx_item_constants.h"
#include "event.h"

/******************************************************************************
 *                                                                            *
 * Purpose: write interface availability changes into database                *
 *                                                                            *
 * Parameters: data        - [IN/OUT] the serialized availability data        *
 *             data_alloc  - [IN/OUT] the serialized availability data size   *
 *             data_alloc  - [IN/OUT] the serialized availability data offset *
 *             ia          - [IN] the interface availability data             *
 *                                                                            *
 * Return value: SUCCEED - the availability changes were written into db      *
 *               FAIL    - no changes in availability data were detected      *
 *                                                                            *
 ******************************************************************************/
static int	update_interface_availability(unsigned char **data, size_t *data_alloc, size_t *data_offset,
		const zbx_interface_availability_t *ia)
{
	if (FAIL == zbx_interface_availability_is_set(ia))
		return FAIL;

	zbx_availability_serialize_interface(data, data_alloc, data_offset, ia);

	return SUCCEED;
}

/******************************************************************************
 *                                                                            *
 * Purpose: get interface availability data                                   *
 *                                                                            *
 * Parameters: dc_interface - [IN] the interface                              *
 *             ia           - [OUT] the interface availability data           *
 *                                                                            *
 ******************************************************************************/
static void	interface_get_availability(const zbx_dc_interface_t *dc_interface, zbx_interface_availability_t *ia)
{
	zbx_agent_availability_t	*availability = &ia->agent;

	availability->flags = ZBX_FLAGS_AGENT_STATUS;

	availability->available = dc_interface->available;
	availability->error = zbx_strdup(NULL, dc_interface->error);
	availability->errors_from = dc_interface->errors_from;
	availability->disable_until = dc_interface->disable_until;

	ia->interfaceid = dc_interface->interfaceid;
}

/********************************************************************************
 *                                                                              *
 * Purpose: sets interface availability data                                    *
 *                                                                              *
 * Parameters: dc_interface - [IN/OUT] the interface                            *
 *             ia           - [IN] the interface availability data              *
 *                                                                              *
 *******************************************************************************/
static void	interface_set_availability(zbx_dc_interface_t *dc_interface, const zbx_interface_availability_t *ia)
{
	const zbx_agent_availability_t	*availability = &ia->agent;
	unsigned char			*pavailable;
	int				*perrors_from, *pdisable_until;
	char				*perror;

	pavailable = &dc_interface->available;
	perror = dc_interface->error;
	perrors_from = &dc_interface->errors_from;
	pdisable_until = &dc_interface->disable_until;

	if (0 != (availability->flags & ZBX_FLAGS_AGENT_STATUS_AVAILABLE))
		*pavailable = availability->available;

	if (0 != (availability->flags & ZBX_FLAGS_AGENT_STATUS_ERROR))
		zbx_strlcpy(perror, availability->error, ZBX_INTERFACE_ERROR_LEN_MAX);

	if (0 != (availability->flags & ZBX_FLAGS_AGENT_STATUS_ERRORS_FROM))
		*perrors_from = availability->errors_from;

	if (0 != (availability->flags & ZBX_FLAGS_AGENT_STATUS_DISABLE_UNTIL))
		*pdisable_until = availability->disable_until;
}

static int	interface_availability_by_item_type(unsigned char item_type, unsigned char interface_type)
{
	if ((ITEM_TYPE_ZABBIX == item_type && INTERFACE_TYPE_AGENT == interface_type) ||
			(ITEM_TYPE_SNMP == item_type && INTERFACE_TYPE_SNMP == interface_type) ||
			(ITEM_TYPE_JMX == item_type && INTERFACE_TYPE_JMX == interface_type) ||
			(ITEM_TYPE_IPMI == item_type && INTERFACE_TYPE_IPMI == interface_type))
		return SUCCEED;

	return FAIL;
}

static const char	*item_type_agent_string(zbx_item_type_t item_type)
{
	switch (item_type)
	{
		case ITEM_TYPE_ZABBIX:
			return "Zabbix agent";
		case ITEM_TYPE_SNMP:
			return "SNMP agent";
		case ITEM_TYPE_IPMI:
			return "IPMI agent";
		case ITEM_TYPE_JMX:
			return "JMX agent";
		default:
			return "generic";
	}
}

/********************************************************************************
 *                                                                              *
 * Purpose: activate item interface                                             *
 *                                                                              *
 * Parameters: ts         - [IN] the timestamp                                  *
 *             item       - [IN/OUT] the item                                   *
 *             data       - [IN/OUT] the serialized availability data           *
 *             data_alloc - [IN/OUT] the serialized availability data size      *
 *             data_alloc - [IN/OUT] the serialized availability data offset    *
 *             ts         - [IN] the timestamp                                  *
 *                                                                              *
 *******************************************************************************/
void	zbx_activate_item_interface(zbx_timespec_t *ts, zbx_dc_item_t *item,  unsigned char **data, size_t *data_alloc,
		size_t *data_offset)
{
	zbx_interface_availability_t	in, out;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() interfaceid:" ZBX_FS_UI64 " itemid:" ZBX_FS_UI64 " type:%d",
			__func__, item->interface.interfaceid, item->itemid, (int)item->type);

	zbx_interface_availability_init(&in, item->interface.interfaceid);
	zbx_interface_availability_init(&out, item->interface.interfaceid);

	if (FAIL == interface_availability_by_item_type(item->type, item->interface.type))
		goto out;

	interface_get_availability(&item->interface, &in);

	if (FAIL == zbx_dc_interface_activate(item->interface.interfaceid, ts, &in.agent, &out.agent))
		goto out;

	if (FAIL == update_interface_availability(data, data_alloc, data_offset, &out))
		goto out;

	interface_set_availability(&item->interface, &out);

	if (ZBX_INTERFACE_AVAILABLE_TRUE == in.agent.available)
	{
		zabbix_log(LOG_LEVEL_WARNING, "resuming %s checks on host \"%s\": connection restored",
				item_type_agent_string(item->type), item->host.host);
	}
	else
	{
		zabbix_log(LOG_LEVEL_WARNING, "enabling %s checks on host \"%s\": interface became available",
				item_type_agent_string(item->type), item->host.host);
	}
out:
	zbx_interface_availability_clean(&out);
	zbx_interface_availability_clean(&in);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

/***********************************************************************************
 *                                                                                 *
 * Purpose: deactivate item interface                                              *
 *                                                                                 *
 * Parameters: ts                 - [IN] timestamp                                 *
 *             item               - [IN/OUT] item                                  *
 *             data               - [IN/OUT] serialized availability data          *
 *             data_alloc         - [IN/OUT] serialized availability data size     *
 *             data_alloc         - [IN/OUT] serialized availability data offset   *
 *             ts                 - [IN] timestamp                                 *
 *             unavailable_delay  - [IN]                                           *
 *             unreachable_period - [IN]                                           *
 *             unreachable_delay  - [IN]                                           *
 *             error              - [IN/OUT]                                       *
 *                                                                                 *
 ***********************************************************************************/
void	zbx_deactivate_item_interface(zbx_timespec_t *ts, zbx_dc_item_t *item, unsigned char **data, size_t *data_alloc,
		size_t *data_offset, int unavailable_delay, int unreachable_period, int unreachable_delay,
		const char *error)
{
	zbx_interface_availability_t	in, out;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() interfaceid:" ZBX_FS_UI64 " itemid:" ZBX_FS_UI64 " type:%d",
			__func__, item->interface.interfaceid, item->itemid, (int)item->type);

	zbx_interface_availability_init(&in, item->interface.interfaceid);
	zbx_interface_availability_init(&out,item->interface.interfaceid);

	if (FAIL == interface_availability_by_item_type(item->type, item->interface.type))
		goto out;

	interface_get_availability(&item->interface, &in);

	if (FAIL == zbx_dc_interface_deactivate(item->interface.interfaceid, ts, unavailable_delay, unreachable_period,
			unreachable_delay, &in.agent, &out.agent, error))
	{
		goto out;
	}

	if (FAIL == update_interface_availability(data, data_alloc, data_offset, &out))
		goto out;

	interface_set_availability(&item->interface, &out);

	if (0 == in.agent.errors_from)
	{
		zabbix_log(LOG_LEVEL_WARNING, "%s item \"%s\" on host \"%s\" failed:"
				" first network error, wait for %d seconds",
				item_type_agent_string(item->type), item->key_orig, item->host.host,
				out.agent.disable_until - ts->sec);
	}
	else if (ZBX_INTERFACE_AVAILABLE_FALSE != in.agent.available)
	{
		if (ZBX_INTERFACE_AVAILABLE_FALSE != out.agent.available)
		{
			zabbix_log(LOG_LEVEL_WARNING, "%s item \"%s\" on host \"%s\" failed:"
					" another network error, wait for %d seconds",
					item_type_agent_string(item->type), item->key_orig, item->host.host,
					out.agent.disable_until - ts->sec);
		}
		else
		{
			zabbix_log(LOG_LEVEL_WARNING, "temporarily disabling %s checks on host \"%s\":"
					" interface unavailable",
					item_type_agent_string(item->type), item->host.host);
		}
	}

	zabbix_log(LOG_LEVEL_DEBUG, "%s() errors_from:%d available:%d", __func__,
			out.agent.errors_from, out.agent.available);
out:
	zbx_interface_availability_clean(&out);
	zbx_interface_availability_clean(&in);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

void	zbx_free_agent_result_ptr(AGENT_RESULT *result)
{
	zbx_free_agent_result(result);
	zbx_free(result);
}

static int	get_value(zbx_dc_item_t *item, AGENT_RESULT *result, zbx_vector_ptr_t *add_results,
		const zbx_config_comms_args_t *config_comms, int config_startup_time)
{
	int	res = FAIL;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() key:'%s'", __func__, item->key_orig);

	switch (item->type)
	{
		case ITEM_TYPE_ZABBIX:
			res = get_value_agent(item, config_comms->config_timeout, config_comms->config_source_ip,
					result);
			break;
		case ITEM_TYPE_SIMPLE:
			/* simple checks use their own timeouts */
			res = get_value_simple(item, result, add_results);
			break;
		case ITEM_TYPE_INTERNAL:
			res = get_value_internal(item, result, config_comms, config_startup_time);
			break;
		case ITEM_TYPE_DB_MONITOR:
#ifdef HAVE_UNIXODBC
			res = get_value_db(item, config_comms->config_timeout, result);
#else
			SET_MSG_RESULT(result,
					zbx_strdup(NULL, "Support for Database monitor checks was not compiled in."));
			res = CONFIG_ERROR;
#endif
			break;
		case ITEM_TYPE_EXTERNAL:
			/* external checks use their own timeouts */
			res = get_value_external(item, config_comms->config_timeout, result);
			break;
		case ITEM_TYPE_SSH:
#if defined(HAVE_SSH2) || defined(HAVE_SSH)
			res = get_value_ssh(item, config_comms->config_timeout, config_comms->config_source_ip, result);
#else
			SET_MSG_RESULT(result, zbx_strdup(NULL, "Support for SSH checks was not compiled in."));
			res = CONFIG_ERROR;
#endif
			break;
		case ITEM_TYPE_TELNET:
			res = get_value_telnet(item, config_comms->config_timeout, config_comms->config_source_ip,
					result);
			break;
		case ITEM_TYPE_CALCULATED:
			res = get_value_calculated(item, result);
			break;
		case ITEM_TYPE_HTTPAGENT:
#ifdef HAVE_LIBCURL
			res = get_value_http(item, config_comms->config_source_ip, result);
#else
			SET_MSG_RESULT(result, zbx_strdup(NULL, "Support for HTTP agent checks was not compiled in."));
			res = CONFIG_ERROR;
#endif
			break;
		case ITEM_TYPE_SCRIPT:
			res = get_value_script(item, config_comms->config_source_ip, result);
			break;
		default:
			SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Not supported item type:%d", item->type));
			res = CONFIG_ERROR;
	}

	if (SUCCEED != res)
	{
		if (!ZBX_ISSET_MSG(result))
			SET_MSG_RESULT(result, zbx_strdup(NULL, ZBX_NOTSUPPORTED_MSG));

		zabbix_log(LOG_LEVEL_DEBUG, "Item [%s:%s] error: %s", item->host.host, item->key_orig, result->msg);
	}

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(res));

	return res;
}

static int	parse_query_fields(const zbx_dc_item_t *item, char **query_fields, unsigned char expand_macros)
{
	struct zbx_json_parse	jp_array, jp_object;
	char			name[MAX_STRING_LEN], value[MAX_STRING_LEN], *str = NULL;
	const char		*member, *element = NULL;
	size_t			alloc_len, offset;

	if ('\0' == **query_fields)
		return SUCCEED;

	if (SUCCEED != zbx_json_open(*query_fields, &jp_array))
	{
		zabbix_log(LOG_LEVEL_ERR, "cannot parse query fields: %s", zbx_json_strerror());
		return FAIL;
	}

	if (NULL == (element = zbx_json_next(&jp_array, element)))
	{
		zabbix_log(LOG_LEVEL_ERR, "cannot parse query fields: array is empty");
		return FAIL;
	}

	do
	{
		char	*data = NULL;

		if (SUCCEED != zbx_json_brackets_open(element, &jp_object) ||
				NULL == (member = zbx_json_pair_next(&jp_object, NULL, name, sizeof(name))) ||
				NULL == zbx_json_decodevalue(member, value, sizeof(value), NULL))
		{
			zabbix_log(LOG_LEVEL_ERR, "cannot parse query fields: %s", zbx_json_strerror());
			zbx_free(str);
			return FAIL;
		}

		if (NULL == str && NULL == strchr(item->url, '?'))
			zbx_chrcpy_alloc(&str, &alloc_len, &offset, '?');
		else
			zbx_chrcpy_alloc(&str, &alloc_len, &offset, '&');

		data = zbx_strdup(data, name);
		if (MACRO_EXPAND_YES == expand_macros)
		{
			zbx_substitute_simple_macros(NULL, NULL, NULL,NULL, NULL, &item->host, item, NULL, NULL, NULL, NULL,
					NULL, &data, MACRO_TYPE_HTTP_RAW, NULL, 0);
		}
		zbx_http_url_encode(data, &data);
		zbx_strcpy_alloc(&str, &alloc_len, &offset, data);
		zbx_chrcpy_alloc(&str, &alloc_len, &offset, '=');

		data = zbx_strdup(data, value);
		if (MACRO_EXPAND_YES == expand_macros)
		{
			zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL,NULL, NULL, &item->host, item, NULL, NULL,
					NULL, NULL, NULL, &data, MACRO_TYPE_HTTP_RAW, NULL, 0);
		}

		zbx_http_url_encode(data, &data);
		zbx_strcpy_alloc(&str, &alloc_len, &offset, data);

		free(data);
	}
	while (NULL != (element = zbx_json_next(&jp_array, element)));

	zbx_free(*query_fields);
	*query_fields = str;

	return SUCCEED;
}

void	zbx_prepare_items(zbx_dc_item_t *items, int *errcodes, int num, AGENT_RESULT *results,
		unsigned char expand_macros)
{
	int			i;
	char			*port = NULL, error[ZBX_ITEM_ERROR_LEN_MAX];
	zbx_dc_um_handle_t	*um_handle;

	if (MACRO_EXPAND_YES == expand_macros)
		um_handle = zbx_dc_open_user_macros();

	for (i = 0; i < num; i++)
	{
		zbx_init_agent_result(&results[i]);
		errcodes[i] = SUCCEED;

		if (MACRO_EXPAND_YES == expand_macros)
		{
			ZBX_STRDUP(items[i].key, items[i].key_orig);
			if (SUCCEED != zbx_substitute_key_macros_unmasked(&items[i].key, NULL, &items[i], NULL, NULL,
					MACRO_TYPE_ITEM_KEY, error, sizeof(error)))
			{
				SET_MSG_RESULT(&results[i], zbx_strdup(NULL, error));
				errcodes[i] = CONFIG_ERROR;
				continue;
			}
		}

		switch (items[i].type)
		{
			case ITEM_TYPE_ZABBIX:
			case ITEM_TYPE_SNMP:
			case ITEM_TYPE_JMX:
				ZBX_STRDUP(port, items[i].interface.port_orig);
				if (MACRO_EXPAND_YES == expand_macros)
				{
					zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
							NULL, NULL, NULL, NULL, NULL, NULL, &port, MACRO_TYPE_COMMON,
							NULL, 0);
				}

				if (FAIL == zbx_is_ushort(port, &items[i].interface.port))
				{
					SET_MSG_RESULT(&results[i], zbx_dsprintf(NULL, "Invalid port number [%s]",
								items[i].interface.port_orig));
					errcodes[i] = CONFIG_ERROR;
					continue;
				}
				break;
		}

		switch (items[i].type)
		{
			case ITEM_TYPE_SNMP:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				if (ZBX_IF_SNMP_VERSION_3 == items[i].snmp_version)
				{
					ZBX_STRDUP(items[i].snmpv3_securityname, items[i].snmpv3_securityname_orig);
					ZBX_STRDUP(items[i].snmpv3_authpassphrase, items[i].snmpv3_authpassphrase_orig);
					ZBX_STRDUP(items[i].snmpv3_privpassphrase, items[i].snmpv3_privpassphrase_orig);
					ZBX_STRDUP(items[i].snmpv3_contextname, items[i].snmpv3_contextname_orig);

					zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid,
							NULL, NULL, NULL, NULL, NULL, NULL, NULL,
							&items[i].snmpv3_securityname, MACRO_TYPE_COMMON, NULL,
							0);
					zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid,
							NULL, NULL, NULL, NULL, NULL, NULL, NULL,
							&items[i].snmpv3_authpassphrase, MACRO_TYPE_COMMON,
							NULL, 0);
					zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid,
							NULL, NULL, NULL, NULL, NULL, NULL, NULL,
							&items[i].snmpv3_privpassphrase, MACRO_TYPE_COMMON,
							NULL, 0);
					zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid,
							NULL, NULL, NULL, NULL, NULL, NULL, NULL,
							&items[i].snmpv3_contextname, MACRO_TYPE_COMMON, NULL,
							0);
				}

				ZBX_STRDUP(items[i].snmp_community, items[i].snmp_community_orig);
				ZBX_STRDUP(items[i].snmp_oid, items[i].snmp_oid_orig);

				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].snmp_community,
						MACRO_TYPE_COMMON, NULL, 0);
				if (SUCCEED != zbx_substitute_key_macros(&items[i].snmp_oid, &items[i].host.hostid,
						NULL, NULL, NULL, MACRO_TYPE_SNMP_OID, error, sizeof(error)))
				{
					SET_MSG_RESULT(&results[i], zbx_strdup(NULL, error));
					errcodes[i] = CONFIG_ERROR;
					continue;
				}
				break;
			case ITEM_TYPE_SCRIPT:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				ZBX_STRDUP(items[i].timeout, items[i].timeout_orig);

				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL, NULL,
						NULL, NULL, NULL, NULL, NULL, &items[i].timeout, MACRO_TYPE_COMMON,
						NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, NULL, NULL, &items[i], NULL,
						NULL, NULL, NULL, NULL, &items[i].script_params,
						MACRO_TYPE_SCRIPT_PARAMS_FIELD, NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].params, MACRO_TYPE_COMMON,
						NULL, 0);
				break;
			case ITEM_TYPE_SSH:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				ZBX_STRDUP(items[i].publickey, items[i].publickey_orig);
				ZBX_STRDUP(items[i].privatekey, items[i].privatekey_orig);

				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL, NULL,
						NULL, NULL, NULL, NULL, NULL, &items[i].publickey, MACRO_TYPE_COMMON,
						NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL, NULL,
						NULL, NULL, NULL, NULL, NULL, &items[i].privatekey, MACRO_TYPE_COMMON, NULL, 0);
				ZBX_FALLTHROUGH;
			case ITEM_TYPE_TELNET:
			case ITEM_TYPE_DB_MONITOR:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, NULL, NULL, &items[i], NULL,
						NULL, NULL, NULL, NULL, &items[i].params, MACRO_TYPE_PARAMS_FIELD,
						NULL, 0);
				ZBX_FALLTHROUGH;
			case ITEM_TYPE_SIMPLE:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				items[i].username = zbx_strdup(items[i].username, items[i].username_orig);
				items[i].password = zbx_strdup(items[i].password, items[i].password_orig);

				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].username,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].password,
						MACRO_TYPE_COMMON, NULL, 0);
				break;
			case ITEM_TYPE_JMX:
				if (MACRO_EXPAND_NO == expand_macros)
					break;

				items[i].username = zbx_strdup(items[i].username, items[i].username_orig);
				items[i].password = zbx_strdup(items[i].password, items[i].password_orig);
				items[i].jmx_endpoint = zbx_strdup(items[i].jmx_endpoint, items[i].jmx_endpoint_orig);

				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].username,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].password,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, NULL, NULL, &items[i],
						NULL, NULL, NULL, NULL, NULL, &items[i].jmx_endpoint, MACRO_TYPE_JMX_ENDPOINT,
						NULL, 0);
				break;
			case ITEM_TYPE_HTTPAGENT:
				if (MACRO_EXPAND_YES == expand_macros)
				{
					ZBX_STRDUP(items[i].timeout, items[i].timeout_orig);
					ZBX_STRDUP(items[i].url, items[i].url_orig);
					ZBX_STRDUP(items[i].status_codes, items[i].status_codes_orig);
					ZBX_STRDUP(items[i].http_proxy, items[i].http_proxy_orig);
					ZBX_STRDUP(items[i].ssl_cert_file, items[i].ssl_cert_file_orig);
					ZBX_STRDUP(items[i].ssl_key_file, items[i].ssl_key_file_orig);
					ZBX_STRDUP(items[i].ssl_key_password, items[i].ssl_key_password_orig);
					ZBX_STRDUP(items[i].username, items[i].username_orig);
					ZBX_STRDUP(items[i].password, items[i].password_orig);
					ZBX_STRDUP(items[i].query_fields, items[i].query_fields_orig);

					zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
							NULL, NULL, NULL, NULL, NULL, NULL, &items[i].timeout,
							MACRO_TYPE_COMMON, NULL, 0);
					zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, NULL, &items[i].host,
							&items[i], NULL, NULL, NULL, NULL, NULL, &items[i].url,
							MACRO_TYPE_HTTP_RAW, NULL, 0);
				}

				if (SUCCEED != zbx_http_punycode_encode_url(&items[i].url))
				{
					SET_MSG_RESULT(&results[i], zbx_strdup(NULL, "Cannot encode URL into punycode"));
					errcodes[i] = CONFIG_ERROR;
					continue;
				}

				if (FAIL == parse_query_fields(&items[i], &items[i].query_fields, expand_macros))
				{
					SET_MSG_RESULT(&results[i], zbx_strdup(NULL, "Invalid query fields"));
					errcodes[i] = CONFIG_ERROR;
					continue;
				}

				if (MACRO_EXPAND_NO == expand_macros)
					break;

				switch (items[i].post_type)
				{
					case ZBX_POSTTYPE_XML:
						if (SUCCEED != zbx_substitute_macros_xml_unmasked(&items[i].posts, &items[i],
								NULL, NULL, error, sizeof(error)))
						{
							SET_MSG_RESULT(&results[i], zbx_dsprintf(NULL, "%s.", error));
							errcodes[i] = CONFIG_ERROR;
							continue;
						}
						break;
					case ZBX_POSTTYPE_JSON:
						zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL,NULL, NULL,
								&items[i].host, &items[i], NULL, NULL, NULL, NULL, NULL,
								&items[i].posts, MACRO_TYPE_HTTP_JSON, NULL, 0);
						break;
					default:
						zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL,NULL, NULL,
								&items[i].host, &items[i], NULL, NULL, NULL, NULL, NULL,
								&items[i].posts, MACRO_TYPE_HTTP_RAW, NULL, 0);
						break;
				}

				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL,NULL, NULL, &items[i].host,
						&items[i], NULL, NULL, NULL, NULL, NULL, &items[i].headers,
						MACRO_TYPE_HTTP_RAW, NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].status_codes,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].http_proxy,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL,NULL, NULL, &items[i].host, &items[i], NULL,
						NULL, NULL, NULL, NULL, &items[i].ssl_cert_file, MACRO_TYPE_HTTP_RAW,
						NULL, 0);
				zbx_substitute_simple_macros(NULL, NULL, NULL,NULL, NULL, &items[i].host, &items[i], NULL,
						NULL, NULL, NULL, NULL, &items[i].ssl_key_file, MACRO_TYPE_HTTP_RAW,
						NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].ssl_key_password,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].username,
						MACRO_TYPE_COMMON, NULL, 0);
				zbx_substitute_simple_macros_unmasked(NULL, NULL, NULL, NULL, &items[i].host.hostid, NULL,
						NULL, NULL, NULL, NULL, NULL, NULL, &items[i].password,
						MACRO_TYPE_COMMON, NULL, 0);
				break;
		}
	}

	zbx_free(port);

	if (MACRO_EXPAND_YES == expand_macros)
		zbx_dc_close_user_macros(um_handle);

}

void	zbx_check_items(zbx_dc_item_t *items, int *errcodes, int num, AGENT_RESULT *results,
		zbx_vector_ptr_t *add_results, unsigned char poller_type, const zbx_config_comms_args_t *config_comms,
		int config_startup_time)
{
	if (ITEM_TYPE_SNMP == items[0].type)
	{
#ifndef HAVE_NETSNMP
		int	i;

		ZBX_UNUSED(poller_type);

		for (i = 0; i < num; i++)
		{
			if (SUCCEED != errcodes[i])
				continue;

			SET_MSG_RESULT(&results[i], zbx_strdup(NULL, "Support for SNMP checks was not compiled in."));
			errcodes[i] = CONFIG_ERROR;
		}
#else
		/* SNMP checks use their own timeouts */
		get_values_snmp(items, results, errcodes, num, poller_type, config_comms->config_timeout,
				config_comms->config_source_ip);
#endif
	}
	else if (ITEM_TYPE_JMX == items[0].type)
	{
		get_values_java(ZBX_JAVA_GATEWAY_REQUEST_JMX, items, results, errcodes, num,
				config_comms->config_timeout, config_comms->config_source_ip);
	}
	else if (1 == num)
	{
		if (SUCCEED == errcodes[0])
			errcodes[0] = get_value(&items[0], &results[0], add_results, config_comms,
				config_startup_time);
	}
	else
		THIS_SHOULD_NEVER_HAPPEN;
}

void	zbx_clean_items(zbx_dc_item_t *items, int num, AGENT_RESULT *results)
{
	int	i;

	for (i = 0; i < num; i++)
	{
		zbx_free(items[i].key);

		switch (items[i].type)
		{
			case ITEM_TYPE_SNMP:
				if (ZBX_IF_SNMP_VERSION_3 == items[i].snmp_version)
				{
					zbx_free(items[i].snmpv3_securityname);
					zbx_free(items[i].snmpv3_authpassphrase);
					zbx_free(items[i].snmpv3_privpassphrase);
					zbx_free(items[i].snmpv3_contextname);
				}

				zbx_free(items[i].snmp_community);
				zbx_free(items[i].snmp_oid);
				break;
			case ITEM_TYPE_HTTPAGENT:
				zbx_free(items[i].timeout);
				zbx_free(items[i].url);
				zbx_free(items[i].query_fields);
				zbx_free(items[i].status_codes);
				zbx_free(items[i].http_proxy);
				zbx_free(items[i].ssl_cert_file);
				zbx_free(items[i].ssl_key_file);
				zbx_free(items[i].ssl_key_password);
				zbx_free(items[i].username);
				zbx_free(items[i].password);
				break;
			case ITEM_TYPE_SCRIPT:
				zbx_free(items[i].timeout);
				break;
			case ITEM_TYPE_SSH:
				zbx_free(items[i].publickey);
				zbx_free(items[i].privatekey);
				ZBX_FALLTHROUGH;
			case ITEM_TYPE_TELNET:
			case ITEM_TYPE_DB_MONITOR:
			case ITEM_TYPE_SIMPLE:
				zbx_free(items[i].username);
				zbx_free(items[i].password);
				break;
			case ITEM_TYPE_JMX:
				zbx_free(items[i].username);
				zbx_free(items[i].password);
				zbx_free(items[i].jmx_endpoint);
				break;
		}

		zbx_free_agent_result(&results[i]);
	}
}

/***********************************************************************************
 *                                                                                 *
 * Purpose: retrieve values of metrics from monitored hosts                        *
 *                                                                                 *
 * Parameters: poller_type                - [IN] poller type (ZBX_POLLER_TYPE_...) *
 *             nextcheck                  - [OUT] item nextcheck                   *
 *             config_comms               - [IN] server/proxy configuration for    *
 *                                               communication                     *
 *             config_startup_time        - [IN] program startup time              *
 *             config_unavailable_delay   - [IN]                                   *
 *             config_unreachable_period  - [IN]                                   *
 *             config_unreachable_delay   - [IN]                                   *
 *                                                                                 *
 * Return value: number of items processed                                         *
 *                                                                                 *
 * Comments: processes single item at a time except for Java, SNMP items,          *
 *           see zbx_dc_config_get_poller_items()                                  *
 *                                                                                 *
 **********************************************************************************/
static int	get_values(unsigned char poller_type, int *nextcheck, const zbx_config_comms_args_t *config_comms,
		int config_startup_time, int config_unavailable_delay, int config_unreachable_period,
		int config_unreachable_delay)
{
	zbx_dc_item_t		item, *items;
	AGENT_RESULT		results[ZBX_MAX_POLLER_ITEMS];
	int			errcodes[ZBX_MAX_POLLER_ITEMS];
	zbx_timespec_t		timespec;
	int			i, num, last_available = ZBX_INTERFACE_AVAILABLE_UNKNOWN;
	zbx_vector_ptr_t	add_results;
	unsigned char		*data = NULL;
	size_t			data_alloc = 0, data_offset = 0;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	items = &item;
	num = zbx_dc_config_get_poller_items(poller_type, config_comms->config_timeout, &items);

	if (0 == num)
	{
		*nextcheck = zbx_dc_config_get_poller_nextcheck(poller_type);
		goto exit;
	}

	zbx_vector_ptr_create(&add_results);

	zbx_prepare_items(items, errcodes, num, results, MACRO_EXPAND_YES);
	zbx_check_items(items, errcodes, num, results, &add_results, poller_type, config_comms, config_startup_time);

	zbx_timespec(&timespec);

	/* process item values */
	for (i = 0; i < num; i++)
	{
		switch (errcodes[i])
		{
			case SUCCEED:
			case NOTSUPPORTED:
			case AGENT_ERROR:
				if (ZBX_INTERFACE_AVAILABLE_TRUE != last_available)
				{
					zbx_activate_item_interface(&timespec, &items[i], &data, &data_alloc,
							&data_offset);
					last_available = ZBX_INTERFACE_AVAILABLE_TRUE;
				}
				break;
			case NETWORK_ERROR:
			case GATEWAY_ERROR:
			case TIMEOUT_ERROR:
				if (ZBX_INTERFACE_AVAILABLE_FALSE != last_available)
				{
					zbx_deactivate_item_interface(&timespec, &items[i], &data, &data_alloc,
							&data_offset, config_unavailable_delay,
							config_unreachable_period, config_unreachable_delay,
							results[i].msg);
					last_available = ZBX_INTERFACE_AVAILABLE_FALSE;
				}
				break;
			case CONFIG_ERROR:
				/* nothing to do */
				break;
			case SIG_ERROR:
				/* nothing to do, execution was forcibly interrupted by signal */
				break;
			default:
				zbx_error("unknown response code returned: %d", errcodes[i]);
				THIS_SHOULD_NEVER_HAPPEN;
		}

		if (SUCCEED == errcodes[i])
		{
			if (0 == add_results.values_num)
			{
				items[i].state = ITEM_STATE_NORMAL;
				zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid, items[i].value_type,
						items[i].flags, &results[i], &timespec, items[i].state, NULL);
			}
			else
			{
				/* vmware.eventlog item returns vector of AGENT_RESULT representing events */

				int		j;
				zbx_timespec_t	ts_tmp = timespec;

				for (j = 0; j < add_results.values_num; j++)
				{
					AGENT_RESULT	*add_result = (AGENT_RESULT *)add_results.values[j];

					if (ZBX_ISSET_MSG(add_result))
					{
						items[i].state = ITEM_STATE_NOTSUPPORTED;
						zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid,
						items[i].value_type, items[i].flags, NULL, &ts_tmp, items[i].state,
								add_result->msg);
					}
					else
					{
						items[i].state = ITEM_STATE_NORMAL;
						zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid,
								items[i].value_type, items[i].flags, add_result,
								&ts_tmp, items[i].state, NULL);
					}

					/* ensure that every log item value timestamp is unique */
					if (++ts_tmp.ns == 1000000000)
					{
						ts_tmp.sec++;
						ts_tmp.ns = 0;
					}
				}
			}
		}
		else if (NOTSUPPORTED == errcodes[i] || AGENT_ERROR == errcodes[i] || CONFIG_ERROR == errcodes[i])
		{
			items[i].state = ITEM_STATE_NOTSUPPORTED;
			zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid, items[i].value_type,
					items[i].flags, NULL, &timespec, items[i].state, results[i].msg);
		}

		zbx_dc_poller_requeue_items(&items[i].itemid, &timespec.sec, &errcodes[i], 1, poller_type,
				nextcheck);
	}

	zbx_preprocessor_flush();
	zbx_clean_items(items, num, results);
	zbx_dc_config_clean_items(items, NULL, num);
	zbx_vector_ptr_clear_ext(&add_results, (zbx_mem_free_func_t)zbx_free_agent_result_ptr);
	zbx_vector_ptr_destroy(&add_results);

	if (NULL != data)
	{
		zbx_availability_send(ZBX_IPC_AVAILABILITY_REQUEST, data, (zbx_uint32_t)data_offset, NULL);
		zbx_free(data);
	}

	if (items != &item)
		zbx_free(items);
exit:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%d", __func__, num);

	return num;
}

static int	get_context_http(zbx_dc_item_t *item, const char *config_source_ip, AGENT_RESULT *result,
		CURLM *curl_handle)
{
	char			*error = NULL;
	int			ret = NOTSUPPORTED;
	zbx_http_context_t	*context = zbx_malloc(NULL, sizeof(zbx_http_context_t));

	zbx_http_context_create(context);

	context->item_context.itemid = item->itemid;
	context->item_context.hostid = item->host.hostid;
	context->item_context.value_type = item->value_type;
	context->item_context.flags = item->flags;
	context->item_context.state = item->state;
	context->posts = item->posts;
	item->posts = NULL;

	if (SUCCEED != (ret = zbx_http_request_prepare(context, item->request_method, item->url,
			item->query_fields, item->headers, context->posts, item->retrieve_mode, item->http_proxy,
			item->follow_redirects, item->timeout, 1, item->ssl_cert_file, item->ssl_key_file,
			item->ssl_key_password, item->verify_peer, item->verify_host, item->authtype, item->username,
			item->password, NULL, item->post_type, item->output_format, config_source_ip, &error)))
	{
		SET_MSG_RESULT(result, error);
		error = NULL;
		zbx_http_context_destory(context);
		zbx_free(context);

		return ret;
	}

	curl_easy_setopt(context->easyhandle, CURLOPT_PRIVATE, context);
	curl_multi_add_handle(curl_handle, context->easyhandle);

	return ret;
}


typedef struct
{
	const			zbx_config_comms_args_t *config_comms;
	unsigned char		poller_type;
	CURLM			*curl_handle;
	struct event_base	*base;
	int			num;
	struct event		*add_items_timer;
}
zbx_poller_config_t;

static void	add_items(evutil_socket_t fd, short events, void *arg)
{
	zbx_dc_item_t		item, *items;
	AGENT_RESULT		results[ZBX_MAX_POLLER_ITEMS];
	int			errcodes[ZBX_MAX_POLLER_ITEMS];
	zbx_timespec_t		timespec;
	int			i, num;
	zbx_poller_config_t	*poller_config = (zbx_poller_config_t *)arg;
	int			nextcheck;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	items = &item;
	num = zbx_dc_config_get_poller_items(poller_config->poller_type, poller_config->config_comms->config_timeout, &items);

	if (0 == num)
	{
		nextcheck = zbx_dc_config_get_poller_nextcheck(poller_config->poller_type);
		goto exit;
	}

	zbx_prepare_items(items, errcodes, num, results, MACRO_EXPAND_YES);

	for (i = 0; i < num; i++)
	{
		if (SUCCEED != (errcodes[i] = get_context_http(&items[i],
				poller_config->config_comms->config_source_ip, &results[i],
				poller_config->curl_handle)))
		{
			continue;
		}
	}

	zbx_timespec(&timespec);

	/* process item values */
	for (i = 0; i < num; i++)
	{
		if (SUCCEED == errcodes[i])
		{
			continue;
		}
		else if (NOTSUPPORTED == errcodes[i] || AGENT_ERROR == errcodes[i] || CONFIG_ERROR == errcodes[i])
		{
			items[i].state = ITEM_STATE_NOTSUPPORTED;
			zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid, items[i].value_type,
					items[i].flags, NULL, &timespec, items[i].state, results[i].msg);

			zbx_dc_poller_requeue_items(&items[i].itemid, &timespec.sec, &errcodes[i], 1,
					poller_config->poller_type, &nextcheck);
		}
	}

	zbx_preprocessor_flush();
	zbx_clean_items(items, num, results);
	zbx_dc_config_clean_items(items, NULL, num);

	if (items != &item)
		zbx_free(items);
exit:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%d", __func__, num);

	poller_config->num += num;
}

struct event_base	*base;
struct event		*curl_timeout;
CURLM			*curl_handle;

typedef struct
{
	struct event *event;
	curl_socket_t sockfd;
}
curl_context_t;

static void	check_multi_info(void)
{
	CURLMsg			*message;
	int			pending;
	CURL			*easy_handle;
	zbx_http_context_t	*context;
	zbx_timespec_t		timespec;
	long			response_code;
	int			ret;
	char			*error, *out = NULL;
	AGENT_RESULT		result;

	zbx_timespec(&timespec);

	while (NULL != (message = curl_multi_info_read(curl_handle, &pending)))
	{
		switch(message->msg)
		{
			case CURLMSG_DONE:
				easy_handle = message->easy_handle;

				curl_easy_getinfo(easy_handle, CURLINFO_PRIVATE, &context);
				printf("DONE\n");

				zbx_init_agent_result(&result);
				if (SUCCEED == (ret = zbx_http_handle_response(context->easyhandle, context, message->data.result, &response_code, &out, &error)))
				{
					/*if ('\0' != *item->status_codes && FAIL == zbx_int_in_list(context->status_codes, (int)response_code))
					{
						if (NULL != out)
						{
							SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Response code \"%ld\" did not match any of the"
									" required status codes \"%s\"\n%s", response_code, item->status_codes, out));
						}
						else
						{
							SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Response code \"%ld\" did not match any of the"
									" required status codes \"%s\"", response_code, item->status_codes));
						}
					}
					else*/
					{
						SET_TEXT_RESULT(&result, out);
						out = NULL;
					}
				}
				else
				{
					SET_MSG_RESULT(&result, error);
					error = NULL;
					ret = NOTSUPPORTED;
				}

				if (SUCCEED == ret)
				{
					zbx_preprocess_item_value(context->item_context.itemid, context->item_context.hostid,context->item_context.value_type,
							context->item_context.flags, &result, &timespec, ITEM_STATE_NORMAL, NULL);
				}
				else
				{
					zbx_preprocess_item_value(context->item_context.itemid, context->item_context.hostid,context->item_context.value_type,
							context->item_context.flags, NULL, &timespec, ITEM_STATE_NOTSUPPORTED, result.msg);
				}

				int	errcode = SUCCEED;
				int	nextcheck;
				zbx_dc_poller_requeue_items(&context->item_context.itemid, &timespec.sec, &errcode, 1,
						ZBX_POLLER_TYPE_NORMAL, &nextcheck);
				zbx_free_agent_result(&result);

				curl_multi_remove_handle(curl_handle, easy_handle);
				zbx_http_context_destory(context);
				zbx_free(context);
				break;
			default:
				fprintf(stderr, "CURLMSG default\n");
				break;
		}
	}
}

static void on_timeout(evutil_socket_t fd, short events, void *arg)
{
	int running_handles;

	printf("on_timeout\n");
	curl_multi_socket_action(curl_handle, CURL_SOCKET_TIMEOUT, 0, &running_handles);
	check_multi_info();
}

static int	start_timeout(CURLM *multi, long timeout_ms, void *userp)
{
	if(timeout_ms < 0)
	{
		evtimer_del(curl_timeout);
	}
	else
	{
		struct timeval tv;

		if(timeout_ms == 0)
			timeout_ms = 1;	/* 0 means directly call socket_action, but we will do it in a bit */

		tv.tv_sec = timeout_ms / 1000;
		tv.tv_usec = (timeout_ms % 1000) * 1000;
		evtimer_del(curl_timeout);
		evtimer_add(curl_timeout, &tv);
	}

	return 0;
}

static void	curl_perform(int fd, short event, void *arg);

static curl_context_t	*create_curl_context(curl_socket_t sockfd)
{
	curl_context_t *context;

	context = (curl_context_t *) malloc(sizeof(*context));

	context->sockfd = sockfd;

	context->event = event_new(base, sockfd, 0, curl_perform, context);

	return context;
}

static void	destroy_curl_context(curl_context_t *context)
{
	event_del(context->event);
	event_free(context->event);
	free(context);
}

static void	curl_perform(int fd, short event, void *arg)
{
	int		running_handles;
	int		flags = 0;
	curl_context_t	*context;

	if(event & EV_READ)
		flags |= CURL_CSELECT_IN;
	if(event & EV_WRITE)
		flags |= CURL_CSELECT_OUT;

	context = (curl_context_t *) arg;
	curl_multi_socket_action(curl_handle, context->sockfd, flags, &running_handles);

	check_multi_info();
}


static int handle_socket(CURL *easy, curl_socket_t s, int action, void *userp, void *socketp)
{
	curl_context_t *curl_context;
	int		events = 0;

	switch(action)
	{
		case CURL_POLL_IN:
		case CURL_POLL_OUT:
		case CURL_POLL_INOUT:
			curl_context = socketp ? (curl_context_t *) socketp : create_curl_context(s);

			curl_multi_assign(curl_handle, s, (void *) curl_context);

			if(action != CURL_POLL_IN)
				events |= EV_WRITE;
			if(action != CURL_POLL_OUT)
				events |= EV_READ;

			events |= EV_PERSIST;

			event_del(curl_context->event);
			event_assign(curl_context->event, base, curl_context->sockfd, events, curl_perform,
					curl_context);
			event_add(curl_context->event, NULL);

		break;
	case CURL_POLL_REMOVE:
		if(socketp)
		{
			event_del(((curl_context_t*) socketp)->event);
			destroy_curl_context((curl_context_t*) socketp);
			curl_multi_assign(curl_handle, s, NULL);
		}
		break;
	default:
		break;

	}

	return 0;
}

static void zbx_on_timeout(evutil_socket_t fd, short events, void *arg)
{

}

ZBX_THREAD_ENTRY(poller_thread, args)
{
	zbx_thread_poller_args	*poller_args_in = (zbx_thread_poller_args *)(((zbx_thread_args_t *)args)->args);

	int			nextcheck = 0, sleeptime = -1, processed = 0, old_processed = 0;
	double			sec, total_sec = 0.0, old_total_sec = 0.0;
	time_t			last_stat_time;
	unsigned char		poller_type;
	zbx_ipc_async_socket_t	rtc;
	const zbx_thread_info_t	*info = &((zbx_thread_args_t *)args)->info;
	int			server_num = ((zbx_thread_args_t *)args)->info.server_num;
	int			process_num = ((zbx_thread_args_t *)args)->info.process_num;
	unsigned char		process_type = ((zbx_thread_args_t *)args)->info.process_type;
	zbx_uint32_t		rtc_msgs[] = {ZBX_RTC_SNMP_CACHE_RELOAD};
	struct event		*add_items_timer;
	struct timeval		tv = {1, 0};


#define	STAT_INTERVAL	5	/* if a process is busy and does not sleep then update status not faster than */
				/* once in STAT_INTERVAL seconds */

	poller_type = (poller_args_in->poller_type);

	zabbix_log(LOG_LEVEL_INFORMATION, "%s #%d started [%s #%d]", get_program_type_string(info->program_type),
			server_num, get_process_type_string(process_type), process_num);

	zbx_update_selfmon_counter(info, ZBX_PROCESS_STATE_BUSY);

	scriptitem_es_engine_init();

#if defined(HAVE_GNUTLS) || defined(HAVE_OPENSSL)
	zbx_tls_init_child(poller_args_in->config_comms->config_tls,
			poller_args_in->zbx_get_program_type_cb_arg);
#endif
	if (ZBX_POLLER_TYPE_HISTORY == poller_type)
	{
		zbx_setproctitle("%s #%d [connecting to the database]", get_process_type_string(process_type),
				process_num);

		zbx_db_connect(ZBX_DB_CONNECT_NORMAL);
	}
	zbx_setproctitle("%s #%d started", get_process_type_string(process_type), process_num);
	last_stat_time = time(NULL);

	zbx_rtc_subscribe(process_type, process_num, rtc_msgs, ARRSIZE(rtc_msgs),
			poller_args_in->config_comms->config_timeout, &rtc);

	if(curl_global_init(CURL_GLOBAL_ALL))
	{
		fprintf(stderr, "Could not init curl\n");
		return 1;
	}

	curl_handle = curl_multi_init();

	base = event_base_new();
	curl_timeout = evtimer_new(base, on_timeout, NULL);

	curl_multi_setopt(curl_handle, CURLMOPT_SOCKETFUNCTION, handle_socket);
	curl_multi_setopt(curl_handle, CURLMOPT_TIMERFUNCTION, start_timeout);
	zbx_poller_config_t	poller_config;

	poller_config.config_comms = poller_args_in->config_comms;
	poller_config.poller_type = poller_type;
	poller_config.curl_handle = curl_handle;
	poller_config.base = base;
	add_items_timer = evtimer_new(base, add_items, &poller_config);
	poller_config.add_items_timer = add_items_timer;

	while (ZBX_IS_RUNNING())
	{
		zbx_uint32_t	rtc_cmd;
		unsigned char	*rtc_data;

		sec = zbx_time();
		zbx_update_env(get_process_type_string(process_type), sec);

		if (0 != sleeptime)
		{
			zbx_setproctitle("%s #%d [got %d values in " ZBX_FS_DBL " sec, getting values]",
					get_process_type_string(process_type), process_num, old_processed,
					old_total_sec);
		}

		if (ZBX_POLLER_TYPE_NORMAL == poller_type)
		{
			if (0 == evtimer_pending(add_items_timer, NULL))
				evtimer_add(add_items_timer, &tv);

			event_base_loop(base, EVLOOP_ONCE);

			sleeptime = 0;
		}
		else
		{
			processed += get_values(poller_type, &nextcheck, poller_args_in->config_comms,
				poller_args_in->config_startup_time, poller_args_in->config_unavailable_delay,
				poller_args_in->config_unreachable_period, poller_args_in->config_unreachable_delay);

			sleeptime = zbx_calculate_sleeptime(nextcheck, POLLER_DELAY);
		}

		total_sec += zbx_time() - sec;

		if (0 != sleeptime || STAT_INTERVAL <= time(NULL) - last_stat_time)
		{
			if (0 == sleeptime)
			{
				zbx_setproctitle("%s #%d [got %d values in " ZBX_FS_DBL " sec, getting values]",
					get_process_type_string(process_type), process_num, processed, total_sec);
			}
			else
			{
				zbx_setproctitle("%s #%d [got %d values in " ZBX_FS_DBL " sec, idle %d sec]",
					get_process_type_string(process_type), process_num, processed, total_sec,
					sleeptime);
				old_processed = processed;
				old_total_sec = total_sec;
			}
			processed = 0;
			total_sec = 0.0;
			last_stat_time = time(NULL);
		}

		if (SUCCEED == zbx_rtc_wait(&rtc, info, &rtc_cmd, &rtc_data, sleeptime) && 0 != rtc_cmd)
		{
#ifdef HAVE_NETSNMP
			if (ZBX_RTC_SNMP_CACHE_RELOAD == rtc_cmd)
			{
				if (ZBX_POLLER_TYPE_NORMAL == poller_type || ZBX_POLLER_TYPE_UNREACHABLE == poller_type)
					zbx_clear_cache_snmp(process_type, process_num);
			}
#endif
			if (ZBX_RTC_SHUTDOWN == rtc_cmd)
				break;
		}
	}


	curl_multi_cleanup(curl_handle);
	event_free(curl_timeout);
	event_base_free(base);

	libevent_global_shutdown();
	curl_global_cleanup();
	scriptitem_es_engine_destroy();

	zbx_setproctitle("%s #%d [terminated]", get_process_type_string(process_type), process_num);

	while (1)
		zbx_sleep(SEC_PER_MIN);
#undef STAT_INTERVAL
}
