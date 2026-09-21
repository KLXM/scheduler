/**
 * Formular-Bausteine: Tabs, Sprachumschalter, Wiederholungszeilen, Sichtbarkeitsregeln, Schlagwörter.
 * Alle arbeiten auf serverseitig gerendertem HTML (progressive Verbesserung).
 */
import { enhancePickers, h, icon, t, uniqueId } from './util.js';

class SchedulerTabs extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.panels = [...this.children].filter((child) => child.matches('section[data-tab-label]'));
    this.tablist = h('div', { class: 'scheduler-tablist', role: 'tablist' });
    this.tabs = this.panels.map((panel, index) => {
      panel.id ||= uniqueId('scheduler-panel');
      panel.setAttribute('role', 'tabpanel');
      const tab = h('button', {
        type: 'button', role: 'tab', id: `${panel.id}-tab`, 'aria-controls': panel.id, class: 'scheduler-tab',
        onclick: () => this.select(index),
      }, panel.dataset.tabLabel);
      panel.setAttribute('aria-labelledby', tab.id);
      if (panel.querySelector('.has-error, .scheduler-error')) tab.classList.add('has-error');
      return tab;
    });
    this.tablist.append(...this.tabs);
    this.tablist.addEventListener('keydown', (event) => this.#onKey(event));
    this.prepend(this.tablist);

    const firstWithError = this.panels.findIndex((panel) => panel.querySelector('.has-error, .scheduler-error'));
    this.select(Math.max(0, firstWithError), false);

    // Blendet eine Sichtbarkeitsregel den ganzen Tab aus, verschwindet auch sein Reiter.
    new MutationObserver(() => this.#syncHidden()).observe(this, { attributes: true, attributeFilter: ['data-hidden-by-rule'], subtree: true });
  }

  select(index, focus = true) {
    this.current = index;
    this.tabs.forEach((tab, i) => {
      const active = i === index;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      this.panels[i].hidden = !active || this.panels[i].dataset.hiddenByRule === '1';
    });
    if (focus) this.tabs[index]?.focus();
  }

  #syncHidden() {
    this.panels.forEach((panel, i) => { this.tabs[i].hidden = panel.dataset.hiddenByRule === '1'; });
    if (this.tabs[this.current]?.hidden) {
      const next = this.tabs.findIndex((tab) => !tab.hidden);
      if (next >= 0) this.select(next, false);
    }
  }

  #onKey(event) {
    const visible = this.tabs.map((tab, i) => (tab.hidden ? -1 : i)).filter((i) => i >= 0);
    const position = visible.indexOf(this.current);
    const target = { ArrowRight: visible[position + 1] ?? visible[0], ArrowLeft: visible[position - 1] ?? visible.at(-1), Home: visible[0], End: visible.at(-1) }[event.key];
    if (target !== undefined) {
      event.preventDefault();
      this.select(target);
    }
  }
}

class SchedulerLangField extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.variants = [...this.querySelectorAll(':scope > [data-lang]')];
    this.switcher = h('div', { class: 'scheduler-lang-switch', role: 'group', 'aria-label': t('language') },
      this.variants.map((variant, index) => h('button', {
        type: 'button', class: 'scheduler-lang-button', onclick: () => this.select(index),
      }, variant.dataset.lang)));
    this.prepend(this.switcher);
    this.select(0);
  }

  select(index) {
    this.variants.forEach((variant, i) => { variant.hidden = i !== index; });
    [...this.switcher.children].forEach((button, i) => {
      button.setAttribute('aria-pressed', String(i === index));
      // Gefüllte Sprachen sind am Punkt erkennbar.
      const field = this.variants[i].querySelector('input, textarea, select');
      button.classList.toggle('is-filled', Boolean(field?.value));
    });
  }
}

class SchedulerRepeater extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.rows = this.querySelector(':scope > [data-rows]');
    this.template = this.querySelector(':scope > template');
    this.min = Number(this.getAttribute('min')) || 0;
    this.max = Number(this.getAttribute('max')) || 0;

    this.addEventListener('click', (event) => {
      const button = event.target.closest('[data-action]');
      if (!button || button.closest('scheduler-repeater') !== this) return;
      const row = button.closest('[data-row]');
      switch (button.dataset.action) {
        case 'add': this.add(); break;
        case 'remove': row.remove(); break;
        case 'up': row.previousElementSibling?.before(row); break;
        case 'down': row.nextElementSibling?.after(row); break;
        default: return;
      }
      this.#renumber();
      if (button.dataset.action !== 'add' && button.isConnected) button.focus();
    });

    while (this.rows.children.length < this.min) this.add();
    this.#renumber();
  }

  add() {
    const fragment = this.template.content.cloneNode(true);
    this.rows.append(fragment);
    this.#renumber();
    enhancePickers(this.rows.lastElementChild);
    this.rows.lastElementChild?.querySelector('input, textarea, select')?.focus();
  }

  #renumber() {
    [...this.rows.children].forEach((row, index) => {
      for (const element of row.querySelectorAll('[name], [id], [for]')) {
        for (const attribute of ['name', 'id', 'for']) {
          const value = element.getAttribute(attribute);
          if (!value) continue;
          element.setAttribute(attribute, value
            .replace(/\[(?:__index__|\d+)\](?=\[[^\]]+\]$)/, `[${index}]`)
            .replace(/-(?:__index__|\d+)-(?=[^-]+-\d+$)/, `-${index}-`));
        }
      }
      row.querySelector('[data-action="up"]').disabled = index === 0;
      row.querySelector('[data-action="down"]').disabled = index === this.rows.children.length - 1;
      row.querySelector('[data-action="remove"]').disabled = this.rows.children.length <= this.min;
    });
    const addButton = this.querySelector(':scope > [data-action="add"]');
    if (addButton) addButton.disabled = this.max > 0 && this.rows.children.length >= this.max;
  }
}

class SchedulerForm extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';
    this.addEventListener('input', () => this.evaluate());
    this.addEventListener('change', () => this.evaluate());
    this.evaluate();
  }

  evaluate() {
    for (const element of this.querySelectorAll('[data-visible-if]')) {
      const rule = JSON.parse(element.dataset.visibleIf);
      const visible = this.#matches(rule);
      element.dataset.hiddenByRule = visible ? '0' : '1';
      if (!element.matches('section[data-tab-label]')) element.hidden = !visible;
      // Deaktivierte Felder werden nicht gesendet und blockieren keine Pflichtprüfung.
      for (const field of element.querySelectorAll('input, select, textarea, button')) {
        if (field.closest('template')) continue;
        field.disabled = !visible || Boolean(field.closest('[data-hidden-by-rule="1"]'));
      }
    }
  }

  #matches(rule) {
    const value = this.#valueOf(rule.field);
    switch (rule.operator) {
      case '!=': return value !== rule.value;
      case 'empty': return value === '' || value === '0';
      case 'not_empty': return value !== '' && value !== '0';
      default: return value === rule.value;
    }
  }

  #valueOf(fieldName) {
    const fields = [...this.querySelectorAll(`[name="custom[${fieldName}]"], [name="custom[${fieldName}][]"]`)].filter((field) => !field.disabled || field.type === 'hidden');
    const values = [];
    for (const field of fields) {
      if (field.type === 'checkbox' || field.type === 'radio') {
        if (field.checked) values.push(field.value);
      } else if (field.type === 'hidden' && fields.some((other) => other.type === 'checkbox')) {
        // Das versteckte 0-Feld eines Schalters zählt nur, wenn der Schalter aus ist.
        if (!fields.some((other) => other.type === 'checkbox' && other.checked)) values.push(field.value);
      } else if (field instanceof HTMLSelectElement && field.multiple) {
        values.push(...[...field.selectedOptions].map((option) => option.value));
      } else {
        values.push(field.value);
      }
    }
    return values.join(',');
  }
}

class SchedulerTags extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.max = Number(this.getAttribute('max')) || 0;
    try {
      this.tags = JSON.parse(this.getAttribute('value') || '[]');
    } catch {
      this.tags = [];
    }
    this.hidden_ = h('input', { type: 'hidden', name: this.getAttribute('name') });
    this.list = h('ul', { class: 'scheduler-tag-list' });
    this.input = h('input', {
      type: 'text', class: 'form-control scheduler-tag-input', id: this.getAttribute('input-id'), autocomplete: 'off',
      placeholder: t('tag_placeholder'),
      onkeydown: (event) => this.#onKey(event),
      onblur: () => this.#commit(),
    });
    this.append(this.hidden_, this.list, this.input);
    this.#render();
  }

  #onKey(event) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      this.#commit();
    } else if (event.key === 'Backspace' && this.input.value === '' && this.tags.length > 0) {
      this.tags.pop();
      this.#render();
    }
  }

  #commit() {
    const value = this.input.value.trim().replace(/,+$/, '');
    if (value !== '' && !this.tags.includes(value) && (this.max === 0 || this.tags.length < this.max)) {
      this.tags.push(value);
    }
    this.input.value = '';
    this.#render();
  }

  #render() {
    this.hidden_.value = JSON.stringify(this.tags);
    this.list.replaceChildren(...this.tags.map((tag, index) => h('li', { class: 'scheduler-tag' }, tag, h('button', {
      type: 'button', class: 'scheduler-tag-remove', 'aria-label': t('remove_item', tag),
      onclick: () => { this.tags.splice(index, 1); this.#render(); this.input.focus(); },
    }, icon('fa-xmark')))));
    this.input.disabled = this.max > 0 && this.tags.length >= this.max;
  }
}

customElements.define('scheduler-tabs', SchedulerTabs);
customElements.define('scheduler-lang-field', SchedulerLangField);
customElements.define('scheduler-repeater', SchedulerRepeater);
customElements.define('scheduler-form', SchedulerForm);
customElements.define('scheduler-tags', SchedulerTags);
