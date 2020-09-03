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


const TABFILTERITEM_EVENT_CLICK = 'click';
const TABFILTERITEM_EVENT_COLLAPSE = 'collapse.tabfilter';
const TABFILTERITEM_EVENT_EXPAND   = 'expand.tabfilter';
const TABFILTERITEM_EVENT_RENDER = 'render.tabfilter';
const TABFILTERITEM_EVENT_URLSET = 'urlset.tabfilter';
const TABFILTERITEM_EVENT_UPDATE = 'update.tabfilter';

class CTabFilterItem extends CBaseComponent {

	constructor(target, options) {
		super(target);

		this._parent = options.parent||null;
		this._idx_namespace = options.idx_namespace;
		this._index = options.index;
		this._content_container = options.container;
		this._can_toggle = options.can_toggle;
		this._data = options.data||{};
		this._template = options.template;
		this._expanded = options.expanded;
		this._support_custom_time = options.support_custom_time;
		this._template_rendered = false;
		this._unsaved = false;
		this._src_url = options.src_url||null;

		this.init();
		this.registerEvents();
	}

	init() {
		if (this._expanded) {
			this.renderContentTemplate();
			this.setBrowserLocation(this.getFilterParams());
			this.resetUnsavedState();
		}

		if (this._data.filter_show_counter) {
			this.setCounter('');
		}
	}

	/**
	 * Set results counter value.
	 *
	 * @param {int} value  Results counter value.
	 */
	setCounter(value) {
		this._target.setAttribute('data-counter', value);
	}

	/**
	 * Get results counter value.
	 */
	getCounter() {
		return this._target.getAttribute('data-counter');
	}

	/**
	 * Remove results counter value.
	 */
	removeCounter() {
		this._target.removeAttribute('data-counter');
	}

	/**
	 * Render tab template with data. Fire TABFILTERITEM_EVENT_RENDER on template container binding this as event this.
	 */
	renderContentTemplate() {
		if (this._template) {
			this._content_container.innerHTML = (new Template(this._template.innerHTML)).evaluate(this._data);
			this._template.dispatchEvent(new CustomEvent(TABFILTERITEM_EVENT_RENDER, {detail: this}));
		}
	}

	/**
	 * Open tab filter configuration poup.
	 *
	 * @param {HTMLElement} edit_elm  HTML element to broadcast popup update or delete event.
	 * @param {object}      params    Object of params to be passed to ajax call when opening popup.
	 */
	openPropertiesForm(edit_elm, params) {
		return PopUp('popup.tabfilter.edit', params, 'tabfilter_dialogue', edit_elm);
	}

	/**
	 * Add gear icon and it events to tab filter this._target element.
	 */
	addActionIcons() {
		let edit = document.createElement('a');

		if (!this._target.parentNode.querySelector('.icon-edit')) {
			edit.classList.add('icon-edit');
			edit.addEventListener('click', (ev) => this.openPropertiesForm(ev.target, {
				idx: this._idx_namespace,
				idx2: this._index,
				filter_name: this._data.filter_name,
				filter_show_counter: this._data.filter_show_counter,
				filter_custom_time: this._data.filter_custom_time,
				tabfilter_from: this._data.from||'',
				tabfilter_to: this._data.to||'',
				support_custom_time: +this._support_custom_time
			}));
			this._target.parentNode.appendChild(edit);
		}
	}

	/**
	 * Remove gear icon HTMLElement.
	 */
	removeActionIcons() {
		this._target.parentNode.querySelector('.icon-edit')?.remove();
	}

	/**
	 * Set selected state of item.
	 */
	setSelected() {
		this._target.focus();
		this._target.parentNode.classList.add('selected');

		if (this._data.filter_configurable) {
			this.addActionIcons();
		}
	}

	/**
	 * Remove selected state of item.
	 */
	removeSelected() {
		this._target.parentNode.classList.remove('selected');

		if (this._data.filter_configurable) {
			this.removeActionIcons();
		}
	}

	/**
	 * Set expanded state of item and it content container, render content from template if it was not rendered yet.
	 * Fire TABFILTERITEM_EVENT_EXPAND event on template.
	 */
	setExpanded() {
		let item_template = this._template||this._content_container.querySelector('[data-template]');

		this._expanded = true;
		this._target.parentNode.classList.add('expanded');

		if (!this._template_rendered) {
			this.renderContentTemplate();
			this._template_rendered = true;
		}
		else if (item_template instanceof HTMLElement) {
			item_template.dispatchEvent(new CustomEvent(TABFILTERITEM_EVENT_EXPAND, {detail: this}));
		}

		this._content_container.classList.remove('display-none');
	}

	/**
	 * Remove expanded state of item and it content. Fire TABFILTERITEM_EVENT_COLLAPSE on item template.
	 */
	removeExpanded() {
		let item_template = (this._template||this._content_container.querySelector('[data-template]'));

		this._expanded = false;
		this._target.parentNode.classList.remove('expanded');
		this._content_container.classList.add('display-none');

		if (item_template instanceof HTMLElement) {
			item_template.dispatchEvent(new CustomEvent(TABFILTERITEM_EVENT_COLLAPSE, {detail: this}));
		}
	}

	/**
	 * Delete item, clean up all related HTMLElement nodes.
	 */
	delete() {
		this._target.parentNode.remove();
		this._content_container.remove();
	}

	/**
	 * Toggle item is item selectable or not.
	 *
	 * @param {boolean} state  Selectable when true.
	 */
	setDisabled(state) {
		this.toggleClass('disabled', state);
		this._target.parentNode.classList.toggle('disabled', state);
	}

	/**
	 * Check does item have custom time interval.
	 *
	 * @return {boolean}
	 */
	hasCustomTime() {
		return !!this._data.filter_custom_time;
	}

	/**
	 * Update tab filter configuration: name, show_counter, custom_time. Set browser URL according new values.
	 *
	 * @param {object} data  Updated tab properties object.
	 */
	update(data) {
		var form = this._content_container.querySelector('form'),
			fields = {
				filter_name: form.querySelector('[name="filter_name"]'),
				filter_show_counter: form.querySelector('[name="filter_show_counter"]'),
				filter_custom_time: form.querySelector('[name="filter_custom_time"]')
			},
			search_params;

		if (data.filter_custom_time) {
			this._data.from = data.from;
			this._data.to = data.to;
		}

		search_params = this.getFilterParams();

		Object.keys(fields).forEach((key) => {
			if (fields[key] instanceof HTMLElement) {
				this._data[key] = data[key];
				fields[key].value = data[key];
			}

			search_params.set(key, this._data[key]);
		});

		if (data.filter_show_counter) {
			this.setCounter('');
		}
		else {
			this.removeCounter();
		}

		this._target.text = data.filter_name;
		this.setBrowserLocation(search_params);
	}

	/**
	 * Get filter parameters as URLSearchParams object, defining value of unchecked checkboxes equal to
	 * 'unchecked-value' attribute value.
	 *
	 * @return {URLSearchParams}
	 */
	getFilterParams() {
		let form = this._content_container.querySelector('form'),
			params = null;

		if (form instanceof HTMLFormElement) {
			params = new URLSearchParams(new FormData(form));

			for (const checkbox of form.querySelectorAll('input[type="checkbox"][unchecked-value]')) {
				if (!checkbox.checked) {
					params.set(checkbox.getAttribute('name'), checkbox.getAttribute('unchecked-value'))
				}
			}
		}

		if (this._data.filter_custom_time) {
			params.set('from', this._data.from);
			params.set('to', this._data.to);
		}

		return params;
	}

	/**
	 * Set browser location URL according to passed values. Argument 'action' from already set URL is preserved.
	 * Create TABFILTER_EVENT_URLSET event with detail.target equal instance of CTabFilter.
	 *
	 * @param {URLSearchParams} search_params  Filter field values to be set in URL.
	 */
	setBrowserLocation(search_params) {
		let url = new Curl('', false);

		search_params.set('action', url.getArgument('action'));
		url.query = search_params.toString();
		url.formatArguments();
		history.replaceState(history.state, '', url.getUrl());
		this.fire(TABFILTERITEM_EVENT_URLSET);
	}

	/**
	 * Checks difference between original form values and to be posted values.
	 * Updates this._unsaved according to check results
	 *
	 * @param {URLSearchParams} search_params  Filter field values to compare against.
	 */
	updateUnsavedState() {
		let search_params = this.getFilterParams(),
			src_query = new URLSearchParams(this._src_url);

		if (search_params === null || !this._data.filter_configurable) {
			// Not templated tabs does not contain form fields, no need to update unsaved state.
			return;
		}

		if (src_query.get('filter_custom_time') !== '1') {
			src_query.delete('from');
			src_query.delete('to');
		}

		src_query.delete('action');
		src_query.sort();
		search_params.sort();

		this._unsaved = (src_query.toString() !== search_params.toString());
		this._target.parentNode.classList.toggle('unsaved', this._unsaved);
	}

	/**
	 * Reset item unsaved state. Set this._src_url to filter parameters.
	 */
	resetUnsavedState() {
		let src_query = this.getFilterParams();

		if (src_query ===  null) {
			this._src_url = '';

			return;
		}

		if (src_query.get('filter_custom_time') !== '1') {
			src_query.delete('from');
			src_query.delete('to');
		}

		src_query.delete('action');
		src_query.sort();

		this._src_url = src_query.toString();
		this._target.parentNode.classList.remove('unsaved');
	}

	registerEvents() {
		this._events = {
			click: () => {
				if (this.hasClass('disabled')) {
					return;
				}

				this._target.focus();

				if (!this._expanded) {
					this.fire(TABFILTERITEM_EVENT_EXPAND);
				}
				else if (this._can_toggle) {
					this.fire(TABFILTERITEM_EVENT_COLLAPSE);
				}
			},

			expand: () => {
				this.setSelected();
				this.setExpanded();

				let search_params = this.getFilterParams();

				if (this._src_url === null) {
					this.resetUnsavedState();
				}

				if (search_params) {
					this.setBrowserLocation(search_params);
				}
			},

			collapse: () => {
				this.removeExpanded();
			}
		}

		this
			.on(TABFILTERITEM_EVENT_EXPAND, this._events.expand)
			.on(TABFILTERITEM_EVENT_COLLAPSE, this._events.collapse)
			.on(TABFILTERITEM_EVENT_CLICK, this._events.click);
	}
}
