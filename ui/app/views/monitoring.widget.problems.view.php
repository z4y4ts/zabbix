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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


/**
 * @var CView $this
 * @var array $data
 */

// indicator of sort field
$sort_div = (new CSpan())->addClass(($data['sortorder'] === ZBX_SORT_DOWN) ? ZBX_STYLE_ARROW_DOWN : ZBX_STYLE_ARROW_UP);

$show_timeline = ($data['sortfield'] === 'clock' && $data['fields']['show_timeline']);
$show_recovery_data = in_array($data['fields']['show'], [TRIGGERS_OPTION_RECENT_PROBLEM, TRIGGERS_OPTION_ALL]);

$header_time = new CColHeader(($data['sortfield'] === 'clock')
	? [_x('Time', 'compact table header'), $sort_div]
	: _x('Time', 'compact table header'));

$header = [];

if ($data['do_causes_have_symptoms']) {
	$header[] = (new CColHeader())->addClass(ZBX_STYLE_CELL_WIDTH);
	$header[] = (new CColHeader())->addClass(ZBX_STYLE_CELL_WIDTH);
}
elseif ($data['has_symptoms']) {
	$header[] = (new CColHeader())->addClass(ZBX_STYLE_CELL_WIDTH);
}

if ($show_timeline) {
	$header[] = $header_time->addClass(ZBX_STYLE_RIGHT);
	$header[] = (new CColHeader())->addClass(ZBX_STYLE_TIMELINE_TH);
	$header[] = (new CColHeader())->addClass(ZBX_STYLE_TIMELINE_TH);
}
else {
	$header[] = $header_time;
}

$table = (new CTableInfo())
	->setHeader(array_merge($header, [
		$show_recovery_data
			? _x('Recovery time', 'compact table header')
			: null,
		$show_recovery_data
			? _x('Status', 'compact table header')
			: null,
		_x('Info', 'compact table header'),
		($data['sortfield'] === 'host')
			? [_x('Host', 'compact table header'), $sort_div]
			: _x('Host', 'compact table header'),
		[
			($data['sortfield'] === 'name')
				? [_x('Problem', 'compact table header'), $sort_div]
				: _x('Problem', 'compact table header'),
			' &bullet; ',
			($data['sortfield'] === 'severity')
				? [_x('Severity', 'compact table header'), $sort_div]
				: _x('Severity', 'compact table header')
		],
		($data['fields']['show_opdata'] == OPERATIONAL_DATA_SHOW_SEPARATELY)
			? _x('Operational data', 'compact table header')
			: null,
			_x('Duration', 'compact table header'),
			_x('Ack', 'compact table header'),
			_x('Actions', 'compact table header'),
		$data['fields']['show_tags'] ? _x('Tags', 'compact table header') : null
	]));

$data['triggers_hosts'] = $data['problems'] ? makeTriggersHostsList($data['triggers_hosts']) : [];

$data += [
	'today' => strtotime('today'),
	'show_timeline' => $show_timeline,
	'last_clock' => 0,
	'show_recovery_data' => $show_recovery_data
];

$table = addProblemsToTable($table, $data['problems'], $data);

if ($data['info'] !== '') {
	$table->setFooter([
		(new CCol($data['info']))
			->setColSpan($table->getNumCols())
			->addClass(ZBX_STYLE_LIST_TABLE_FOOTER)
	]);
}

$output = [
	'name' => $data['name'],
	'body' => $table->toString()
];

if ($messages = get_and_clear_messages()) {
	$output['messages'] = array_column($messages, 'message');
}

if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

echo json_encode($output);

/**
 * Add problems and symtoms to table.
 *
 * @param CTableInfo $table                                 Table object to which problems are added to.
 * @param array      $problems                              List of problems.
 * @param array      $data                                  Additional data to build the table.
 * @param array      $data['triggers']                      List of triggers.
 * @param int        $data['today']                         Timestamp of today's date.
 * @param array      $data['tasks']                         List of tasks. Used to determine current problem status.
 * @param array      $data['users']                         List of users.
 * @param array      $data['correlations']                  List of correlations.
 * @param array      $data['fields']                        Problem widget filter fields.
 * @param int        $data['fields']['show_tags']           "Show tags" filter option.
 * @param int        $data['fields']['show_opdata']         "Show operational data" filter option.
 * @param array      $data['fields']['tags']                "Tags" filter.
 * @param int        $data['fields']['tag_name_format']     "Tag name" filter.
 * @param string     $data['fields']['tag_priority']        "Tag display priority" filter.
 * @param bool       $data['show_timeline']                 "Show timeline" filter option.
 * @param int        $data['do_causes_have_symptoms']       True if cause problems have symptoms.
 * @param int        $data['last_clock']                    Problem time. Used to show timeline breaks.
 * @param int        $data['sortorder']                     Sort problems in ascending or descending order.
 * @param array      $data['allowed']                       An array of user role rules.
 * @param bool       $data['allowed']['ui_problems']        Whether user is allowed to access problem view.
 * @param bool       $data['allowed']['close']              Whether user is allowed to close problems.
 * @param bool       $data['allowed']['add_comments']       Whether user is allowed to add problems comments.
 * @param bool       $data['allowed']['change_severity']    Whether user is allowed to change problems severity.
 * @param bool       $data['allowed']['acknowledge']        Whether user is allowed to acknowledge problems.
 * @param bool       $data['allowed']['suppress_problems']  Whether user is allowed to manually suppress/unsuppress
 *                                                          problems.
 * @param bool       $data['allowed']['rank_change']        Whether user is allowed to change problem ranking.
 * @param bool       $data['show_recovery_data']            True if filter "Show" option is "Recent problems"
 *                                                          or History.
 * @param array      $data['triggers_hosts']                List of trigger hosts.
 * @param array      $data['actions']                       List of actions.
 * @param array      $data['tags']                          List of tags.
 * @param bool       $nested                                If true, show the symptom rows with indentation.
 *
 * @return CTableInfo
 */
function addProblemsToTable(CTableInfo $table, array $problems, array $data, $nested = false): CTableInfo {
	foreach ($problems as $problem) {
		$trigger = $data['triggers'][$problem['objectid']];

		$cell_clock = ($problem['clock'] >= $data['today'])
			? zbx_date2str(TIME_FORMAT_SECONDS, $problem['clock'])
			: zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']);

		if ($data['allowed']['ui_problems']) {
			$cell_clock = new CCol(new CLink($cell_clock,
				(new CUrl('tr_events.php'))
					->setArgument('triggerid', $problem['objectid'])
					->setArgument('eventid', $problem['eventid'])
			));
		}
		else {
			$cell_clock = new CCol($cell_clock);
		}

		if ($problem['r_eventid'] != 0) {
			$cell_r_clock = ($problem['r_clock'] >= $data['today'])
				? zbx_date2str(TIME_FORMAT_SECONDS, $problem['r_clock'])
				: zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['r_clock']);

			if ($data['allowed']['ui_problems']) {
				$cell_r_clock = new CCol(new CLink($cell_r_clock,
					(new CUrl('tr_events.php'))
						->setArgument('triggerid', $problem['objectid'])
						->setArgument('eventid', $problem['eventid'])
				));
			}
			else {
				$cell_r_clock = new CCol($cell_r_clock);
			}

			$cell_r_clock
				->addClass(ZBX_STYLE_NOWRAP)
				->addClass(ZBX_STYLE_RIGHT);
		}
		else {
			$cell_r_clock = '';
		}

		$in_closing = false;

		if ($problem['r_eventid'] != 0) {
			$value = TRIGGER_VALUE_FALSE;
			$value_clock = $problem['r_clock'];
			$can_be_closed = false;
		}
		else {
			$in_closing = hasEventCloseAction($problem['acknowledges']);
			$can_be_closed = ($trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED && $data['allowed']['close']
				&& !$in_closing
			);
			$value = $in_closing ? TRIGGER_VALUE_FALSE : TRIGGER_VALUE_TRUE;
			$value_clock = $in_closing ? time() : $problem['clock'];
		}

		$value_str = getEventStatusString($in_closing, $problem, $data['tasks']);
		$is_acknowledged = ($problem['acknowledged'] == EVENT_ACKNOWLEDGED);
		$cell_status = new CSpan($value_str);

		// Add colors and blinking to span depending on configuration and trigger parameters.
		addTriggerValueStyle($cell_status, $value, $value_clock, $is_acknowledged);

		// Info.
		$info_icons = [];
		if ($problem['r_eventid'] != 0) {
			if ($problem['correlationid'] != 0) {
				$info_icons[] = makeInformationIcon(
					array_key_exists($problem['correlationid'], $data['correlations'])
						? _s('Resolved by correlation rule "%1$s".',
							$data['correlations'][$problem['correlationid']]['name']
						)
						: _('Resolved by correlation rule.')
				);
			}
			elseif ($problem['userid'] != 0) {
				$info_icons[] = makeInformationIcon(
					array_key_exists($problem['userid'], $data['users'])
						? _s('Resolved by user "%1$s".', getUserFullname($data['users'][$problem['userid']]))
						: _('Resolved by inaccessible user.')
				);
			}
		}

		if (array_key_exists('suppression_data', $problem)) {
			if (count($problem['suppression_data']) == 1
					&& $problem['suppression_data'][0]['maintenanceid'] == 0
					&& isEventRecentlyUnsuppressed($problem['acknowledges'], $unsuppression_action)) {
				// Show blinking button if the last manual suppression was recently revoked.
				$user_unsuppressed = array_key_exists($unsuppression_action['userid'], $data['users'])
					? getUserFullname($data['users'][$unsuppression_action['userid']])
					: _('Inaccessible user');

				$info_icons[] = (new CSimpleButton())
					->addClass(ZBX_STYLE_ACTION_ICON_UNSUPPRESS)
					->addClass('blink')
					->setHint(_s('Unsuppressed by: %1$s', $user_unsuppressed));
			}
			elseif ($problem['suppression_data']) {
				$info_icons[] = makeSuppressedProblemIcon($problem['suppression_data'], false);
			}
			elseif (isEventRecentlySuppressed($problem['acknowledges'], $suppression_action)) {
				// Show blinking button if suppression was made but is not yet processed by server.
				$info_icons[] = makeSuppressedProblemIcon([[
					'suppress_until' => $suppression_action['suppress_until'],
					'username' => array_key_exists($suppression_action['userid'], $data['users'])
						? getUserFullname($data['users'][$suppression_action['userid']])
						: _('Inaccessible user')
				]], true);
			}
		}

		$opdata = null;
		if ($data['fields']['show_opdata'] != OPERATIONAL_DATA_SHOW_NONE) {

			// operational data
			if ($trigger['opdata'] === '') {
				if ($data['fields']['show_opdata'] == OPERATIONAL_DATA_SHOW_SEPARATELY) {
					$opdata = (new CCol(CScreenProblem::getLatestValues($trigger['items'])))->addClass('latest-values');
				}
			}
			else {
				$opdata = CMacrosResolverHelper::resolveTriggerOpdata(
					[
						'triggerid' => $trigger['triggerid'],
						'expression' => $trigger['expression'],
						'opdata' => $trigger['opdata'],
						'clock' => ($problem['r_eventid'] != 0) ? $problem['r_clock'] : $problem['clock'],
						'ns' => ($problem['r_eventid'] != 0) ? $problem['r_ns'] : $problem['ns']
					],
					[
						'events' => true,
						'html' => true
					]
				);

				if ($data['fields']['show_opdata'] == OPERATIONAL_DATA_SHOW_SEPARATELY) {
					$opdata = (new CCol($opdata))->addClass('opdata');
				}
			}
		}

		$problem_link = [
			(new CLinkAction($problem['name']))
				->setMenuPopup(CMenuPopupHelper::getTrigger($trigger['triggerid'], $problem['eventid'],
					['show_rank_change_cause' => true]
				))
				->setAttribute('aria-label', _xs('%1$s, Severity, %2$s', 'screen reader',
					$problem['name'], CSeverityHelper::getName((int) $problem['severity'])
				))
		];

		if ($data['fields']['show_opdata'] == OPERATIONAL_DATA_SHOW_WITH_PROBLEM && $opdata) {
			$problem_link = array_merge($problem_link, [' (', $opdata, ')']);
		}

		$description = (new CCol($problem_link))->addClass(ZBX_STYLE_WORDBREAK);
		$description_style = CSeverityHelper::getStyle((int) $problem['severity']);

		if ($value == TRIGGER_VALUE_TRUE) {
			$description->addClass($description_style);
		}

		if (!$data['show_recovery_data']
				&& (($is_acknowledged && $data['config']['problem_ack_style'])
					|| (!$is_acknowledged && $data['config']['problem_unack_style']))) {
			// blinking
			$duration = time() - $problem['clock'];
			$blink_period = timeUnitToSeconds($data['config']['blink_period']);

			if ($blink_period != 0 && $duration < $blink_period) {
				$description
					->addClass('blink')
					->setAttribute('data-time-to-blink', $blink_period - $duration)
					->setAttribute('data-toggle-class', ZBX_STYLE_BLINK_HIDDEN);
			}
		}

		if ($problem['cause_eventid'] == 0) {
			$row = new CRow();

			if ($problem['symptom_count'] > 0) {
				$row->addItem((new CSpan($problem['symptom_count']))->addClass(ZBX_STYLE_TAG));

				$icon = (new CButton(null))
					->setAttribute('data-eventid', $problem['eventid'])
					->setAttribute('data-action', 'show_symptoms')
					->addClass(ZBX_STYLE_BTN_WIDGET_EXPAND)
					->setTitle(_('Expand'));

				$row->addItem($icon);
			}
			else {
				if ($data['do_causes_have_symptoms']) {
					// Show two empty columns.
					$row->addItem([new CCol(''), new CCol('')]);
				}
			}
		}
		else {
			if ($nested) {
				// First and second column empty for symptom event.
				$row = (new CRow(['']))
					->setAttribute('data-cause-eventid', $problem['cause_eventid'])
					->addClass('nested')
					->addStyle('display: none');
			}
			else {
				// First column empty stand-alone symptom event.
				$row = new CRow();
			}

			// Next column symptom icon.
			$row->addItem(new CCol(
				makeActionIcon(['icon' => ZBX_STYLE_ACTION_ICON_SYMPTOM, 'title' => _('Symptom')])
			));

			// If there are causes as well, show additional empty column.
			if (!$nested && $data['do_causes_have_symptoms']) {
				$row->addItem(new CCol(''));
			}
		}

		if ($data['show_timeline']) {
			if ($data['last_clock'] != 0) {
				CScreenProblem::addTimelineBreakpoint($table, $data['last_clock'], $problem['clock'],
					$data['sortorder']
				);
			}
			$data['last_clock'] = $problem['clock'];

			$row->addItem([
				$cell_clock->addClass(ZBX_STYLE_TIMELINE_DATE),
				(new CCol())
					->addClass(ZBX_STYLE_TIMELINE_AXIS)
					->addClass(ZBX_STYLE_TIMELINE_DOT),
				(new CCol())->addClass(ZBX_STYLE_TIMELINE_TD)
			]);
		}
		else {
			$row->addItem($cell_clock
					->addClass(ZBX_STYLE_NOWRAP)
					->addClass(ZBX_STYLE_RIGHT)
			);
		}

		// Create acknowledge link.
		$problem_update_link = ($data['allowed']['add_comments'] || $data['allowed']['change_severity']
				|| $data['allowed']['acknowledge'] || $can_be_closed || $data['allowed']['suppress_problems']
				|| $data['allowed']['rank_change'])
			? (new CLink($is_acknowledged ? _('Yes') : _('No')))
				->addClass($is_acknowledged ? ZBX_STYLE_GREEN : ZBX_STYLE_RED)
				->addClass(ZBX_STYLE_LINK_ALT)
				->setAttribute('data-eventid', $problem['eventid'])
				->onClick('acknowledgePopUp({eventids: [this.dataset.eventid]}, this);')
			: (new CSpan($is_acknowledged ? _('Yes') : _('No')))->addClass(
				$is_acknowledged ? ZBX_STYLE_GREEN : ZBX_STYLE_RED
			);

		$row->addItem([
			$data['show_recovery_data'] ? $cell_r_clock : null,
			$data['show_recovery_data'] ? $cell_status : null,
			makeInformationList($info_icons),
			$data['triggers_hosts'][$trigger['triggerid']],
			$description,
			($data['fields']['show_opdata'] == OPERATIONAL_DATA_SHOW_SEPARATELY)
				? $opdata->addClass(ZBX_STYLE_WORDBREAK)
				: null,
			(new CCol(
				(new CLinkAction(zbx_date2age($problem['clock'], ($problem['r_eventid'] != 0)
					? $problem['r_clock']
					: 0
				)))
					->setAjaxHint(CHintBoxHelper::getEventList($trigger['triggerid'], $problem['eventid'],
						$data['show_timeline'], $data['fields']['show_tags'], $data['fields']['tags'],
						$data['fields']['tag_name_format'], $data['fields']['tag_priority']
					))
			))->addClass(ZBX_STYLE_NOWRAP),
			$problem_update_link,
			makeEventActionsIcons($problem['eventid'], $data['actions'], $data['users']),
			$data['fields']['show_tags'] ? $data['tags'][$problem['eventid']] : null
		]);

		$table->addRow($row);

		if ($problem['cause_eventid'] == 0 && $problem['symptoms']) {
			$table = addProblemsToTable($table, $problem['symptoms'], $data, true);

			if ($problem['symptom_count'] > ZBX_PROBLEM_SYMPTOM_LIMIT) {
				$table->addRow(
					(new CRow(
						(new CCol(
							(new CDiv(
								(new CDiv(_s('Displaying %1$s of %2$s found', ZBX_PROBLEM_SYMPTOM_LIMIT,
									$problem['symptom_count']
								)))->addClass(ZBX_STYLE_TABLE_STATS)
							))->addClass(ZBX_STYLE_PAGING_BTN_CONTAINER)
						))->setColSpan($table->getNumCols())
					))->addClass('no-hover')
				);
			}
		}
	}

	return $table;
}
