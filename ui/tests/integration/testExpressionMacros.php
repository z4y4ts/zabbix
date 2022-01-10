<?php
/*
** Zabbix
** Copyright (C) 2001-2022 Zabbix SIA
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

require_once dirname(__FILE__).'/../include/CIntegrationTest.php';

/**
 * Test suite for expression macros
 *
 * @required-components server
 * @configurationDataProvider serverConfigurationProvider
 * @backup items,actions,triggers
 * @hosts test_macros
 */
class testExpressionMacros extends CIntegrationTest {

	private static $hostid;
	private static $triggerid;
	private static $trigger_actionid;
	private static $alert_response;
	private static $event_response;

	const TRAPPER_ITEM_NAME = 'trap';
	const HOST_NAME = 'test_macros';
	const MESSAGE_PREFIX = 'message with expression macro: ';
	const SUBJECT_PREFIX = 'subject with expression macro: ';
	const EVENT_PREFIX = 'event name with expression macro: ';

	/**
	 * @inheritdoc
	 */
	public function prepareData() {
		// Create host "test_macros".
		$response = $this->call('host.create', [
			'host' => self::HOST_NAME,
			'interfaces' => [
				[
					'type' => 1,
					'main' => 1,
					'useip' => 1,
					'ip' => '127.0.0.1',
					'dns' => '',
					'port' => $this->getConfigurationValue(self::COMPONENT_AGENT, 'ListenPort')
				]
			],
			'groups' => [
				[
					'groupid' => 4
				]
			]
		]);

		$this->assertArrayHasKey('hostids', $response['result']);
		$this->assertArrayHasKey(0, $response['result']['hostids']);
		self::$hostid = $response['result']['hostids'][0];

		// Get host interface ids.
		$response = $this->call('host.get', [
			'output' => ['host'],
			'hostids' => [self::$hostid],
			'selectInterfaces' => ['interfaceid']
		]);

		$this->assertArrayHasKey(0, $response['result']);
		$this->assertArrayHasKey('interfaces', $response['result'][0]);
		$this->assertArrayHasKey(0, $response['result'][0]['interfaces']);

		// Create trapper item
		$response = $this->call('item.create', [
			'hostid' => self::$hostid,
			'name' => self::TRAPPER_ITEM_NAME,
			'key_' => self::TRAPPER_ITEM_NAME,
			'type' => ITEM_TYPE_TRAPPER,
			'value_type' => ITEM_VALUE_TYPE_UINT64
		]);
		$this->assertArrayHasKey('itemids', $response['result']);
		$this->assertEquals(1, count($response['result']['itemids']));

		// Create trigger
		$response = $this->call('trigger.create', [
			'description' => 'trigger_trap',
			'expression' => 'last(/'.self::HOST_NAME.'/'.self::TRAPPER_ITEM_NAME.')<>3',
			'event_name' => self::EVENT_PREFIX.'{?last(//'.self::TRAPPER_ITEM_NAME.')}'
		]);
		$this->assertArrayHasKey('triggerids', $response['result']);
		$this->assertEquals(1, count($response['result']['triggerids']));
		self::$triggerid = $response['result']['triggerids'][0];

		// Create trigger action
		$response = $this->call('action.create', [
			'esc_period' => '1m',
			'eventsource' => EVENT_SOURCE_TRIGGERS,
			'status' => 0,
			'filter' => [
				'conditions' => [
					[
						'conditiontype' => CONDITION_TYPE_TRIGGER,
						'operator' => CONDITION_OPERATOR_EQUAL,
						'value' => self::$triggerid
					]
				],
				'evaltype' => CONDITION_EVAL_TYPE_AND_OR
			],
			'name' => 'action_trigger_trap',
			'operations' => [
				[
					'esc_period' => 0,
					'esc_step_from' => 1,
					'esc_step_to' => 1,
					'operationtype' => OPERATION_TYPE_MESSAGE,
					'opmessage' => [
						'default_msg' => 0,
						'mediatypeid' => 1,
						'subject' => self::SUBJECT_PREFIX.'{?last(//'.self::TRAPPER_ITEM_NAME.')}',
						'message' => self::MESSAGE_PREFIX.'{?last(/'.self::HOST_NAME.'/'.self::TRAPPER_ITEM_NAME.')}'
					],
					'opmessage_grp' => [
						['usrgrpid' => 7]
					]
				],
				[
					'esc_period' => 0,
					'esc_step_from' => 2,
					'esc_step_to' => 2,
					'operationtype' => OPERATION_TYPE_MESSAGE,
					'opmessage' => [
						'default_msg' => 0,
						'mediatypeid' => 4,
						'subject' => self::SUBJECT_PREFIX.'{?first(//'.self::TRAPPER_ITEM_NAME.',1h)}',
						'message' => self::MESSAGE_PREFIX.'{?first(/{HOST.HOST}/'.self::TRAPPER_ITEM_NAME.',1h)}'
					],
					'opmessage_grp' => [
						['usrgrpid' => 7]
					]
				]
			],
			'pause_suppressed' => 0,
			'recovery_operations' => [
				[
					'operationtype' => OPERATION_TYPE_MESSAGE,
					'opmessage' => [
						'default_msg' => 0,
						'mediatypeid' => 0,
						'subject' => self::SUBJECT_PREFIX.'{?last(//'.self::TRAPPER_ITEM_NAME.')}',
						'message' => self::MESSAGE_PREFIX.'{?last(//'.self::TRAPPER_ITEM_NAME.',#2)}'
					],
					'opmessage_grp' => [
						['usrgrpid' => 7]
					]
				]
			]
		]);
		$this->assertArrayHasKey('actionids', $response['result']);
		$this->assertEquals(1, count($response['result']['actionids']));
		self::$trigger_actionid = $response['result']['actionids'][0];

		return true;
	}

	/**
	 * Component configuration provider.
	 *
	 * @return array
	 */
	public function serverConfigurationProvider() {
		return [
			self::COMPONENT_SERVER => [
				'DebugLevel' => 4,
				'LogFileSize' => 20,
				'AllowUnsupportedDBVersions' => 1
			]
		];
	}

	/**
	 * Get data
	 *
	 * @backup alerts,events,history_uint
	 */
	public function testExpressionMacros_getData() {
		$this->sendSenderValue(self::HOST_NAME, self::TRAPPER_ITEM_NAME, 3);
		$this->sendSenderValue(self::HOST_NAME, self::TRAPPER_ITEM_NAME, 2);

		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'In escalation_execute()', true);
		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'End of escalation_execute()', true, 10, 3);

		self::$event_response = $this->callUntilDataIsPresent('event.get', [
			'hostids' => [self::$hostid]
		], 5, 2);
		$this->assertCount(1, self::$event_response['result']);

		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'In escalation_execute()', true, 95, 3);
		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'End of escalation_execute()', true, 10, 3);

		$this->sendSenderValue(self::HOST_NAME, self::TRAPPER_ITEM_NAME, 3);

		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'In escalation_recover()', true);
		$this->waitForLogLineToBePresent(self::COMPONENT_SERVER, 'End of escalation_recover()', true, 10, 3);

		self::$alert_response = $this->callUntilDataIsPresent('alert.get', [
			'actionids' => [self::$trigger_actionid],
			'sortfield' => 'alertid'
		], 5, 2);
		$this->assertCount(3, self::$alert_response['result']);
	}

	/**
	 * Test expression macro in problem message
	 */
	public function testExpressionMacros_checkProblemMessage() {
		$this->assertEquals(self::MESSAGE_PREFIX.'2', self::$alert_response['result'][0]['message']);
	}

	/**
	 * Test expression macro with empty hostname
	 */
	public function testExpressionMacros_checkEmptyHostname() {
		$this->assertEquals(self::SUBJECT_PREFIX.'2', self::$alert_response['result'][0]['subject']);
	}

	/**
	 * Test expression macro in function with argument
	 */
	public function testExpressionMacros_checkFunctionArgument() {
		$this->assertEquals(self::SUBJECT_PREFIX.'3', self::$alert_response['result'][1]['subject']);
	}

	/**
	 * Test expression macro with {HOST.HOST} macro
	 */
	public function testExpressionMacros_checkHostMacro() {
		$this->assertEquals(self::MESSAGE_PREFIX.'3', self::$alert_response['result'][1]['message']);
	}

	/**
	 * Test expression macro in recovery message
	 */
	public function testExpressionMacros_checkRecoveryMessage() {
		$this->assertEquals(self::SUBJECT_PREFIX.'3', self::$alert_response['result'][2]['subject']);
		$this->assertEquals(self::MESSAGE_PREFIX.'2', self::$alert_response['result'][2]['message']);
	}

	/**
	 * Test expression macro in event name
	 */
	public function testExpressionMacros_checkEventName() {
		$this->assertEquals(self::EVENT_PREFIX.'2', self::$event_response['result'][0]['name']);
	}
}
