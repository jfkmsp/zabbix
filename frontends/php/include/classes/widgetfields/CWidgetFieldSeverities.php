<?php
/*
** Zabbix
** Copyright (C) 2001-2018 Zabbix SIA
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

class CWidgetFieldSeverities extends CWidgetField {
	protected $style;

	public function __construct($name, $label) {
		parent::__construct($name, $label);

		$this->setSaveType(ZBX_WIDGET_FIELD_TYPE_INT32);
		$this->setDefault([]);
		$this->setValidationRules(['type' => API_INTS32]);
		$this->setExValidationRules(
			['in' => implode(',', range(TRIGGER_SEVERITY_NOT_CLASSIFIED, TRIGGER_SEVERITY_COUNT - 1))
		]);
		$this->setStyle(ZBX_STYLE_LIST_CHECK_RADIO);
	}

	public function setValue($value) {
		$this->value = (array) $value;

		return $this;
	}

	public function setStyle($value) {
		$this->style = $value;

		return $this;
	}

	public function getStyle() {
		return $this->style;
	}
}
