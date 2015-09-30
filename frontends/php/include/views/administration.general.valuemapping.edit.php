<?php
/*
** Zabbix
** Copyright (C) 2001-2015 Zabbix SIA
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


include('include/views/js/administration.general.valuemapping.edit.js.php');

$widget = (new CWidget())
	->setTitle(_('Value mapping'))
	->setControls((new CForm())
		->cleanItems()
		->addItem((new CList())->addItem(makeAdministrationGeneralMenu('adm.valuemapping.php')))
	);

$form = (new CForm())
	->setName('valuemap_form')
	->addVar('form', $data['form']);

if ($data['valuemapid'] != 0) {
	$form->addVar('valuemapid', $data['valuemapid']);
}

$form_list = (new CFormList())
	->addRow(_('Name'),
		(new CTextBox('name', $data['name'], false, 64))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('autofocus', 'autofocus')
	);

$mappings_table = (new CTable())
	->setId('mappings_table')
	->setHeader([_('Value'), '', _('Mapped to'), _('Action')])
	->setAttribute('style', 'width: 100%;');

foreach ($data['mappings'] as $i => $mapping) {
	$mappings_table->addRow([
		(new CTextBox('mappings['.$i.'][value]', $mapping['value'], false, 64))
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH),
		'&rArr;',
		(new CTextBox('mappings['.$i.'][newvalue]', $mapping['newvalue'], false, 64))
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH),
		(new CButton('mappings['.$i.'][remove]', _('Remove')))
			->addClass(ZBX_STYLE_BTN_LINK)
			->addClass('element-table-remove')
		],
		'form_row'
	);
}

$mappings_table->addRow([(new CButton('mapping_add', _('Add')))
	->addClass(ZBX_STYLE_BTN_LINK)
	->addClass('element-table-add')]);

$form_list->addRow(_('Mappings'),
	(new CDiv($mappings_table))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_STANDARD_WIDTH.'px;'),
	'row_mappings'
);

// append form list to tab
$tab = (new CTabView())->addTab('valuemap_tab', _('Value mapping'), $form_list);

// append buttons
if ($data['valuemapid'] != 0) {
	$tab->setFooter(makeFormFooter(
		new CSubmit('update', _('Update')),
		[
			new CButtonDelete($data['confirmMessage'], url_param('valuemapid')),
			new CButtonCancel()
		]
	));
}
else {
	$tab->setFooter(makeFormFooter(
		new CSubmit('add', _('Add')),
		[new CButtonCancel()]
	));
}

$form->addItem($tab);

$widget->addItem($form);

return $widget;
