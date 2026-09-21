/**
 * Anlegen aus dem Formular heraus, ohne es zu verlassen.
 *
 * <scheduler-quick-create> hängt neben einer Auswahlliste. Der Knopf öffnet einen Dialog mit den
 * angegebenen Feldern, legt den Datensatz über die Backend-API an und wählt ihn in der Liste aus.
 *
 * <scheduler-yform-field> umschließt die Auswahl eines YForm-Datensatzes. "Neu" öffnet das
 * YForm-Formular im Popup; nach dem Schließen lädt die Liste neu und wählt den neuen Datensatz.
 */
import { Api, ask, enhancePickers, h, icon, t, toast, uniqueId } from './util.js';

class SchedulerQuickCreate extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.api = new Api(this.getAttribute('api'));
    this.fields = JSON.parse(this.getAttribute('fields') || '[]');
    this.append(h('button', { type: 'button', class: 'btn btn-default btn-sm scheduler-quick-create-button', onclick: () => this.open() },
      icon('fa-plus'), ' ', this.getAttribute('label') || t('new')));
  }

  async open() {
    const inputs = {};
    const rows = this.fields.map((field) => {
      const id = uniqueId('quick');
      let control;
      if (field.html) {
        // Serverseitig gerendertes Eingabefeld, etwa der Kartenpicker von vector_maps.
        control = h('div', {});
        control.innerHTML = field.html;
        inputs[field.name] = control.querySelector('input, select, textarea');
      } else {
        control = h('input', { type: field.type || 'text', class: field.type === 'color' ? 'scheduler-color' : 'form-control', id, required: Boolean(field.required), value: field.value ?? '', placeholder: field.placeholder ?? '' });
        inputs[field.name] = control;
      }
      return h('div', { class: 'form-group' }, h('label', { for: id }, field.label), control);
    });
    const error = h('p', { class: 'scheduler-error', role: 'alert', hidden: true });
    const body = h('div', {}, ...rows, error);

    // Der Dialog bleibt offen, solange der Server Fehler meldet.
    for (;;) {
      const pending = ask({
        title: this.getAttribute('title') || t('create_new'),
        body,
        buttons: [{ label: t('cancel'), value: null }, { label: t('create'), value: 'create', class: 'btn-save' }],
      });
      enhancePickers(body);
      requestAnimationFrame(() => Object.values(inputs)[0]?.focus());
      if (await pending === null) return;

      try {
        const payload = Object.fromEntries(Object.entries(inputs).map(([name, input]) => [name, input?.value ?? '']));
        const created = await this.api.post(this.getAttribute('action'), payload);
        this.#select(created);
        toast(`„${created.label}“ wurde angelegt.`);
        return;
      } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
      }
    }
  }

  #select(created) {
    const select = document.querySelector(this.getAttribute('for'));
    if (!select) return;
    select.append(h('option', { value: created.id }, created.label));
    select.value = String(created.id);
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

class SchedulerYformField extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.select = this.querySelector('select');
    const createUrl = this.getAttribute('create-url');
    if (!createUrl || !this.select) return;

    // Die API-Adresse liefert das umgebende Formular, damit der Feldtyp keine eigene braucht.
    const apiUrl = this.closest('[data-scheduler-api]')?.dataset.schedulerApi;
    this.api = apiUrl ? new Api(apiUrl) : null;
    this.append(h('button', { type: 'button', class: 'btn btn-default btn-sm scheduler-quick-create-button', onclick: () => this.#openPopup(createUrl) },
      icon('fa-plus'), ` ${t('yform_create')}`));
  }

  #openPopup(url) {
    const popup = window.open(url, 'scheduler_yform', 'width=1100,height=800,resizable=yes,scrollbars=yes');
    if (!popup) {
      toast(t('popup_blocked'), 'error');
      return;
    }
    const timer = setInterval(() => {
      if (!popup.closed) return;
      clearInterval(timer);
      this.#reload();
    }, 500);
  }

  async #reload() {
    if (!this.api) return;
    try {
      const known = new Set([...this.select.options].map((option) => option.value));
      const chosen = new Set([...this.select.selectedOptions].map((option) => option.value));
      const { options } = await this.api.get('yformOptions', { table: this.getAttribute('table'), label_field: this.getAttribute('label-field') });

      const placeholder = [...this.select.options].find((option) => option.value === '');
      this.select.replaceChildren(...(placeholder ? [placeholder] : []), ...options.map((option) => h('option', { value: option.id }, option.label)));
      // Neu hinzugekommene Datensätze werden gleich ausgewählt.
      const added = options.filter((option) => !known.has(option.id)).map((option) => option.id);
      for (const option of this.select.options) {
        option.selected = this.select.multiple ? chosen.has(option.value) || added.includes(option.value) : option.value === (added.at(-1) ?? [...chosen][0] ?? '');
      }
      this.select.dispatchEvent(new Event('change', { bubbles: true }));
      if (added.length > 0) toast(t('yform_created'));
    } catch (failure) {
      toast(failure.message, 'error');
    }
  }
}

customElements.define('scheduler-quick-create', SchedulerQuickCreate);
customElements.define('scheduler-yform-field', SchedulerYformField);
