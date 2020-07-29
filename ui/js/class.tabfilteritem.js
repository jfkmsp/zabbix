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
const TABFILTERITEM_EVENT_EXPAND_BEFORE = 'expandbefore.tabfilter';
const TABFILTERITEM_EVENT_RENDER = 'render.tabfilter';
const TABFILTERITEM_EVENT_DELETE = 'delete.tabfilter';

class CTabFilterItem extends CBaseComponent {

	constructor(target, options) {
		super(target);

		this._parent = null;
		this._idx_namespace = options.idx_namespace;
		this._index = options.index;
		this._content_container = options.container;
		this._can_toggle = options.can_toggle;
		this._data = options.data||{};
		this._template = options.template;
		this._expanded = options.expanded;

		this.init();
		this.registerEvents();
	}

	init() {
		if (this._expanded) {
			this.renderContentTemplate();

			if ('name' in this._data && this._data.name.length) {
				this.addActionIcons();
			}
		}

		if (this._data.show_counter) {
			this.setCounter('-');
		}
	}

	registerEvents() {
		this._events = {
			click: () => {
				if (!this._expanded) {
					this.fire(TABFILTERITEM_EVENT_EXPAND_BEFORE);
					this.fire(TABFILTERITEM_EVENT_EXPAND);
				}
				else if (this._can_toggle) {
					this.fire(TABFILTERITEM_EVENT_COLLAPSE);
				}
			},

			expand: () => {
				let is_init = (this._content_container.children.length == 0);

				this._expanded = true;
				this.addClass('active');

				if (is_init) {
					this.renderContentTemplate();
				}
				else {
					(this._template||this._content_container.querySelector('[data-template]')).dispatchEvent(
						new CustomEvent(TABFILTERITEM_EVENT_EXPAND, {detail: this})
					);
				}

				this._content_container.classList.remove('display-none');

				if (this._content_container.querySelector('[name="filter_name"]')) {
					this.addActionIcons();
				}
			},

			collapse: () => {
				this._expanded = false;
				this.removeClass('active');
				this._content_container.classList.add('display-none');
				(this._template||this._content_container.querySelector('[data-template]')).dispatchEvent(
					new CustomEvent(TABFILTERITEM_EVENT_COLLAPSE, {detail: this})
				);
				this.removeActionIcons();
			}
		}

		this
			.on(TABFILTERITEM_EVENT_EXPAND, this._events.expand)
			.on(TABFILTERITEM_EVENT_COLLAPSE, this._events.collapse)
			.on(TABFILTERITEM_EVENT_CLICK, this._events.click);
	}

	setCounter(value) {
		this._target.setAttribute('data-counter', value);
	}

	removeCounter() {
		this._target.removeAttribute('data-counter');
	}

	renderContentTemplate() {
		if (this._template) {
			this._content_container.innerHTML = (new Template(this._template.innerHTML)).evaluate(
				Object.assign({}, this._data, {
					show_counter: +this._data.show_counter,
					custom_time: +this._data.custom_time
				})
			);
			this._template.dispatchEvent(new CustomEvent(TABFILTERITEM_EVENT_RENDER, {detail: this}));
		}
	}

	/**
	 * Open tab filter configuration poup.
	 *
	 * @param {HTMLElement} edit_elm  HTML element to broadcast popup update or delete event.
	 */
	openPropertiesForm(edit_elm) {
		PopUp('popup.tabfilter.edit', {
			'idx': this._idx_namespace,
			'idx2': this._index,
			'name': this._data.name,
			'show_counter': this._data.show_counter ? 1 : 0,
			'custom_time': this._data.custom_time ? 1 : 0,
			'tabfilter_from': this._data.from||'',
			'tabfilter_to': this._data.to||''
		}, 'tabfilter_dialogue', edit_elm);
	}

	/**
	 * Add gear icon and it events to tab filter this._target element.
	 */
	addActionIcons() {
		let edit = document.createElement('a');

		edit.classList.add('icon-edit');
		edit.addEventListener('click', (ev) => this.openPropertiesForm(ev.target));
		edit.addEventListener('popup.tabfilter', (ev) => {
			let data = ev.detail;

			if (data.from_action === 'update') {
				this.update({
					name: data.name,
					show_counter: !!data.show_counter,
					custom_time: !!data.custom_time,
					from: data.tabfilter_from,
					to: data.tabfilter_to
				});
			}
			else {
				this.delete();
			}
		});
		this._target.parentNode.appendChild(edit);
	}

	removeActionIcons() {
		let edit = this._target.parentNode.querySelector('.icon-edit');

		edit && edit.remove();
	}

	select() {
		if (!this._expanded) {
			this._events.click();
		}
	}

	delete() {
		this._content_container.remove();
		this.fire(TABFILTERITEM_EVENT_DELETE);
	}

	/**
	 * Update tab filter configuration: name, show_counter, custom_time.
	 *
	 * @param {object} data  Updated tab properties object.
	 */
	update(data) {
		var form = this._content_container.querySelector('form'),
			fields = {
				name: form.querySelector('[name="filter_name"]'),
				show_counter: form.querySelector('[name="filter_show_counter"]'),
				custom_time: form.querySelector('[name="filter_custom_time"]')
			};

		this._data.show_counter = data.show_counter;
		this._data.custom_time = data.custom_time;
		this._data.name = data.name;

		if (data.custom_time) {
			this._data.from = data.from;
			this._data.to = data.to;
		}

		if (data.show_counter) {
			this.setCounter('');
		}
		else {
			this.removeCounter();
		}

		this._target.text = data.name;
		Object.keys(fields).forEach((key) => {
			if (fields[key]) {
				fields[key].value = this._data[key];
			}
		});
	}
}
