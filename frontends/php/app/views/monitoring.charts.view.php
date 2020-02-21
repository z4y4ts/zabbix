<?php
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
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

$this->addJsFile('multiselect.js');
$this->addJsFile('layout.mode.js');
$this->addJsFile('class.calendar.js');
$this->addJsFile('gtlc.js');

$this->enableLayoutModes();
$web_layout_mode = $this->getLayoutMode();

$widget = (new CWidget())
	->setTitle(_('Graphs'))
	->setWebLayoutMode($web_layout_mode)
	->setControls(new CList([
		(new CForm('get'))
			->cleanItems()
			->setAttribute('aria-label', _('Main filter'))
			->addItem((new CList())
				->addItem([
					new CLabel(_('View as'), 'action'),
					(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
					new CComboBox('action', $data['action'], 'submit()', [
						HISTORY_GRAPH => _('Graph'),
						HISTORY_VALUES => _('Values')
					])
				])
			),
		(new CTag('nav', true, (new CList())
			->addItem($data['filter_search_type'] == ZBX_SEARCH_TYPE_STRICT && count($data['graphids']) == 1
				? get_icon('favourite', [
					'fav' => 'web.favorite.graphids',
					'elname' => 'graphid',
					'elid' => $data['graphids'][0]
				])
				: null
			)
			->addItem(get_icon('fullscreen', ['mode' => $web_layout_mode]))
		))->setAttribute('aria-label', _('Content controls'))
	]));

$filter = (new CFilter((new CUrl('zabbix.php'))->setArgument('action', 'charts.view')))
	->setProfile($data['timeline']['profileIdx'], $data['timeline']['profileIdx2'])
	->setActiveTab($data['active_tab'])
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'],
		$web_layout_mode != ZBX_LAYOUT_KIOSKMODE
	)
	->addFormItem((new CVar('action', 'charts.view'))->removeId());

if (in_array($web_layout_mode, [ZBX_LAYOUT_NORMAL, ZBX_LAYOUT_FULLSCREEN])) {
	$filter->addFilterTab(_('Filter'), [
		(new CFormList())
			->addRow((new CLabel(_('Host'), 'filter_hostids__ms')),
				(new CMultiSelect([
					'multiple' => false,
					'name' => 'filter_hostids[]',
					'object_name' => 'host',
					'data' => $data['ms_hosts'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'hosts',
							'srcfld1' => 'hostid',
							'dstfrm' => 'zbx_filter',
							'dstfld1' => 'filter_hostids_',
							'with_graphs' => true
						]
					]
				]))
					->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			)
			->addRow((new CLabel(_('Search type'), 'waa')),
				(new CRadioButtonList('filter_search_type', $data['filter_search_type']))
					->addValue(_('Strict'), ZBX_SEARCH_TYPE_STRICT, null,
						'$("#ms_graph_patterns").addClass("'.ZBX_STYLE_DISPLAY_NONE.'");'.
						'$("#ms_graphids").removeClass("'.ZBX_STYLE_DISPLAY_NONE.'");'.
						'$("#filter_graphids_, #filter_graph_patterns_").multiSelect("clean")'
					)
					->addValue(_('Pattern'), ZBX_SEARCH_TYPE_PATTERN, null,
						'$("#ms_graph_patterns").removeClass("'.ZBX_STYLE_DISPLAY_NONE.'");'.
						'$("#ms_graphids").addClass("'.ZBX_STYLE_DISPLAY_NONE.'");'.
						'$("#filter_graphids_, #filter_graph_patterns_").multiSelect("clean")'
					)
					->setModern(true)
			)
			->addRow(
				(new CLabel(_('Graphs'), 'filter_graph__ms')),
				(new CMultiSelect([
					'multiple' => true,
					'name' => 'filter_graphids[]',
					'object_name' => 'graphs',
					'data' => $data['ms_graphs'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'graphs',
							'srcfld1' => 'graphid',
							'dstfrm' => 'zbx_filter',
							'dstfld1' => 'filter_graphids_',
							/* 'templated' => false */
						]
					]
				]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
				'ms_graphids',
				($data['filter_search_type'] == ZBX_SEARCH_TYPE_STRICT) ? '' : ZBX_STYLE_DISPLAY_NONE
			)
			->addRow(
				(new CLabel(_('Graphs'), 'filter_graph_patterns__ms')),
				(new CPatternSelect([
					'placeholder' => _('graph pattern'),
					'name' => 'filter_graph_patterns[]',
					'object_name' => 'graphs',
					'data' => $data['ms_graph_patterns'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'graphs',
							'srcfld1' => 'graphid',
							'dstfrm' => 'zbx_filter',
							'dstfld1' => 'filter_graph_patterns_',
							/* 'templated' => false */
						]
					],
					'add_post_js' => true
				]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
				'ms_graph_patterns',
				($data['filter_search_type'] == ZBX_SEARCH_TYPE_STRICT) ? ZBX_STYLE_DISPLAY_NONE : ''
			)
	]);
}

$widget->addItem($filter);

if ($data['must_specify_host']) {
	$widget->addItem((new CTableInfo())->setNoDataMessage(_('Specify host to see the graphs.')));
}
else if ($data['charts']) {
	$table = (new CTable())
		->setAttribute('style', 'width: 100%;');

	if ($data['action'] === HISTORY_VALUES) {
		$screen = CScreenBuilder::getScreen([
			'resourcetype' => SCREEN_RESOURCE_HISTORY,
			'action' => HISTORY_VALUES,
			/* 'graphid' => $data['graphid'], */
			'graphids' => $data['graphids'], // << TODO
			'pageFile' => (new CUrl('charts.php'))
				->setArgument('groupid', $data['groupid'])
				->setArgument('hostid', $data['hostid'])
				->setArgument('graphid', $data['graphid'])
				->setArgument('action', $data['action'])
				->getUrl(),
			'profileIdx' => $data['timeline']['profileIdx'],
			'profileIdx2' => $data['timeline']['profileIdx2'],
			'from' => $data['timeline']['from'],
			'to' => $data['timeline']['to'],
			'page' => $data['page']
		]);

		CScreenBuilder::insertScreenStandardJs($screen->timeline);

		$table->addRow($screen->get());
	}
	else {
		$table->setId('charts');

		$this->includeJsFile('monitoring.charts.view.js.php', [
			'charts' => $data['charts'],
			'timeline' => $data['timeline'],
			'config' => [
				'refresh_interval' => (int) CWebUser::getRefresh(),
				'refresh_list' => $data['filter_search_type'] == ZBX_SEARCH_TYPE_PATTERN,
				'filter_graph_patterns' => $data['filter_graph_patterns']
			]
		]);
	}

	$widget->addItem($table);
}
else {
	$widget->addItem(new CTableInfo());
}

$widget->show();
