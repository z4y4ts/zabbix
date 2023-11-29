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


require_once dirname(__FILE__).'/../common/testTriggerDependencies.php';

/**
 * @backup hosts
 *
 * @onBefore prepareTemplateTriggersData
 */
class testTemplateTriggerDependencies extends testTriggerDependencies {

	protected static $templateids;
	protected static $template_druleids;

	public function prepareTemplateTriggersData() {
		$template_result = CDataHelper::createTemplates([
			[
				'host' => 'Template with everything',
				'groups' => ['groupid' => 1],
				'items' => [
					[
						'name' => 'template item for everything',
						'key_' => 'everything',
						'type' => ITEM_TYPE_TRAPPER,
						'value_type' => ITEM_VALUE_TYPE_UINT64,
						'delay' => 0
					]
				],
				'discoveryrules' => [
					[
						'name' => 'Drule for everything',
						'key_' => 'everything_drule',
						'type' => ITEM_TYPE_TRAPPER,
						'delay' => 0
					]
				]
			],
			[
				'host' => 'Template that linked to host',
				'groups' => ['groupid' => 1],
				'items' => [
					[
						'name' => 'template item for linking',
						'key_' => 'everything_2',
						'type' => ITEM_TYPE_TRAPPER,
						'value_type' => ITEM_VALUE_TYPE_UINT64,
						'delay' => 0
					]
				],
				'discoveryrules' => [
					[
						'name' => 'Drule for linking',
						'key_' => 'linked_drule',
						'type' => ITEM_TYPE_TRAPPER,
						'delay' => 0
					]
				]
			],
			[
				'host' => 'Template with linked template',
				'groups' => ['groupid' => 1]
			],
			[
				'host' => 'Template that linked to template',
				'groups' => ['groupid' => 1],
				'items' => [
					[
						'name' => 'template item for template',
						'key_' => 'linked_temp',
						'type' => ITEM_TYPE_TRAPPER,
						'value_type' => ITEM_VALUE_TYPE_UINT64,
						'delay' => 0
					]
				],
				'discoveryrules' => [
					[
						'name' => 'Drule for template',
						'key_' => 'template_drule',
						'type' => ITEM_TYPE_TRAPPER,
						'delay' => 0
					]
				]
			]
		]);
		self::$templateids = $template_result['templateids'];
		self::$template_druleids = $template_result['discoveryruleids'];

		$response = CDataHelper::call('template.update', [
			[
				'templateid' => self::$templateids['Template with linked template'],
				'templates' => [
					[
						'templateid' => self::$templateids['Template that linked to template']
					]
				]
			],
			[
				'templateid' => self::$templateids['Template with everything'],
				'templates' => [
					[
						'templateid' => self::$templateids['Template that linked to template']
					]
				]
			]
		]);
		$this->assertArrayHasKey('templateids', $response);

		$template_triggers = CDataHelper::call('trigger.create', [
			[
				'description' => 'Template trigger update',
				'expression' => 'last(/Template with everything/everything)=0'
			],
			[
				'description' => 'trigger simple',
				'expression' => 'last(/Template with everything/everything)=0'
			],
			[
				'description' => 'trigger simple_2',
				'expression' => 'last(/Template with everything/everything)=0'
			],
			[
				'description' => 'Trigger that linked',
				'expression' => 'last(/Template that linked to host/everything_2)=0'
			],
			[
				'description' => 'trigger template linked',
				'expression' => 'last(/Template that linked to template/linked_temp)=0'
			],
			[
				'description' => 'trigger template linked update',
				'expression' => 'last(/Template that linked to template/linked_temp)=0'
			],
			[
				'description' => 'Template that depends on trigger',
				'expression' => 'last(/Template with everything/everything)=0'
			],
			[
				'description' => 'trigger that depends on linked trigger',
				'expression' => 'last(/Template that linked to template/linked_temp)=0'
			]
		]);
		$this->assertArrayHasKey('triggerids', $template_triggers);
		$template_triggerid = CDataHelper::getIds('description');

		CDataHelper::call('trigger.update', [
			[
				'triggerid' => $template_triggerid['Template that depends on trigger'],
				'dependencies' => [['triggerid' => $template_triggerid['Template trigger update']]]
			],
			[
				'triggerid' => $template_triggerid['trigger that depends on linked trigger'],
				'dependencies' => [['triggerid' => $template_triggerid['trigger template linked update']]]
			]
		]);

		$item_prototype = CDataHelper::call('itemprototype.create', [
			[
				'name' => 'Item prot with everything',
				'key_' => 'everything_prot_[{#KEY}]',
				'hostid' => self::$templateids['Template with everything'],
				'ruleid' => self::$template_druleids['Template with everything:everything_drule'],
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'delay' => 0
			],
			[
				'name' => 'Item prot for template',
				'key_' => 'template_prot_[{#KEY}]',
				'hostid' => self::$templateids['Template that linked to template'],
				'ruleid' => self::$template_druleids['Template that linked to template:template_drule'],
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'delay' => 0
			]
		]);
		$this->assertArrayHasKey('itemids', $item_prototype);

		$trigger_prototype = CDataHelper::call('triggerprototype.create', [
			[
				'description' => 'Template trigger prototype update{#KEY}',
				'expression' => 'last(/Template with everything/everything_prot_[{#KEY}])=0'
			],
			[
				'description' => 'trigger prototype simple{#KEY}',
				'expression' => 'last(/Template with everything/everything_prot_[{#KEY}])=0'
			],
			[
				'description' => 'trigger prototype template{#KEY}',
				'expression' => 'last(/Template that linked to template/template_prot_[{#KEY}])=0'
			],
			[
				'description' => 'trigger prototype template update{#KEY}',
				'expression' => 'last(/Template that linked to template/template_prot_[{#KEY}])=0'
			]
		]);
		$this->assertArrayHasKey('triggerids', $trigger_prototype);

		$host_result = CDataHelper::createHosts([
			[
				'host' => 'Host with everything',
				'templates' => ['templateid' => self::$templateids['Template that linked to host']],
				'groups' => [['groupid' => 4]],
				'items' => [
					[
						'name' => 'Host item 1',
						'key_' => 'host_item_1',
						'type' => ITEM_TYPE_TRAPPER,
						'value_type' => ITEM_VALUE_TYPE_UINT64,
						'delay' => 0
					]
				],
				'discoveryrules' => [
					[

						'name' => 'Drule for host everything',
						'key_' => 'host_everything_drule',
						'type' => ITEM_TYPE_TRAPPER,
						'delay' => 0
					]
				]
			]
		]);
		$hostids = $host_result['hostids'];
		$host_druleids = $host_result['discoveryruleids'];

		$host_triggers = CDataHelper::call('trigger.create', [
			[
				'description' => 'Host trigger everything',
				'expression' => 'last(/Host with everything/host_item_1)=0'
			],
			[
				'description' => 'Host trigger everything 2',
				'expression' => 'last(/Host with everything/host_item_1)=0'
			]
		]);
		$this->assertArrayHasKey('triggerids', $host_triggers);

		$host_item_prototype = CDataHelper::call('itemprototype.create', [
			[
				'name' => 'Host Item prot with everything',
				'key_' => 'host_everything_prot_[{#KEY}]',
				'hostid' => $hostids['Host with everything'],
				'ruleid' => $host_druleids['Host with everything:host_everything_drule'],
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_UINT64,
				'delay' => 0
			]
		]);
		$this->assertArrayHasKey('itemids', $host_item_prototype);
	}

	public static function getTriggerCreateBadData() {
		return [
			// #0 dependencies on parent templates trigger.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Depends on linked trigger',
					'dependencies' => [
						'Template that linked to template' => ['trigger template linked']
					],
					'error_message' => 'Trigger "Depends on linked trigger" cannot depend on the trigger "trigger'.
							' template linked" from the template "Template that linked to template", because dependencies'.
							' on triggers from the parent template are not allowed.'
				]
			]
		];
	}

	public static function getTriggerCreateData() {
		return [
			// #0 dependencies on another trigger on same template.
			[
				[
					'name' => 'Simple template trigger',
					'dependencies' => [
						'Template with everything' => ['trigger simple']
					],
					'result' => [
						'Template with everything: trigger simple'
					]
				]
			],
			// #1 dependencies on 2 triggers from same template.
			[
				[
					'name' => 'Two trigger dependencies',
					'dependencies' => [
						'Template with everything' => ['trigger simple', 'trigger simple_2']
					],
					'result' => [
						'Template with everything: trigger simple',
						'Template with everything: trigger simple_2'
					]
				]
			],
			// #2 dependencies on trigger from another template.
			[
				[
					'name' => 'Triggers from another template',
					'dependencies' => [
						'Template that linked to host' => ['Trigger that linked']
					],
					'result' => [
						'Template that linked to host: Trigger that linked'
					]
				]
			],
			// #3 dependencies on trigger from another and same template.
			[
				[
					'name' => 'Two triggers from different',
					'dependencies' => [
						'Template that linked to host' => ['Trigger that linked'],
						'Template with everything' => ['trigger simple']
					],
					'result' => [
						'Template that linked to host: Trigger that linked',
						'Template with everything: trigger simple'
					]
				]
			],
			// #4 dependencies on hosts trigger.
			[
				[
					'name' => 'Depends on hosts trigger',
					'host_dependencies' => [
						'Host with everything' => ['Host trigger everything']
					],
					'result' => [
						'Host with everything: Host trigger everything'
					]
				]
			],
			// #5 dependencies on two hosts trigger.
			[
				[
					'name' => 'Depends on two hosts trigger',
					'host_dependencies' => [
						'Host with everything' => ['Host trigger everything', 'Host trigger everything 2']
					],
					'result' => [
						'Host with everything: Host trigger everything',
						'Host with everything: Host trigger everything 2'
					]
				]
			],
			// #6 dependencies on linked trigger from another template.
			[
				[
					'name' => 'Depends on trigger that linked from another template',
					'dependencies' => [
						'Template with linked template' => ['trigger template linked']
					],
					'result' => [
						'Template with linked template: trigger template linked'
					]
				]
			],
			// #7 dependencies on trigger from template and trigger from host.
			[
				[
					'name' => 'Depends on trigger from template and host',
					'host_dependencies' => [
						'Host with everything' => ['Host trigger everything']
					],
					'dependencies' => [
						'Template with everything' => ['trigger simple']
					],
					'result' => [
						'Host with everything: Host trigger everything',
						'Template with everything: trigger simple'
					]
				]
			],
			// #8 dependencies on trigger that linked to this template.
			[
				[
					'name' => 'Depends on trigger that linked to this template',
					'dependencies' => [
						'Template with everything' => ['trigger template linked']
					],
					'result' => [
						'Template with everything: trigger template linked'
					]
				]
			]
		];
	}

	/**
	 * Create trigger on template with dependencies.
	 *
	 * @dataProvider getTriggerCreateBadData
	 * @dataProvider getTriggerCreateData
	 */
	public function testTemplateTriggerDependencies_TriggerCreate($data) {
		$this->page->login()->open('zabbix.php?action=trigger.list&filter_set=1&filter_hostids%5B0%5D='.
				self::$templateids['Template with everything'].'&context=template'
		)->waitUntilReady();
		$this->query('button:Create trigger')->one()->click();
		$this->page->waitUntilReady();

		// Creating new template trigger - expression is mandatory.
		$this->triggerCreateUpdate($data, 'Trigger added', 'last(/Template with everything/everything)=0',
				'Cannot add trigger'
		);
	}

	public static function getTriggerUpdateData() {
		return [
			// #0 dependencies on itself.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Template trigger update',
					'dependencies' => [
						'Template with everything' => ['Template trigger update']
					],
					'error_message' => 'Trigger "Template trigger update" cannot depend on the trigger "Template trigger update",'.
							' because a circular linkage ("Template trigger update" -> "Template trigger update") would occur.'
				]
			],
			// #1 dependencies on parent templates trigger.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Depends on linked trigger',
					'dependencies' => [
						'Template that linked to template' => ['trigger template linked']
					],
					'error_message' => 'Trigger "Template trigger update" cannot depend on the trigger "trigger'.
							' template linked" from the template "Template that linked to template", because dependencies'.
							' on triggers from the parent template are not allowed.'
				]
			],
			// #2 dependencies on dependent trigger.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Depends on dependent trigger',
					'dependencies' => [
						'Template with everything' => ['Template that depends on trigger']
					],
					'error_message' => 'Trigger "Template trigger update" cannot depend on the trigger "Template that'.
							' depends on trigger", because a circular linkage ("Template that depends on trigger" ->'.
							' "Template trigger update" -> "Template that depends on trigger") would occur.'
				]
			]
		];
	}

	/**
	 * Update trigger on template with dependencies.
	 *
	 * @dataProvider getTriggerUpdateData
	 * @dataProvider getTriggerCreateData
	 */
	public function testTemplateTriggerDependencies_TriggerUpdate($data) {
		$this->page->login()->open('zabbix.php?action=trigger.list&filter_set=1&filter_hostids%5B0%5D='.
				self::$templateids['Template with everything'].'&context=template'
		)->waitUntilReady();
		$this->query('link:Template trigger update')->one()->click();
		$this->page->waitUntilReady();
		$this->triggerCreateUpdate($data, 'Trigger updated', null, 'Cannot update trigger', 'Template trigger update');
	}

	public static function getLinkedTriggerUpdateData() {
		return [
			// #0 dependencies on itself.
			[
				[
					'expected' => TEST_BAD,
					'dependencies' => [
						'Template with everything' => ['trigger template linked update']
					],
					'error_message' => 'Trigger "trigger template linked update" cannot depend on the trigger'.
							' "trigger template linked update", because a circular linkage ("trigger template'.
							' linked update" -> "trigger template linked update") would occur.'
				]
			],
			// #1 depends on linked trigger that already depends on this trigger.
			[
				[
					'expected' => TEST_BAD,
					'dependencies' => [
						'Template with everything' => ['trigger that depends on linked trigger']
					],
					'error_message' => 'Trigger "trigger template linked update" cannot depend on the trigger'.
						' "trigger that depends on linked trigger", because a circular linkage ("trigger that depends'.
						' on linked trigger" -> "trigger template linked update" -> "trigger that depends on linked'.
						' trigger") would occur.'
				]
			],
			// #2 dependencies on parent templates trigger.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Depends on linked trigger',
					'dependencies' => [
						'Template that linked to template' => ['trigger template linked']
					],
					'error_message' => 'Trigger "trigger template linked update" cannot depend on the trigger "trigger'.
						' template linked" from the template "Template that linked to template", because dependencies'.
						' on triggers from the parent template are not allowed.'
				]
			]
		];
	}

	/**
	 * Update linked trigger on template with dependencies.
	 *
	 * @dataProvider getLinkedTriggerUpdateData
	 * @dataProvider getTriggerCreateData
	 */
	public function testTemplateTriggerDependencies_LinkedTriggerUpdate($data) {
		$this->page->login()->open('zabbix.php?action=trigger.list&filter_set=1&filter_hostids%5B0%5D='.
				self::$templateids['Template with everything'].'&context=template'
		)->waitUntilReady();
		$this->query('link:trigger template linked update')->one()->click();
		$this->page->waitUntilReady();
		$this->triggerCreateUpdate($data, 'Trigger updated', null, 'Cannot update trigger', 'trigger template linked update');
	}

	public static function getTriggerPrototypeCreateData() {
		return [
			// #0 dependencies on trigger from template, host and trigger prototype.
			[
				[
					'name' => 'Depends on trigger, hosts trigger and prototype_{#KEY}',
					'host_dependencies' => [
						'Host with everything' => ['Host trigger everything']
					],
					'dependencies' => [
						'Template with everything' => ['trigger simple']
					],
					'prototype_dependencies' => [
						'trigger prototype simple{#KEY}'
					],
					'result' => [
						'Host with everything: Host trigger everything',
						'Template with everything: trigger prototype simple{#KEY}',
						'Template with everything: trigger simple'
					]
				]
			],
			// #1 dependencies on prototype only.
			[
				[
					'name' => 'Depends on prototype_{#KEY}',
					'prototype_dependencies' => [
						'trigger prototype simple{#KEY}'
					],
					'result' => [
						'Template with everything: trigger prototype simple{#KEY}'
					]
				]
			]
		];
	}

	/**
	 * Create trigger prototype on template with dependencies.
	 *
	 * @dataProvider getTriggerCreateData
	 * @dataProvider getTriggerPrototypeCreateData
	 */
	public function testTemplateTriggerDependencies_TriggerPrototypeCreate($data) {
		$this->page->login()->open('zabbix.php?action=trigger.prototype.list&parent_discoveryid='.
				self::$template_druleids['Template with everything:everything_drule'].'&context=template'
		)->waitUntilReady();
		$this->query('button:Create trigger prototype')->one()->click();
		$this->page->waitUntilReady();

		// Creating new template trigger prototype - expression is mandatory.
		$this->triggerCreateUpdate($data, 'Trigger prototype added',
				'last(/Template with everything/everything_prot_[{#KEY}])=0'
		);
	}

	public static function getTriggerPrototypeUpdateData() {
		return [
			// #0 dependencies on itself.
			[
				[
					'expected' => TEST_BAD,
					'name' => 'Depends on trigger, hosts trigger and prototype_{#KEY}',
					'prototype_dependencies' => [
						'Template trigger prototype update{#KEY}'
					],
					'error_message' => 'Trigger prototype "Template trigger prototype update{#KEY}" cannot depend on the trigger'.
							' prototype "Template trigger prototype update{#KEY}", because a circular linkage ("Template'.
							' trigger prototype update{#KEY}" -> "Template trigger prototype update{#KEY}") would occur.'
				]
			]
		];
	}

	/**
	 * Update trigger prototype on template with dependencies.
	 *
	 * @dataProvider getTriggerPrototypeUpdateData
	 * @dataProvider getTriggerCreateData
	 * @dataProvider getTriggerPrototypeCreateData
	 */
	public function testTemplateTriggerDependencies_TriggerPrototypeUpdate($data) {
		$this->page->login()->open('zabbix.php?action=trigger.prototype.list&parent_discoveryid='.
				self::$template_druleids['Template with everything:everything_drule'].'&context=template'
		)->waitUntilReady();
		$this->query('link:Template trigger prototype update{#KEY}')->one()->click();
		$this->page->waitUntilReady();
		$this->triggerCreateUpdate($data, 'Trigger prototype updated', null, 'Cannot update trigger prototype',
				'Template trigger prototype update{#KEY}'
		);
	}

	public static function getLinkedTriggerPrototypeUpdateData() {
		return [
			// #0 dependencies on itself.
			[
				[
					'expected' => TEST_BAD,
					'prototype_dependencies' => [
						'trigger prototype template update{#KEY}'
					],
					'error_message' => 'Trigger prototype "trigger prototype template update{#KEY}" cannot depend on the trigger'.
							' prototype "trigger prototype template update{#KEY}", because a circular linkage'.
							' ("trigger prototype template update{#KEY}" -> "trigger prototype template update{#KEY}") would occur.'
				]
			],
			// #1 depends on trigger, host trigger, prototype trigger.
			[
				[
					'host_dependencies' => [
						'Host with everything' => ['Host trigger everything']
					],
					'dependencies' => [
						'Template with everything' => ['trigger simple']
					],
					'prototype_dependencies' => [
						'trigger prototype template{#KEY}'
					],
					'result' => [
						'Host with everything: Host trigger everything',
						'Template with everything: trigger prototype template{#KEY}',
						'Template with everything: trigger simple'
					]
				]
			],
			// #2 dependencies on prototype only.
			[
				[
					'prototype_dependencies' => [
						'trigger prototype template{#KEY}'
					],
					'result' => [
						'Template with everything: trigger prototype template{#KEY}'
					]
				]
			]
		];
	}

	/**
	 * Update linked trigger prototype on template with dependencies.
	 *
	 * @dataProvider getLinkedTriggerPrototypeUpdateData
	 * @dataProvider getTriggerCreateData
	 */
	public function testTemplateTriggerDependencies_LinkedTriggerPrototypeUpdate($data) {
		$this->page->login()->open('host_discovery.php?filter_set=1&filter_hostids%5B0%5D='.
				self::$templateids['Template with everything'].'&context=template'
		)->waitUntilReady();
		$this->query('class:list-table')->asTable()->one()->findRow('Name', 'Template that linked to template: Drule for template')
				->query('link:Trigger prototypes')->waitUntilClickable()->one()->click();
		$this->page->waitUntilReady();
		$this->query('link:trigger prototype template update{#KEY}')->one()->click();
		$this->triggerCreateUpdate($data, 'Trigger prototype updated', null, 'Cannot update trigger prototype',
				'trigger prototype template update{#KEY}'
		);
	}
}
