<?php
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


/**
 * @var CView $this
 */
?>

<script type="text/x-jquery-tmpl" id="filter-tag-row-tmpl">
	<?= CTagFilterFieldHelper::getTemplate() ?>
</script>

<script>
	const view = new class {

		init({form_name, confirm_messages, token, hostids, context}) {
			this.confirm_messages = confirm_messages;
			this.token = token;
			this.context = context;
			this.hostids = hostids;

			this.form = document.forms[form_name];
			this.subfilter_form = document.querySelector('form[name="zbx_filter"]');

			this.initForm();
			this.initEvents();
		}

		initForm() {
			$('#filter-tags')
				.dynamicRows({template: '#filter-tag-row-tmpl'})
				.on('afteradd.dynamicRows', function() {
					const rows = this.querySelectorAll('.form_row');

					new CTagFilterItem(rows[rows.length - 1]);
				});

			document.querySelectorAll('#filter-tags .form_row').forEach(row => {
				new CTagFilterItem(row);
			});
		}

		initEvents() {
			this.subfilter_form.addEventListener('click', e => {
				const target = e.target;

				if (target.matches('.link-action') && target.closest('.subfilter') !== null) {
					const subfilter = target.closest('.subfilter');

					if (subfilter.matches('.subfilter-enabled')) {
						subfilter.querySelector('input[type="hidden"]').remove();
						this.subfilter_form.submit();
					}
					else {
						const name = target.getAttribute('data-name');
						const value = target.getAttribute('data-value');

						subfilter.classList.add('subfilter-enabled');
						submitFormWithParam('zbx_filter', name, value);
					}
				}
				else if (target.matches('[name="filter_state"]')) {
					const disabled = e.target.getAttribute('value') != -1;

					this.subfilter_form.querySelectorAll('input[name=filter_status]').forEach(checkbox => {
						checkbox.toggleAttribute('disabled', disabled);
					});
				}
			});
			this.form.addEventListener('click', (e) => {
				const target = e.target;

				if (target.classList.contains('js-update-item')) {
					this.#edit(target, {itemid: target.dataset.itemid});
				}
				else if (target.classList.contains('js-enable-item')) {
					this.#enable(null, [target.dataset.itemid]);
				}
				else if (target.classList.contains('js-disable-item')) {
					this.#disable(null, [target.dataset.itemid]);
				}
				else if (target.classList.contains('js-massenable-item')) {
					this.#enable(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-massdisable-item')) {
					this.#disable(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-massexecute-item')) {
					this._executeItems(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-massclearhistory-item')) {
					this._clearItems(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-masscopy-item')) {
					this._copyItems(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-massupdate-item')) {
					this._updateItems(target, Object.keys(chkbxRange.getSelectedIds()));
				}
				else if (target.classList.contains('js-massdelete-item')) {
					this._deleteItems(target, Object.keys(chkbxRange.getSelectedIds()));
				}
			});
			document.addEventListener('click', (e) => {
				const target = e.target;

				if (target.classList.contains('js-create-item')) {
					this.#edit(target);
				}
			});
		}

		#edit(target, parameters = {}) {
			parameters.context = this.context;
			parameters.hostid = this.hostids[0];

			const overlay = PopUp('item.edit', parameters, {
				dialogueid: 'item-edit',
				dialogue_class: 'modal-popup-large',
				trigger_element: target
			});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				postMessageOk(e.detail.title);

				if ('messages' in e.detail) {
					postMessageDetails('success', e.detail.messages);
				}

				location.href = location.href;
			});
		}

		#enable(target, itemids) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'item.enable');

			if (target !== null) {
				this.#confirmPost(curl, {itemids}, target);
			}
			else {
				this.#post(curl, {itemids});
			}
		}

		#disable(target, itemids) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'item.disable');

			if (target !== null) {
				this.#confirmPost(curl, {itemids}, target);
			}
			else {
				this.#post(curl, {itemids});
			}
		}

		_executeItems(target, itemids) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'item.execute');

			this._post(target, {itemids}, curl);
		}

		_clearItems(target, itemids) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'item.clear');

			this._confirmWithPost(target, {itemids}, curl);
		}

		_copyItems(target, itemids) {
			const parameters = {
				itemids: Object.keys(chkbxRange.getSelectedIds()),
				source: 'items'
			};
			const overlay = PopUp('copy.edit', parameters, {
				dialogueid: 'copy',
				dialogue_class: 'modal-popup-static'
			});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
				postMessageOk(e.detail.title);
				uncheckTableRows('item');

				if ('messages' in e.detail) {
					postMessageDetails('success', e.detail.messages);
				}

				location.href = location.href;
			});
		}

		_updateItems(target, itemids) {
			let action;
			let params = {
				context: target.closest('form').querySelector('[name="context"]').value,
				prototype: 0
			}
			params[this.token[0]] = this.token[1];
			const reloadPage = e => {
				location.href = location.href;
			}

			if (target.classList.contains('js-massupdate-item')) {
				action = 'item.massupdate';
				params.ids = Object.keys(chkbxRange.getSelectedIds());
			}
			// else {
			// 	action = 'item.edit';
			// 	params.hostid = this.hostids[0];
			// 	params.itemid = itemids.pop();
			// }

			const overlay = PopUp(action, params, {
				dialogue_class: 'modal-popup-preprocessing',
				trigger_element: target
			});
		}

		_deleteItems(target, itemids) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'item.delete');

			this._confirmWithPost(target, {itemids}, curl);
		}

		#confirmPost(curl, data, target) {
			const confirm = this.confirm_messages[curl.getArgument('action')];
			const message = confirm[data.itemids.length > 1 ? 1 : 0];

			if (message != '' && !window.confirm(message)) {
				return;
			}

			target.classList.add('is-loading');
			this.#post(curl, data)
				.finally(() => {
					target.classList.remove('is-loading');
					target.blur();
				});
		}

		#post(curl, data) {
			data[this.token[0]] = this.token[1];

			return fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(data)
			})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						if ('title' in response.error) {
							postMessageError(response.error.title);
						}

						postMessageDetails('error', response.error.messages);
					}
					else if ('success' in response) {
						postMessageOk(response.success.title);

						if ('messages' in response.success) {
							postMessageDetails('success', response.success.messages);
						}
					}

					uncheckTableRows('item');
					location.href = location.href;
				})
				.catch(() => {
					clearMessages();

					const message_box = makeMessageBox('bad', [<?= json_encode(_('Unexpected server error.')) ?>]);

					addMessage(message_box);
				});
		}

		editHost(e, hostid) {
			e.preventDefault();
			const host_data = {hostid};

			this.openHostPopup(host_data);
		}

		openHostPopup(host_data) {
			let original_url = location.href;
			const overlay = PopUp('popup.host.edit', host_data, {
				dialogueid: 'host_edit',
				dialogue_class: 'modal-popup-large',
				prevent_navigation: true
			});
			const reloadPage = (e) => {
				const data = e.detail;

				if ('success' in data) {
					postMessageOk(data.success.title);

					if ('messages' in data.success) {
						postMessageDetails('success', data.success.messages);
					}
				}

				location.href = original_url;
			}

			overlay.$dialogue[0].addEventListener('dialogue.create', reloadPage);
			overlay.$dialogue[0].addEventListener('dialogue.update', reloadPage);
			overlay.$dialogue[0].addEventListener('dialogue.delete', e => {
				const curl = new Curl('zabbix.php');
				curl.setArgument('action', 'host.list');

				original_url = curl.getUrl();
				return reloadPage(e);
			});
			overlay.$dialogue[0].addEventListener('overlay.close', () => {
				history.replaceState({}, '', original_url);
			});
		}
	};
</script>
