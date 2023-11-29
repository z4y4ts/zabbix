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
 * @var array $data
 */

function local_showHeader(array $data): void {
	header('Content-Type: text/html; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('X-XSS-Protection: 1; mode=block');

	if ($data['config']['x_frame_options'] !== '') {
		if (strcasecmp($data['config']['x_frame_options'], 'SAMEORIGIN') == 0
				|| strcasecmp($data['config']['x_frame_options'], 'DENY') == 0) {
			$x_frame_options = $data['config']['x_frame_options'];
		}
		else {
			$x_frame_options = 'SAMEORIGIN';
			$allowed_urls = explode(',', $data['config']['x_frame_options']);
			$url_to_check = array_key_exists('HTTP_REFERER', $_SERVER)
				? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST)
				: null;

			if ($url_to_check) {
				foreach ($allowed_urls as $allowed_url) {
					if (strcasecmp(trim($allowed_url), $url_to_check) == 0) {
						$x_frame_options = 'ALLOW-FROM '.$allowed_url;
						break;
					}
				}
			}
		}

		header('X-Frame-Options: '.$x_frame_options);
	}

	echo (new CPartial('layout.htmlpage.header', [
		'javascript' => [
			'files' => $data['javascript']['files']
		],
		'stylesheet' => [
			'files' => $data['stylesheet']['files']
		],
		'page' => [
			'title' => $data['page']['title']
		],
		'user' => [
			'lang' => CWebUser::$data['lang'],
			'theme' => CWebUser::$data['theme']
		],
		'web_layout_mode' => $data['web_layout_mode'],
		'config' => [
			'server_check_interval' => $data['config']['server_check_interval']
		]
	]))->getOutput();
}

function local_showSidebar(array $data): void {
	global $ZBX_SERVER_NAME;

	if ($data['web_layout_mode'] == ZBX_LAYOUT_NORMAL) {
		echo (new CPartial('layout.htmlpage.aside', [
			'server_name' => isset($ZBX_SERVER_NAME) ? $ZBX_SERVER_NAME : ''
		]))->getOutput();
	}
}

function local_showFooter(array $data): void {
	echo (new CPartial('layout.htmlpage.footer', [
		'user' => [
			'username' => CWebUser::$data['username'],
			'debug_mode' => CWebUser::$data['debug_mode']
		],
		'web_layout_mode' => $data['web_layout_mode']
	]))->getOutput();
}

local_showHeader($data);

echo '<body>';

local_showSidebar($data);

echo '<div class="'.ZBX_STYLE_LAYOUT_WRAPPER.
	($data['web_layout_mode'] == ZBX_LAYOUT_KIOSKMODE ? ' '.ZBX_STYLE_LAYOUT_KIOSKMODE : '').'">';

// Display unexpected messages (if any) generated by the layout.
echo get_prepared_messages(['with_current_messages' => true]);

echo $data['main_block'];

makeServerStatusOutput()->show();

local_showFooter($data);

require_once 'include/views/js/common.init.js.php';

insertPagePostJs();

echo '</div></body></html>';
