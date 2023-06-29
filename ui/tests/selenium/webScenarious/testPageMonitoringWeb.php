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
require_once dirname(__FILE__).'/../traits/TableTrait.php';
require_once dirname(__FILE__).'/../../include/helpers/CDataHelper.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';

/**
 * @backup hosts, httptest
 *
 * @onBefore prepareHostWebData
 */
class testPageMonitoringWeb extends CWebTest {

	use TableTrait;

	/**
	 * Attach MessageBehavior to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return [CMessageBehavior::class];
	}

	/**
	 * Host id created for web service.
	 *
	 * @var integer
	 */
	private static $hostid;

	/**
	 * Web service ids.
	 *
	 * @var integer
	 */
	private static $httptestid;

	public function prepareHostWebData() {
		CDataHelper::call('hostgroup.create', [
			[
				'name' => 'WebData HostGroup'
			]
		]);
		$hostgrpid = CDataHelper::getIds('name');

		CDataHelper::call('host.create', [
			'host' => 'WebData Host',
			'groups' => [
				[
					'groupid' => $hostgrpid['WebData HostGroup']
				]
			],
			'interfaces' => [
				'type'=> 1,
				'main' => 1,
				'useip' => 1,
				'ip' => '192.168.3.217',
				'dns' => '',
				'port' => '10050'
			]
		]);
		self::$hostid = CDataHelper::getIds('host');

		CDataHelper::call('httptest.create', [
			[
				'name' => 'Web scenario 1 step',
				'hostid' => self::$hostid['WebData Host'],
				'steps' => [
					[
						'name' => 'Homepage',
						'url' => 'http://zabbix.com',
						'no' => 1
					]
				]
			],
			[
				'name' => 'Web scenario 2 step',
				'hostid' => self::$hostid['WebData Host'],
				'steps' => [
					[
						'name' => 'Homepage1',
						'url' => 'http://example.com',
						'no' => 1
					],
					[
						'name' => 'Homepage2',
						'url' => 'http://example.com',
						'no' => 2
					]
				]
			],
			[
				'name' => 'Web scenario 3 step',
				'hostid' => self::$hostid['WebData Host'],
				'steps' => [
					[
						'name' => 'Homepage1',
						'url' => 'http://example.com',
						'no' => 1
					],
					[
						'name' => 'Homepage2',
						'url' => 'http://example.com',
						'no' => 2
					],
					[
						'name' => 'Homepage3',
						'url' => 'http://example.com',
						'no' => 3
					]
				]
			]
		]);
		self::$httptestid = CDataHelper::getIds('name');
	}

	/**
	 * Function which checks the layout of Web page.
	 */
	public function testPageMonitoringWeb_Layout() {
		// Logins directly into required page.
		$this->page->login()->open('zabbix.php?action=web.view')->waitUntilReady();
		$form = $this->query('name:zbx_filter')->asForm()->one();
		$table = $this->query('class:list-table')->asTable()->one();

		// Checks Title, Header, and column names, and filter labels.
		$this->page->assertTitle('Web monitoring');
		$this->page->assertHeader('Web monitoring');
		$this->assertEquals(['Host', 'Name', 'Number of steps', 'Last check', 'Status'], $table->getHeadersText());
		$this->assertEquals(['Host groups', 'Hosts'], $form->getLabels()->asText());

		// Check if Apply and Reset button are clickable.
		foreach (['Apply', 'Reset'] as $button) {
			$this->assertTrue($form->query('button', $button)->one()->isClickable());
		}

		// Check filter collapse/expand.
		foreach (['true', 'false'] as $status) {
			$this->assertTrue($this->query('xpath://li[@aria-expanded='.CXPathHelper::escapeQuotes($status).']')
					->one()->isPresent()
			);
			$this->query('xpath://a[@class="filter-trigger ui-tabs-anchor"]')->one()->click();
		}

		// Check if links to Hosts and to Web scenarios are clickable.
		$row = $table->getRow(0);

		foreach (['Host', 'Name'] as $field) {
			$this->assertTrue($row->getColumn($field)->query('xpath:.//a')->one()->isClickable());
		}

		// Check that the correct details of scenario are opened.
		$column = $row->getColumn('Name');
		$first_row_name = $column->getText();
		$column->query('tag:a')->one()->click();

		$this->page->waitUntilReady();
		$this->page->assertHeader('Details of web scenario: '.$first_row_name);
		$this->page->assertTitle('Details of web scenario');
	}

	/**
	 * Function which checks if button "Reset" works properly.
	 */
	public function testPageMonitoringWeb_ResetButtonCheck() {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1');
		$form = $this->query('name:zbx_filter')->waitUntilPresent()->asForm()->one();
		$this->page->waitUntilReady();
		$table = $this->query('class:list-table')->asTable()->one();

		// Check table contents before filtering.
		$start_rows_count = $table->getRows()->count();
		$this->assertTableStats($start_rows_count);
		$start_contents = $this->getTableColumnData('Name');

		// Filter hosts.
		$form->fill(['Hosts' => 'Simple form test host']);
		$this->query('button:Apply')->one()->waitUntilClickable()->click();
		$table->waitUntilReloaded();

		// Check that filtered count matches expected.
		$this->assertEquals(4, $table->getRows()->count());
		$this->assertTableStats(4);

		// After pressing reset button, check that previous hosts are displayed again.
		$this->query('button:Reset')->one()->click();
		$table->waitUntilReloaded();
		$reset_rows_count = $table->getRows()->count();
		$this->assertEquals($start_rows_count, $reset_rows_count);
		$this->assertTableStats($reset_rows_count);
		$this->assertEquals($start_contents, $this->getTableColumnData('Name'));
	}

	/**
	 * Function which checks Hosts context menu.
	 */
	public function testPageMonitoringWeb_CheckHostContextMenu() {
		$popupitems = [
			'Inventory', 'Latest data',	'Problems',	'Graphs', 'Screens', 'Configuration', 'Detect operating system',
			'Ping', 'Selenium script', 'Traceroute'
		];

		$this->checkHostContextMenu($popupitems, 'WebData Host', 'Graphs');
		$this->checkHostContextMenu($popupitems, 'WebData Host', 'Screens');
		$this->checkHostContextMenu($popupitems, 'Simple form test host', 'Screens');
	}

	/**
	 * Function for checking the context menu of the selected host.
	 *
	 * @param array		$popupitems		items of the popup window.
	 * @param string	$hostname		name of the host.
	 * @param string	$disabled		disabled host elements.
	 *
	 */
	private function checkHostContextMenu($popupitems, $hostname, $disabled) {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1&sort=hostname&sortorder=DESC');
		$this->query('class:list-table')->asTable()->one()->findRow('Host', $hostname)->query('link', $hostname)->one()->click();
		$popup = CPopupMenuElement::find()->waitUntilVisible()->one();
		$this->assertEquals(['HOST', 'SCRIPTS'], $popup->getTitles()->asText());
		$this->assertTrue($popup->hasItems($popupitems));
		$this->assertTrue($popup->query('xpath://a[@aria-label="Host, ' .
			$disabled . '" and @class="menu-popup-item-disabled "]')->one()->isPresent()
		);
		$popup->close();
	}

	/**
	 * Function which checks if disabled web services aren't displayed.
	 */
	public function testPageMonitoringWeb_CheckDisabledWebServices() {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1&sort=name&sortorder=DESC')->waitUntilReady();
		$values = $this->getTableColumnData('Name');

		// Turn off/on web services and check table results.
		foreach (['Disable', 'Enable'] as $status) {
			$this->page->open('httpconf.php?filter_set=1&filter_hostids%5B0%5D='.self::$hostid['WebData Host'])->waitUntilReady();
			$this->query('xpath://input[@id="all_httptests"]')->one()->click();
			$this->query('xpath://button[normalize-space()="'.$status.'"]')->one()->click();
			$this->page->acceptAlert();

			$this->assertMessage(TEST_GOOD, ($status === 'Disable' ? 'Web scenarios disabled' :'Web scenarios enabled'));

			$this->page->open('zabbix.php?action=web.view&filter_rst=1&sort=name&sortorder=DESC')->waitUntilReady();
			$changed = ($status === 'Disable')
				? array_diff($values, ['Web scenario 1 step', 'Web scenario 2 step', 'Web scenario 3 step'])
				: $values;
			$this->assertTableDataColumn($changed);
		}
	}

	/**
	 * Function which checks number of steps for web services displayed.
	 */
	public function testPageMonitoringWeb_CheckWebServiceNumberOfSteps() {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1&sort=name&sortorder=DESC')->waitUntilReady();
		$row = $this->query('class:list-table')->asTable()->one()->findRow('Name', 'Web scenario 3 step');
		$this->assertEquals('3', $row->getColumn('Number of steps')->getText());

		// Directly open API created Web scenario and add one more step.
		$this->page->open('httpconf.php?context=host&form=update&hostid='.self::$hostid['WebData Host'].'&httptestid='.
				self::$httptestid['Web scenario 3 step'])->waitUntilReady();
		$this->query('xpath://a[@id="tab_stepTab"]')->one()->click();
		$this->query('xpath://button[@class="element-table-add btn-link"]')->one()->click();
		COverlayDialogElement::find()->one()->waitUntilReady();
		$form = $this->query('id:http_step')->asForm()->one();
		$form->fill(['Name' => 'Step number 4', 'id:url' => 'test.com']);
		$form->submit();
		$this->query('button:Update')->one()->click();
		$this->page->waitUntilReady();
		$this->assertMessage(TEST_GOOD, 'Web scenario updated');

		// Return to the "Web monitoring" and check if the "Number of steps" is correctly displayed.
		$this->page->open('zabbix.php?action=web.view&filter_rst=1&sort=name&sortorder=DESC')->waitUntilReady();
		$this->assertEquals('4', $row->getColumn('Number of steps')->getText());
	}

	/**
	 * Function which checks sorting by Name/Host column.
	 */
	public function testPageMonitoringWeb_CheckSorting() {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1&sort=hostname&sortorder=ASC')->waitUntilReady();
		$table = $this->query('class:list-table')->asTable()->one();

		foreach (['Host', 'Name'] as $column_name) {
			if ($column_name === 'Name') {
				$table->query('xpath:.//a[text()="'.$column_name.'"]')->one()->click();
			}
			$column_values = $this->getTableColumnData($column_name);

			foreach (['asc', 'desc'] as $sorting) {
				$expected = ($sorting === 'asc') ? $column_values : array_reverse($column_values);
				$this->assertEquals($expected, $this->getTableColumnData($column_name));
				$table->query('xpath:.//a[text()="'.$column_name.'"]')->one()->click();
			}
		}
	}

	/**
	 * Function which checks that title field disappears while Kioskmode is active.
	 */
	public function testPageMonitoringWeb_KioskMode() {
		$this->page->login()->open('zabbix.php?action=web.view')->waitUntilReady();

		// Check title, filter and table display after pressing Kiosk mode/Normal view.
		foreach (['Kiosk mode', 'Normal view'] as $status) {
			$this->query('xpath://button[@title="'.$status.'"]')->one()->click();
			$this->page->waitUntilReady();

			if ($status === 'Kiosk mode') {
				$this->query('xpath://h1[@id="page-title-general"]')->waitUntilNotVisible();
			}
			else {
				$this->query('xpath://h1[@id="page-title-general"]')->waitUntilVisible();
			}

			$this->assertTrue($this->query('xpath://div[@aria-label="Filter"]')->exists());
			$this->assertTrue($this->query('id:flickerfreescreen_httptest')->exists());
		}

		$this->query('xpath://button[@title="Kiosk mode"]')->waitUntilVisible();
	}

	public static function getFilterData() {
		return [
			// #0.
			[
				[
					'filter' => [
						'Host groups' => 'Zabbix servers'
					],
					'expected' => [
						'Web ZBX6663 Second',
						'Web ZBX6663',
						'testInheritanceWeb4',
						'testInheritanceWeb3',
						'testInheritanceWeb2',
						'testInheritanceWeb1',
						'testFormWeb4',
						'testFormWeb3',
						'testFormWeb2',
						'testFormWeb1'
					]
				]
			],
			// #1.
			[
				[
					'filter' => [
						'Hosts' => 'Simple form test host'
					],
					'expected' => [
						'testFormWeb4',
						'testFormWeb3',
						'testFormWeb2',
						'testFormWeb1'
					]
				]
			],
			// #2.
			[
				[
					'filter' => [
						'Host groups' => 'Zabbix servers',
						'Hosts' => 'Host ZBX6663'
					],
					'expected' => [
						'Web ZBX6663 Second',
						'Web ZBX6663'
					]
				]
			],
			// #3.
			[
				[
					'filter' => [
						'Host groups' => 'Zabbix servers',
						'Hosts' => [
							'Host ZBX6663',
							'Simple form test host'
						]
					],
					'expected' => [
						'Web ZBX6663 Second',
						'Web ZBX6663',
						'testFormWeb4',
						'testFormWeb3',
						'testFormWeb2',
						'testFormWeb1'
					]
				]
			],
			// #4.
			[
				[
					'filter' => [
						'Hosts' => [
							'Host ZBX6663',
							'Simple form test host',
							'Template inheritance test host'
						]
					],
					'expected' => [
						'Web ZBX6663 Second',
						'Web ZBX6663',
						'testInheritanceWeb4',
						'testInheritanceWeb3',
						'testInheritanceWeb2',
						'testInheritanceWeb1',
						'testFormWeb4',
						'testFormWeb3',
						'testFormWeb2',
						'testFormWeb1'
					]
				]
			],
			// #5.
			[
				[
					'filter' => [
						'Host groups' => [
							'WebData HostGroup',
							'Zabbix servers'
						],
						'Hosts' => [
							'Host ZBX6663',
							'WebData Host'
						]
					],
					'expected' => [
						'Web ZBX6663 Second',
						'Web ZBX6663',
						'Web scenario 3 step',
						'Web scenario 2 step',
						'Web scenario 1 step'
					]
				]
			]
		];
	}

	/**
	 * @dataProvider getFilterData
	 */
	public function testPageMonitoringWeb_Filter($data) {
		$this->page->login()->open('zabbix.php?action=web.view&filter_rst=1&sort=name&sortorder=DESC')->waitUntilReady();
		$this->query('name:zbx_filter')->waitUntilPresent()->asForm()->one()->fill($data['filter'])->submit();
		$this->page->waitUntilReady();
		$this->assertTableDataColumn($data['expected']);
	}
}
