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

#include "version.h"
#include "zbxtypes.h"
#include "common.h"

/******************************************************************************
 *                                                                            *
 * Purpose: Extracts protocol version from string. All three groups of digits *
 *          are extracted. Alphanumeric release candidate part is ignored.    *
 *                                                                            *
 * Parameters:                                                                *
 *     value      - [IN] textual representation of version                    *
 *                  Example: "6.4.0alpha1"                                    *
 *                                                                            *
 * Return value: The protocol version if it was successfully extracted,       *
 *               otherwise FAIL                                               *
 *                                                                            *
 ******************************************************************************/
int	zbx_get_component_version(const char *version_str)
{
	char	*pmid, *plow;
	char	version_buf[ZBX_VERSION_BUF_LEN];

	if (NULL == version_str)
		return FAIL;

	zbx_strlcpy(version_buf, version_str, sizeof(version_buf));

	if (NULL == (pmid = strchr(version_buf, '.')))
		return FAIL;

	*pmid++ = '\0';

	if (NULL == (plow = strchr(pmid, '.')))
		return FAIL;

	*plow++ = '\0';

	return ZBX_COMPONENT_VERSION(atoi(version_buf), atoi(pmid), atoi(plow));
}

/******************************************************************************
 *                                                                            *
 * Purpose: Extracts protocol version from string. Only the two most          *
 *          significant groups of digits are extracted.                       *
 *                                                                            *
 * Parameters:                                                                *
 *     value      - [IN] textual representation of version                    *
 *                  Example: "6.4.0alpha1"                                    *
 *                                                                            *
 * Return value: The protocol version if it was successfully extracted,       *
 *               otherwise FAIL                                               *
 *                                                                            *
 ******************************************************************************/
int	zbx_get_component_version_ignore_patch(const char *value)
{
	int ver;

	if (FAIL == (ver = zbx_get_component_version(value)))
		return FAIL;

	return ZBX_COMPONENT_VERSION_IGNORE_PATCH(ver);
}
