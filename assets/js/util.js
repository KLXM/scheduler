/**
 * Kleine Helfer für die scheduler-Komponenten. Keine Abhängigkeiten.
 */

/** Erzeugt ein Element: h('button', {class: 'btn', onclick: fn}, 'Text', kind) */
export function h(tag, attributes = {}, ...children) {
  const element = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes ?? {})) {
    if (value === null || value === undefined || value === false) continue;
    if (name.startsWith('on') && typeof value === 'function') {
      element.addEventListener(name.slice(2).toLowerCase(), value);
    } else if (name === 'dataset') {
      Object.assign(element.dataset, value);
    } else if (name in element && typeof value !== 'string') {
      element[name] = value;
    } else {
      element.setAttribute(name, value === true ? '' : String(value));
    }
  }
  element.append(...children.flat().filter((child) => child !== null && child !== undefined && child !== false));
  return element;
}

export function icon(name) {
  return h('i', { class: `rex-icon ${name}`, 'aria-hidden': 'true' });
}

export function debounce(callback, delay = 250) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => callback(...args), delay);
  };
}

let sequence = 0;
export function uniqueId(prefix = 'scheduler') {
  sequence += 1;
  return `${prefix}-${Date.now().toString(36)}-${sequence}`;
}

/**
 * Setzt den Wert eines Datums- oder Zeitfelds. Hängt ein a11y_datetime-Picker am Feld,
 * muss er den Wert bekommen, sonst zeigt sein sichtbares Feld weiter den alten Stand.
 */
export function setFieldValue(input, value) {
  if (input._flatpickr) input._flatpickr.setDate(value, false);
  else input.value = value;
}

/** Aktiviert oder sperrt ein Feld samt dem sichtbaren Ersatzfeld eines a11y_datetime-Pickers. */
export function setFieldDisabled(input, disabled) {
  input.disabled = disabled;
  const picker = input._flatpickr;
  if (picker?.altInput) picker.altInput.disabled = disabled;
  if (picker?._input) picker._input.disabled = disabled;
}

/** Frühestes wählbares Datum, für native Felder und für den Picker. */
export function setMinDate(input, value) {
  if (input._flatpickr) input._flatpickr.set('minDate', value || null);
  else input.min = value;
}

/**
 * a11y_datetime (flatpickr) legt für jedes Feld ein sichtbares Ersatzfeld an, das die Klasse
 * a11y_datetime erbt. Beim nächsten rex:ready würde das Addon dieses Ersatzfeld selbst zum Picker
 * machen. Deshalb markiert scheduler die Ersatzfelder nach jedem Durchlauf als erledigt.
 * Der Handler hängt hinter dem des Addons, weil dessen Skript früher geladen wird.
 */
function protectAltInputs() {
  for (const input of document.querySelectorAll('.flatpickr-input')) {
    input._flatpickr?.altInput?.setAttribute('data-a11y-datetime-initialized', '1');
  }
}
if (window.jQuery) window.jQuery(document).on('rex:ready', protectAltInputs);

/**
 * Initialisiert a11y_datetime-Picker in nachträglich eingefügtem HTML. Das Addon hört im
 * Backend auf rex:ready und durchsucht dann das ganze Dokument.
 */
export function enhancePickers(container) {
  if (!window.jQuery || !container.querySelector('.a11y_datetime, .a11y_datetime_range')) return;

  const run = () => {
    protectAltInputs();
    window.jQuery(document).trigger('rex:ready', [window.jQuery(container)]);
  };
  // Erst nach dem regulären Durchlauf des Addons beim Seitenstart.
  if (document.readyState === 'complete') run();
  else window.addEventListener('load', run, { once: true });
}

/** Zugriff auf die Backend-API. Die Basis-URL enthält bereits API-Namen und CSRF-Token. */
export class Api {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
  }

  url(action, params = {}) {
    const url = new URL(this.baseUrl, window.location.href);
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(params)) {
      if (Array.isArray(value)) value.forEach((item) => url.searchParams.append(`${key}[]`, item));
      else if (value !== undefined && value !== null) url.searchParams.set(key, value);
    }
    return url;
  }

  async get(action, params = {}) {
    return this.#handle(await fetch(this.url(action, params), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }));
  }

  async post(action, body = {}) {
    return this.#handle(await fetch(this.url(action), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    }));
  }

  async #handle(response) {
    let data = null;
    try {
      data = await response.json();
    } catch {
      // Antwort ohne JSON: unten als Fehler behandelt.
    }
    if (!response.ok || data === null) {
      const error = new Error(data?.error ?? data?.message ?? t('request_failed', response.status));
      error.fields = data?.fields ?? {};
      throw error;
    }
    return data;
  }
}

/**
 * Übersetzter Text. Das Wörterbuch kommt aus den Sprachdateien (Schlüssel "scheduler_js_*") und
 * steht im Backend unter rex.scheduler_i18n, im Frontend unter window.schedulerI18n.
 */
export function t(key, ...args) {
  const dictionary = window.rex?.scheduler_i18n ?? window.schedulerI18n ?? {};
  return (dictionary[key] ?? key).replace(/\{(\d+)\}/g, (match, index) => args[Number(index)] ?? match);
}

/** Sprache der Oberfläche für Intl, etwa "de". */
export function language() {
  return document.documentElement.lang || 'de';
}

/**
 * Modaler Dialog auf Basis von <dialog>. Löst mit dem Wert des gewählten Buttons auf, bei Abbruch mit null.
 */
export function ask({ title, text = '', body = null, buttons }) {
  return new Promise((resolve) => {
    const dialog = h('dialog', { class: 'scheduler-dialog' },
      h('form', { method: 'dialog' },
        h('h2', { class: 'scheduler-dialog-title' }, title),
        text ? h('p', {}, text) : null,
        body,
        h('div', { class: 'scheduler-dialog-actions' }, buttons.map((button) => h('button', {
          type: button.value === null ? 'button' : 'submit',
          value: button.value ?? '',
          class: `btn ${button.class ?? 'btn-default'}`,
          autofocus: button.autofocus ?? false,
          onclick: button.value === null ? () => dialog.close('') : null,
        }, button.label))),
      ));
    dialog.addEventListener('close', () => {
      const value = dialog.returnValue;
      dialog.remove();
      resolve(value === '' ? null : value);
    });
    document.body.append(dialog);
    dialog.showModal();

    // Ein modaler Dialog liegt im Browser auf einer eigenen obersten Ebene. Overlays anderer Addons,
    // etwa der Kartenpicker von vector_maps, hängen am <body> und lägen dahinter. Solange der Dialog
    // offen ist, wandern sie deshalb in den Dialog und beim Schließen zurück.
    const adopted = new Map();
    const adopt = () => {
      for (const overlay of document.querySelectorAll('body > .vm-modal-overlay')) {
        adopted.set(overlay, overlay.nextSibling);
        dialog.append(overlay);
      }
    };
    const observer = new MutationObserver(adopt);
    observer.observe(document.body, { childList: true });
    adopt();
    dialog.addEventListener('close', () => {
      observer.disconnect();
      for (const [overlay, next] of adopted) document.body.insertBefore(overlay, next?.parentNode === document.body ? next : null);
    }, { once: true });
  });
}

export function toast(message, type = 'info') {
  let region = document.querySelector('.scheduler-toasts');
  if (!region) {
    region = h('div', { class: 'scheduler-toasts', role: 'status', 'aria-live': 'polite' });
    document.body.append(region);
  }
  const item = h('div', { class: `scheduler-toast scheduler-toast-${type}` }, message);
  region.append(item);
  setTimeout(() => item.remove(), type === 'error' ? 8000 : 4000);
}
