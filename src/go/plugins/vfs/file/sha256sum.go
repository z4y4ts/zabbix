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

package file

import (
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"time"

	"git.zabbix.com/ap/plugin-support/std"
)

func sha256sum(file std.File, start time.Time, timeout int) (result interface{}, err error) {
	var bnum int64
	bnum = 16 * 1024
	buf := make([]byte, bnum)

	hash := sha256.New()

	for bnum > 0 {
		bnum, _ = io.CopyBuffer(hash, file, buf)
		if time.Since(start) > time.Duration(timeout)*time.Second {
			return nil, errors.New("Timeout while processing item.")
		}
	}

	return fmt.Sprintf("%x", hash.Sum(nil)), nil
}
