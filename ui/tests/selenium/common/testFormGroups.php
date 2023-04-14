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


require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';
require_once dirname(__FILE__).'/../traits/TableTrait.php';

/**
 * Base class for Host and Template group form.
 */
class testFormGroups extends CWebTest {

	use TableTrait;

	/**
	 * Attach MessageBehavior to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return ['class' => CMessageBehavior::class];
	}

	/**
	 * Objects created in dataSource DiscoveredHosts.
	 */
	const DISCOVERED_GROUP = 'Group created from host prototype 1';
	const HOST_PROTOTYPE = 'Host created from host prototype {#KEY}';
	const LLD = 'LLD for Discovered host tests';

	/**
	 * Host and template group name for cancel, clone and delete test scenario.
	 */
	const DELETE_GROUP = 'Group for Delete test';

	/**
	 * Host and template subgroup name for clone test scenario.
	 */
	const SUBGROUP = 'Group1/Subgroup1/Subgroup2';

	/**
	 * SQL query to get groups to compare hash values.
	 */
	const GROUPS_SQL = 'SELECT * FROM hstgrp g INNER JOIN hosts_groups hg ON g.groupid=hg.groupid'.
			' ORDER BY g.groupid, hg.hostgroupid';

	/**
	 * SQL query to get user group permissions for template and host groups to compare hash values.
	 */
	const PERMISSION_SQL = 'SELECT * FROM rights ORDER BY rightid';

	/**
	 * Flag for group form opened by direct link.
	 */
	protected $standalone = false;

	/**
	 * Link to page for opening group form.
	 */
	protected $link;

	/**
	 * Host or template group.
	 */
	protected $object;

	/**
	 * Group form check on search page.
	 */
	protected $search = false;

	/**
	 * Host and template group name for update test scenario.
	 */
	protected static $update_group;

	/**
	 * User group ID for subgroup permissions scenario.
	 */
	protected static $user_groupid;

	public static function prepareGroupData() {
		// Prepare data for template groups.
		CDataHelper::call('templategroup.create', [
			[
				'name' => 'Group for Update test'
			],
			[
				'name' => 'Group for Delete test'
			],
			[
				'name' => 'One group for Delete'
			],
			[
				'name' => 'Templates/Update'
			],
			[
				'name' => 'Group1/Subgroup1/Subgroup2'
			]
		]);
		$template_groupids = CDataHelper::getIds('name');
		CDataHelper::createTemplates([
			[
				'host' => 'Template for group testing',
				'groups' => [
					'groupid' => $template_groupids['One group for Delete']
				]
			]
		]);

		// Prepare data for host groups.
		CDataHelper::call('hostgroup.create', [
			[
				'name' => 'Group for Update test'
			],
			[
				'name' => 'Group for Delete test'
			],
			[
				'name' => 'One group for Delete'
			],
			[
				'name' => 'Group for Script'
			],
			[
				'name' => 'Group for Action'
			],
			[
				'name' => 'Group for Maintenance'
			],
			[
				'name' => 'Group for Host prototype'
			],
			[
				'name' => 'Group for Correlation'
			],
			[
				'name' => 'Hosts/Update'
			],
			[
				'name' => 'Group1/Subgroup1/Subgroup2'
			]
		]);
		$host_groupids = CDataHelper::getIds('name');

		// Create elements with host groups.
		$host = CDataHelper::createHosts([
			[
				'host' => 'Host for host group testing',
				'interfaces' => [],
				'groups' => [
					'groupid' => $host_groupids['One group for Delete']
				]
			]
		]);
		$hostid = $host['hostids']['Host for host group testing'];

		$lld = CDataHelper::call('discoveryrule.create', [
			'name' => 'LLD for host group test',
			'key_' => 'lld.hostgroup',
			'hostid' => $hostid,
			'type' => ITEM_TYPE_TRAPPER,
			'delay' => 30
		]);
		$lldid = $lld['itemids'][0];
		CDataHelper::call('hostprototype.create', [
			'host' => 'Host prototype {#KEY} for host group testing',
			'ruleid' => $lldid,
			'groupLinks' => [
				[
					'groupid' => $host_groupids['Group for Host prototype']
				]
			]
		]);

		CDataHelper::call('script.create', [
			[
				'name' => 'Script for host group testing',
				'scope' => ZBX_SCRIPT_SCOPE_ACTION,
				'type' => ZBX_SCRIPT_TYPE_WEBHOOK,
				'command' => 'return 1',
				'groupid' => $host_groupids['Group for Script']
			]
		]);

		CDataHelper::call('action.create', [
			[
				'name' => 'Discovery action for host group testing',
				'eventsource' => EVENT_SOURCE_DISCOVERY,
				'status' => ACTION_STATUS_ENABLED,
				'operations' => [
					[
						'operationtype' => OPERATION_TYPE_GROUP_ADD,
						'opgroup' => [
							[
								'groupid' => $host_groupids['Group for Action']
							]
						]
					]
				]
			]
		]);

		CDataHelper::call('maintenance.create', [
			[
				'name' => 'Maintenance for host group testing',
				'active_since' => 1358844540,
				'active_till' => 1390466940,
				'groups' => [
					[
						'groupid' => $host_groupids['Group for Maintenance']
					]
				],
				'timeperiods' => [[]]
			]
		]);

		CDataHelper::call('correlation.create', [
			[
				'name' => 'Corellation for host group testing',
				'filter' => [
					'evaltype' => ZBX_CORR_OPERATION_CLOSE_OLD,
					'conditions' => [
						[
							'type' => ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP,
							'groupid' => $host_groupids['Group for Correlation']
						]
					]
				],
				'operations' => [
					[
						'type' => ZBX_CORR_OPERATION_CLOSE_OLD
					]
				]
			]
		]);
	}

	/**
	 * Test for checking group form layout.
	 *
	 * @param string  $name        host or template group name
	 * @param boolean $discovered  discovered host group or not
	 */
	public function layout($name, $discovered = false) {
		// Check existing group form.
		$form = $this->openForm($name, $discovered);
		if ($this->standalone) {
			$this->page->assertHeader(ucfirst($this->object).' group');
			$footer = $form;
		}
		else {
			$dialog = COverlayDialogElement::find()->one()->waitUntilReady();
			$this->assertEquals(ucfirst($this->object).' group', $dialog->getTitle());
			$footer = $dialog->getFooter();
		}

		$this->assertTrue($form->isRequired('Group name'));
		$form->checkValue(['Group name' => $name]);
		$this->assertEquals(['Update', 'Clone', 'Delete','Cancel'], $footer->query('button')->all()
				->filter(CElementFilter::CLICKABLE)->asText()
		);

		if ($discovered) {
			$this->assertEquals(['Discovered by', 'Group name', 'Apply permissions and tag filters to all subgroups'],
					$form->getLabels(CElementFilter::VISIBLE)->asText()
			);
			$this->assertTrue($form->getField('Group name')->isAttributePresent('readonly'));
			$this->assertEquals(self::LLD, $form->getField('Discovered by')->query('tag:a')->one()->getText());
			$form->query('link', self::LLD)->one()->click();
			if (!$this->standalone) {
				$this->page->acceptAlert();
				$this->page->waitUntilReady();
			}
			$this->page->assertHeader('Host prototypes');
			$this->query('id:host')->one()->checkValue(self::HOST_PROTOTYPE);

			return;
		}

		$this->assertEquals(['Group name', ($this->object === 'host')
			? 'Apply permissions and tag filters to all subgroups'
			: 'Apply permissions to all subgroups'],
				$form->getLabels(CElementFilter::VISIBLE)->asText()
		);

		// There is no group creation on the search page.
		if ($this->search) {
			$dialog->close();

			return;
		}

		// Check new group form.
		if ($this->standalone) {
			$this->page->open('zabbix.php?action='.$this->object.'group.edit')->waitUntilReady();
			$this->page->assertHeader('New '.$this->object.' group');
		}
		else {
			$dialog->close();

			// Open group create form.
			$this->query('button', 'Create '.$this->object.' group')->one()->click();
			$this->assertEquals('New '.$this->object.' group', $dialog->getTitle());
		}

		$form->invalidate();
		$this->assertTrue($form->getField('Group name')->isAttributePresent(['maxlength' => '255', 'value' => '']));
		$this->assertTrue($form->isRequired('Group name'));
		$this->assertEquals(['Group name'], $form->getLabels(CElementFilter::VISIBLE)->asText());
		$this->assertEquals(['Add', 'Cancel'], $footer->query('button')->all()->filter(CElementFilter::CLICKABLE)->asText());
		$footer->query('button:Cancel')->one()->click();

		if ($this->standalone) {
			$form->waitUntilNotVisible();
			$this->page->assertHeader(ucfirst($this->object).' groups');
			$this->assertEquals(PHPUNIT_URL.'zabbix.php?action='.$this->object.'group.list', $this->page->getCurrentUrl());
		}
		else {
			$dialog->ensureNotPresent();
		}
	}

	/**
	 * Function for opening group form.
	 *
	 * @param string  $name        host or template group name to open
	 * @param boolean $discovered  discovered host group or not
	 *
	 * @return CForm
	 */
	public function openForm($name = null, $discovered = false) {
		if ($this->standalone) {
			if ($name) {
				$groupid = CDBHelper::getValue('SELECT groupid FROM hstgrp WHERE name='.zbx_dbstr($name).
						' AND type='.constant('HOST_GROUP_TYPE_'.strtoupper($this->object).'_GROUP')
				);
				$this->page->login()->open($this->link.$groupid)->waitUntilReady();
			}
			else {
				$this->page->login()->open('zabbix.php?action='.$this->object.'group.edit')->waitUntilReady();
			}

			return $this->query('id', $this->object.'groupForm')->asForm()->waitUntilVisible()->one();
		}
		else {
			$this->page->login()->open($this->link)->waitUntilReady();

			if ($name) {
				$column_name = $this->search ? ucfirst($this->object).' group' : 'Name';
				$table_selector = $this->search ? 'xpath://section[@id="search_'.$this->object.'group"]//table' : 'class:list-table';
				$table = $this->query($table_selector)->asTable()->one();
				$table->findRow($column_name, ($discovered && !$this->search) ? self::LLD.': '.$name : $name)
						->getColumn($column_name)->query('link', $name)->one()->click();
			}
			else {
				$this->query('button', 'Create '.$this->object.' group')->one()->click();
			}

			return COverlayDialogElement::find()->one()->waitUntilReady()->asForm();
		}
	}

	public static function getCreateData() {
		return [
			[
				[
					'expected' => TEST_BAD,
					'error' => 'Invalid parameter "/1/name": cannot be empty.'
				]
			],
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Group name' => ' '
					],
					'error' => 'Invalid parameter "/1/name": cannot be empty.'
				]
			],
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Group name' => 'Test/Test/'
					],
					'error' => 'Invalid parameter "/1/name": invalid host group name.',
					'template_error' => 'Invalid parameter "/1/name": invalid template group name.'
				]
			],
			[
				[
					'expected' => TEST_BAD,
					'fields' => [
						'Group name' => 'Test/Test\/'
					],
					'error' => 'Invalid parameter "/1/name": invalid host group name.',
					'template_error' => 'Invalid parameter "/1/name": invalid template group name.'
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Group name' => '~!@#$%^&*()_+=[]{}null{$A}{#B}'
					]
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Group name' => 'æ㓴🙂'
					]
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Group name' => '   trim    '
					],
					'trim' => true
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Group name' => 'Group/Subgroup1/Subgroup2'
					]
				]
			]
		];
	}

	public function getUpdateData() {
		$data = [];

		// Add 'update' word to group name and change group name in test case with trim.
		foreach ($this->getCreateData() as $group) {
			if ($group[0]['expected'] === TEST_GOOD) {
				$group[0]['fields']['Group name'] = CTestArrayHelper::get($group[0], 'trim', false)
					? '   trim update    '
					: $group[0]['fields']['Group name'].'update';
			}

			$data[] = $group;
		}

		return $data;
	}

	/**
	 * Test for checking group creation and update.
	 *
	 * @param array  $data    data provider
	 * @param string $action  create or update action
	 */
	protected function checkForm($data, $action) {
		$good_message = ucfirst($this->object).' group '.(($action === 'create') ? 'added' : 'updated');
		$bad_message = 'Cannot '.(($action === 'create') ? 'add' : 'update').' '.$this->object.' group';

		if ($data['expected'] === TEST_BAD) {
			$old_hash = CDBHelper::getHash(self::GROUPS_SQL);
			$permission_old_hash = CDBHelper::getHash(self::PERMISSION_SQL);
		}

		$form = $this->openForm(($action === 'update') ? static::$update_group : null);
		$form->fill(CTestArrayHelper::get($data, 'fields', []));

		// Clear name for update scenario.
		if ($action === 'update' && !CTestArrayHelper::get($data, 'fields', false)) {
			$form->getField('Group name')->clear();
		}

		$form->submit();

		if ($data['expected'] === TEST_GOOD) {
			if (!$this->standalone) {
				COverlayDialogElement::ensureNotPresent();
			}
			$this->assertMessage(TEST_GOOD, $good_message);

			// Trim trailing and leading spaces in expected values before comparison.
			if (CTestArrayHelper::get($data, 'trim', false)) {
				$data['fields']['Group name'] = trim($data['fields']['Group name']);
			}

			$form = $this->openForm($data['fields']['Group name']);
			$form->checkValue($data['fields']['Group name']);

			// Change group name after succefull update scenario.
			if ($action === 'update') {
				static::$update_group = $data['fields']['Group name'];
			}
		}
		else {
			$this->assertEquals($old_hash, CDBHelper::getHash(self::GROUPS_SQL));
			$this->assertEquals($permission_old_hash, CDBHelper::getHash(self::PERMISSION_SQL));
			$error_details =  ($this->object == 'template')
				? CTestArrayHelper::get($data, 'template_error', $data['error'])
				: $data['error'];
			$this->assertMessage(TEST_BAD, $bad_message, $error_details);
		}

		if (!$this->standalone) {
			COverlayDialogElement::find()->one()->close();
		}
	}

	/**
	 * Update group without changing data.
	 *
	 * @param string  $name        group name to be opened for check
	 * @param bollean $discovered  discovered host group or not
	 */
	public function simpleUpdate($name, $discovered = false) {
		$old_hash = CDBHelper::getHash(self::GROUPS_SQL);
		$form = $this->openForm($name, $discovered);
		$values = $form->getValues();
		$form->submit();
		$this->assertMessage(TEST_GOOD, ucfirst($this->object).' group updated');
		$this->assertEquals($old_hash, CDBHelper::getHash(self::GROUPS_SQL));

		// Check form values.
		$this->openForm($name, $discovered);
		$form->invalidate();
		$this->assertEquals($values, $form->getValues());

		if (!$this->standalone) {
			COverlayDialogElement::find()->one()->close();
		}
	}

	public static function getCloneData() {
		return [
			[
				[
					'expected' => TEST_BAD,
					'name' => self::DELETE_GROUP,
					'error' => ' group "'.self::DELETE_GROUP.'" already exists.'
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'name' => self::DELETE_GROUP,
					'fields'  => [
						'Group name' => microtime().' cloned group'
					]
				]
			],
			[
				[
					'expected' => TEST_GOOD,
					'name' => self::SUBGROUP,
					'fields'  => [
						'Group name' => microtime().'/cloned/subgroup'
					]
				]
			]
		];
	}

	public function clone($data) {
		if ($data['expected'] === TEST_BAD) {
			$old_hash = CDBHelper::getHash(self::GROUPS_SQL);
		}

		$form = $this->openForm($data['name'], CTestArrayHelper::get($data, 'discovered', false));
		$footer = ($this->standalone) ? $form : COverlayDialogElement::find()->one()->waitUntilReady()->getFooter();
		$footer->query('button:Clone')->one()->waitUntilClickable()->click();
		$form->invalidate();

		// Check that the group creation form is open after cloning.
		$title = 'New '.$this->object.' group';
		if ($this->standalone) {
			$this->page->assertHeader($title);
		}
		else {
			$this->assertEquals($title, COverlayDialogElement::find()->one()->waitUntilReady()->getTitle());
		}

		$this->assertEquals(PHPUNIT_URL.'zabbix.php?action='.$this->object.'group.edit', $this->page->getCurrentUrl());
		$this->assertEquals(['Add', 'Cancel'], $footer->query('button')->all()->filter(CElementFilter::CLICKABLE)->asText());
		$form->fill(CTestArrayHelper::get($data, 'fields', []));
		$form->submit();

		if ($data['expected'] === TEST_GOOD) {
			if (!$this->standalone) {
				COverlayDialogElement::ensureNotPresent();
			}
			$this->assertMessage(TEST_GOOD, ucfirst($this->object).' group added');
			$this->page->assertHeader($this->search ? 'Search: group' : ucfirst($this->object).' groups');
			$url = PHPUNIT_URL.($this->standalone ? 'zabbix.php?action='.$this->object.'group.list' : $this->link);
			$this->assertEquals($url, $this->page->getCurrentUrl());

			$form = $this->openForm($data['fields']['Group name']);
			$form->checkValue($data['fields']['Group name']);

			foreach ([$data['name'], $data['fields']['Group name']] as $name) {
				$this->assertEquals(1, CDBHelper::getCount('SELECT NULL FROM hstgrp WHERE name='.zbx_dbstr($name).
						' AND type='.constant('HOST_GROUP_TYPE_'.strtoupper($this->object).'_GROUP'))
				);
			}
		}
		else {
			$this->assertEquals($old_hash, CDBHelper::getHash(self::GROUPS_SQL));
			$this->assertMessage(TEST_BAD, 'Cannot add '.$this->object.' group', ucfirst($this->object).$data['error']);
		}

		if (!$this->standalone) {
			COverlayDialogElement::find()->one()->close();
		}
	}

	public static function getCancelData() {
		return [
			[
				[
					'action' => 'Add'
				]
			],
			[
				[
					'action' => 'Update'
				]
			],
			[
				[
					'action' => 'Clone'
				]
			],
			[
				[
					'action' => 'Delete'
				]
			]
		];
	}

	/**
	 * Test for checking group actions cancelling.
	 *
	 * @param array $data  data provider with fields values
	 */
	public function cancel($data) {
		// TODO: delete if() after fix ZBX-22376
		if ($this->standalone && $data['action'] === 'Delete') {
			return;
		}

		// There is no group creation on the search page.
		if ($this->search && $data['action'] === 'Add') {
			return;
		}

		$old_hash = CDBHelper::getHash(self::GROUPS_SQL);
		$new_name = microtime(true).' Cancel '.self::DELETE_GROUP;
		$form = $this->openForm(($data['action'] === 'Add') ? null : self::DELETE_GROUP);

		// Change name.
		$form->fill(['Group name' => $new_name]);
		$footer = ($this->standalone) ? $form : COverlayDialogElement::find()->one()->waitUntilReady()->getFooter();

		if (in_array($data['action'], ['Clone', 'Delete'])) {
			$footer->query('button', $data['action'])->one()->click();
		}

		if ($data['action'] === 'Delete') {
			$this->page->dismissAlert();
		}

		// Refresh element after opening new form after cloning.
		if ($data['action'] === 'Clone') {
			$footer = ($this->standalone)
				? $form->invalidate()
				: COverlayDialogElement::find()->one()->waitUntilReady()->getFooter();
		}

		$footer->query('button:Cancel')->waitUntilClickable()->one()->click();

		if (!$this->standalone) {
			COverlayDialogElement::ensureNotPresent();
		}

		$this->page->assertHeader($this->search ? 'Search: group' : ucfirst($this->object).' groups');
		$url = PHPUNIT_URL.($this->standalone ? 'zabbix.php?action='.$this->object.'group.list' : $this->link);
		$this->assertEquals($url, $this->page->getCurrentUrl());
		$this->assertEquals($old_hash, CDBHelper::getHash(self::GROUPS_SQL));

	}

	public static function getDeleteData() {
		return [
			[
				[
					'expected' => TEST_GOOD,
					'name' => self::DELETE_GROUP
				]
			]
		];
	}

	/**
	 * Test for checking group deletion.
	 *
	 * @param array $data  data provider
	 */
	public function delete($data) {
		if ($data['expected'] === TEST_BAD) {
			$old_hash = CDBHelper::getHash(self::GROUPS_SQL);
		}

		$form = $this->openForm($data['name']);
		$footer = ($this->standalone) ? $form : COverlayDialogElement::find()->one()->waitUntilReady()->getFooter();
		$footer->query('button:Delete')->one()->waitUntilClickable()->click();

		if (!$this->standalone) {
			$this->assertEquals('Delete selected '.$this->object.' group?', $this->page->getAlertText());
			$this->page->acceptAlert();
		}

		if ($data['expected'] === TEST_GOOD) {
			if (!$this->standalone) {
				COverlayDialogElement::ensureNotPresent();
			}

			$this->assertMessage(TEST_GOOD, ucfirst($this->object).' group deleted');
			$this->assertEquals(0, CDBHelper::getCount('SELECT NULL FROM hstgrp WHERE name='.zbx_dbstr($data['name']).
					' AND type='.constant('HOST_GROUP_TYPE_'.strtoupper($this->object).'_GROUP'))
			);
		}
		else {
			$this->assertEquals($old_hash, CDBHelper::getHash(self::GROUPS_SQL));
			$this->assertMessage(TEST_BAD, 'Cannot delete '.$this->object.' group', $data['error']);

			if (!$this->standalone) {
				COverlayDialogElement::find()->one()->close();
			}
		}
	}

	public static function prepareSubgroupData() {
		CDataHelper::call('hostgroup.create', [
			[
				'name' => 'Europe'
			],
			[
				'name' => 'Europe/Latvia'
			],
			[
				'name' => 'Europe/Latvia/Riga/Zabbix'
			],
			[
				'name' => 'Europe/Test'
			],
			[
				'name' => 'Europe/Test/Zabbix'
			],
			// Groups to check inherited permissions when creating a parent or subgroup.
			[
				'name' => 'Streets'
			],
			[
				'name' => 'Cities/Cesis'
			],
			[
				'name' => 'Europe group for test on search page'
			]
		]);
		$host_groupids = CDataHelper::getIds('name');

		CDataHelper::call('templategroup.create', [
			[
				'name' => 'Europe'
			],
			[
				'name' => 'Europe/Latvia'
			],
			[
				'name' => 'Europe/Latvia/Riga/Zabbix'
			],
			[
				'name' => 'Europe/Test'
			],
			[
				'name' => 'Europe/Test/Zabbix'
			],
			[
				'name' => 'Streets'
			],
			[
				'name' => 'Cities/Cesis'
			],
			[
				'name' => 'Europe group for test on search page'
			]
		]);
		$template_groupids = CDataHelper::getIds('name');

		$response = CDataHelper::call('usergroup.create', [
			[
				'name' => 'User group to check subgroup permissions',
				'hostgroup_rights' => [
					[
						'permission' => PERM_DENY,
						'id' => $host_groupids['Europe']
					],
					[
						'permission' => PERM_READ,
						'id' => $host_groupids['Europe/Latvia']
					],
					[
						'permission' => PERM_READ_WRITE,
						'id' => $host_groupids['Europe/Test']
					],
					[
						'permission' => PERM_DENY,
						'id' => $host_groupids['Streets']
					],
					[
						'permission' => PERM_READ,
						'id' => $host_groupids['Cities/Cesis']
					]
				],
				'templategroup_rights' => [
					[
						'permission' => PERM_DENY,
						'id' => $template_groupids['Europe']
					],
					[
						'permission' => PERM_READ,
						'id' => $template_groupids['Europe/Latvia']
					],
					[
						'permission' => PERM_READ_WRITE,
						'id' => $template_groupids['Europe/Test']
					],
					[
						'permission' => PERM_DENY,
						'id' => $template_groupids['Streets']
					],
					[
						'permission' => PERM_READ,
						'id' => $template_groupids['Cities/Cesis']
					]
				],
				'tag_filters' => [
					[
						'groupid' => $host_groupids['Europe'],
						'tag' => 'world',
						'value' => ''
					],
					[
						'groupid' => $host_groupids['Europe/Test'],
						'tag' => 'country',
						'value' => 'test'
					],
					[
						'groupid' => $host_groupids['Streets'],
						'tag' => 'street',
						'value' => ''
					],
					[
						'groupid' => $host_groupids['Cities/Cesis'],
						'tag' => 'city',
						'value' => 'Cesis'
					]
				]
			]
		]);
		self::$user_groupid = $response['usrgrpids'][0];
	}

	public static function getSubgroupsData() {
		return [
			[
				[
					'apply_permissions' => 'Europe/Test',
					'create' => 'Cities',
					// "groups_before" parameter isn't used in test, but groups are listed here for test clarity.
					'groups_before' => [
						'All groups' => 'None',
						'Cities/Cesis' => 'Read',
						'Europe' =>	'Deny',
						'Europe/Latvia' => 'Read',
						'Europe/Latvia/Riga/Zabbix' => 'None',
						'Europe/Test' => 'Read-write',
						'Europe/Test/Zabbix' => 'None',
						'Streets' => 'Deny'
					],
					'tags_before' => [
						['Host group' => 'Cities/Cesis', 'Tags' => 'city: Cesis'],
						['Host group' => 'Europe', 'Tags' => 'world'],
						['Host group' => 'Europe/Test', 'Tags' => 'country: test'],
						['Host group' => 'Streets', 'Tags' => 'street']
					],
					'groups_after' => [
						'Cities/Cesis' => 'Read',
						'Europe' =>	'Deny',
						'Europe/Latvia' => 'Read',
						'Europe/Latvia/Riga/Zabbix' => 'None',
						'Europe/Test (including subgroups)' => 'Read-write',
						'Streets' => 'Deny'
					],
					'tags_after' => [
						['Host group' => 'Cities/Cesis', 'Tags' => 'city: Cesis'],
						['Host group' => 'Europe' , 'Tags' => 'world'],
						['Host group' => 'Europe/Test', 'Tags' => 'country: test'],
						['Host group' => 'Europe/Test/Zabbix', 'Tags' => 'country: test'],
						['Host group' => 'Streets', 'Tags' => 'street']
					]
				]
			],
			[
				[
					'apply_permissions' => 'Europe',
					'create' => 'Streets/Dzelzavas',
					'groups_before' => [
						'All groups' => 'None',
						'Cities/Cesis' => 'Read',
						'Europe' =>	'Deny',
						'Europe/Latvia (including subgroups)' => 'Read',
						'Europe/Test (including subgroups)' => 'Read-write',
						'Streets' => 'Deny'
					],
					'tags_before' => [
						['Host group' => 'Cities/Cesis', 'Tags' => 'city: Cesis'],
						['Host group' => 'Europe', 'Tags' => 'world'],
						['Host group' => 'Europe/Test', 'Tags' => 'country: test'],
						// For host group scenario, there will be additional tag 'country: test' for 'Europe/Test/Zabbix'
						['Host group' => 'Streets', 'Tags' => 'street']
					],
					'groups_after' => [
						'Cities/Cesis' => 'Read',
						'Europe (including subgroups)' => 'Deny',
						'Streets (including subgroups)' => 'Deny'
					],
					'tags_after' => [
						['Host group' => 'Cities/Cesis', 'Tags' => 'city: Cesis'],
						['Host group' => 'Europe', 'Tags' => 'world'],
						['Host group' => 'Europe/Latvia', 'Tags' => 'world'],
						['Host group' => 'Europe/Latvia/Riga/Zabbix', 'Tags' => 'world'],
						['Host group' => 'Europe/Test', 'Tags' => 'world'],
						['Host group' => 'Europe/Test/Zabbix', 'Tags' => 'world'],
						['Host group' => 'Streets', 'Tags' => 'street'],
						['Host group' => 'Streets/Dzelzavas', 'Tags' => 'street']
					]
				]
			]
		];
	}

	/**
	 * Apply the same level of permissions/tag filters to all nested host groups.
	 *
	 * @param array $data  data provider
	 */
	public function checkSubgroupsPermissions($data) {
		// Prepare groups array according framework function assertTableData().
		$selector = 'xpath:.//input[@checked]/following-sibling::label';
		$groups = [];
		foreach ($data['groups_after'] as $group => $permissions) {
			$groups[] = [
				ucfirst($this->object).' group' => $group,
				'Permissions' => [
					'text' => $permissions,
					'selector' => $selector
				]
			];
		}
		$data['groups_after'] = $groups;
		array_unshift($data['groups_after'], [ucfirst($this->object).' group' => 'All groups', 'Permissions' => 'None']);

		// Create new parent or subgroup to check nested permissions.
		if (array_key_exists('create', $data)) {
			$form = $this->openForm(CTestArrayHelper::get($data, 'open_form', null));
			$form->fill(['Group name' => $data['create']]);
			$form->submit();

			if (!$this->standalone) {
				COverlayDialogElement::ensureNotPresent();
			}

			// Permission inheritance doesn't apply when changing the name of existing group, only when creating a new group.
			$this->assertMessage(TEST_GOOD,
					ucfirst($this->object).' group '.(array_key_exists('open_form', $data) ? 'updated' : 'added')
			);
		}

		// Apply permissions to subgroups.
		$form = $this->openForm($data['apply_permissions']);
		$form->fill([(($this->object === 'host')
			? 'Apply permissions and tag filters to all subgroups'
			: 'Apply permissions to all subgroups') => true
		]);
		$form->submit();

		if (!$this->standalone) {
			COverlayDialogElement::ensureNotPresent();
		}
		$this->assertMessage(TEST_GOOD, ucfirst($this->object).' group updated');

		// Check group and tag permissions in user group.
		$this->page->open('zabbix.php?action=usergroup.edit&usrgrpid='.self::$user_groupid)->waitUntilReady();
		$group_form = $this->query('id:user-group-form')->asForm()->one();
		$group_form->selectTab(ucfirst($this->object).' permissions');
		$this->assertTableData($data['groups_after'],
				'id:'.(($this->object === 'template') ? 'template' : '').'group-right-table'
		);
		$group_form->selectTab('Problem tag filter');
		$this->assertTableData(($this->object === 'template') ? $data['tags_before'] : $data['tags_after'],
				'id:tag-filter-table'
		);
	}
}
