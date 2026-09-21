/**
 * <scheduler-recurrence> pflegt eine RFC-5545-Wiederholungsregel.
 *
 * Serverseitig steht nur ein Textfeld mit der RRULE im Element. Die Komponente blendet es aus und
 * baut darüber die Oberfläche: Wiederholen-Auswahl, Benutzerdefiniert-Panel, lesbare Zusammenfassung
 * und Vorschau der nächsten Termine (beides liefert die PHP-Engine, damit es nur eine Wahrheit gibt).
 * Regeln, die die Oberfläche nicht abbilden kann, bleiben als Text editierbar.
 */
import { Api, debounce, enhancePickers, h, language, setFieldValue, t, uniqueId } from './util.js';

// Wochentagsnamen liefert Intl in der Sprache der Oberfläche; der 1.1.2024 war ein Montag.
const weekdayName = (index, width) => new Intl.DateTimeFormat(language(), { weekday: width, timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + index)));
const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'].map((code, index) => [code, weekdayName(index, 'short'), weekdayName(index, 'long')]);
const ORDINALS = [['1', 'ordinal_first'], ['2', 'ordinal_second'], ['3', 'ordinal_third'], ['4', 'ordinal_fourth'], ['-1', 'ordinal_last']];
const UNITS = { DAILY: ['unit_day', 'unit_days'], WEEKLY: ['unit_week', 'unit_weeks'], MONTHLY: ['unit_month', 'unit_months'], YEARLY: ['unit_year', 'unit_years'] };
const SUPPORTED_KEYS = new Set(['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'COUNT', 'UNTIL', 'WKST']);

function parseRule(text) {
  const parts = {};
  for (const part of text.trim().replace(/^RRULE:/i, '').split(';')) {
    const [key, value] = part.split('=');
    if (key && value) parts[key.toUpperCase()] = value.toUpperCase();
  }
  return parts;
}

class SchedulerRecurrence extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.field = this.querySelector('input[name], textarea[name]');
    this.api = new Api(this.getAttribute('api'));
    this.period = this.closest('form')?.querySelector('scheduler-period') ?? null;
    this.state = this.#stateFromRule(this.field.value);
    this.#build();

    this.closest('form')?.addEventListener('scheduler-period-change', () => { this.#render(); this.#commit(); });
    this.refreshPreview = debounce(() => this.#loadPreview(), 300);
    this.#render();
    this.#loadPreview();
  }

  /* ---------- Zustand ---------- */

  #stateFromRule(text) {
    const parts = parseRule(text);
    const state = {
      preset: 'none', frequency: 'WEEKLY', interval: 1, weekdays: [], monthlyMode: 'day', ordinal: '1', ordinalDay: 'MO',
      end: 'never', until: '', count: 10, raw: text.trim(),
    };
    if (!parts.FREQ) return state;

    const byDay = parts.BYDAY ? parts.BYDAY.split(',') : [];
    const unsupported = Object.keys(parts).some((key) => !SUPPORTED_KEYS.has(key))
      || !UNITS[parts.FREQ]
      || (parts.BYMONTHDAY ?? '').includes(',')
      || (parts.FREQ === 'MONTHLY' && byDay.length > 1)
      || (parts.FREQ === 'WEEKLY' && byDay.some((day) => !/^[A-Z]{2}$/.test(day)))
      || ((parts.FREQ === 'DAILY' || parts.FREQ === 'YEARLY') && (parts.BYDAY || parts.BYMONTHDAY));
    if (unsupported) return { ...state, preset: 'raw' };

    state.frequency = parts.FREQ;
    state.interval = Math.max(1, Number(parts.INTERVAL ?? 1));
    if (parts.FREQ === 'WEEKLY') state.weekdays = byDay;
    if (parts.FREQ === 'MONTHLY' && byDay.length === 1) {
      const match = /^(-?\d)([A-Z]{2})$/.exec(byDay[0]);
      if (!match) return { ...state, preset: 'raw' };
      state.monthlyMode = 'weekday';
      [, state.ordinal, state.ordinalDay] = match;
    }
    if (parts.COUNT) {
      state.end = 'count';
      state.count = Number(parts.COUNT);
    } else if (parts.UNTIL) {
      state.end = 'until';
      state.until = `${parts.UNTIL.slice(0, 4)}-${parts.UNTIL.slice(4, 6)}-${parts.UNTIL.slice(6, 8)}`;
    }

    const simple = state.end === 'never' && state.weekdays.length <= 1 && state.monthlyMode === 'day';
    state.preset = simple && state.interval === 1 ? { DAILY: 'daily', WEEKLY: 'weekly', MONTHLY: 'monthly', YEARLY: 'yearly' }[state.frequency]
      : simple && state.interval === 2 && state.frequency === 'WEEKLY' ? 'biweekly'
      : 'custom';
    return state;
  }

  #startDate() {
    const start = this.period?.value.start ?? '';
    const date = new Date(start.length === 10 ? `${start}T00:00` : start);
    return Number.isNaN(date.getTime()) ? new Date() : date;
  }

  #rule() {
    const s = this.state;
    if (s.preset === 'none') return '';
    if (s.preset === 'raw') return s.raw;

    const presets = { daily: ['DAILY', 1], weekly: ['WEEKLY', 1], biweekly: ['WEEKLY', 2], monthly: ['MONTHLY', 1], yearly: ['YEARLY', 1] };
    if (presets[s.preset]) {
      const [frequency, interval] = presets[s.preset];
      return `FREQ=${frequency}${interval > 1 ? `;INTERVAL=${interval}` : ''}`;
    }

    const parts = [`FREQ=${s.frequency}`];
    if (s.interval > 1) parts.push(`INTERVAL=${s.interval}`);
    if (s.frequency === 'WEEKLY' && s.weekdays.length > 0) {
      parts.push(`BYDAY=${WEEKDAYS.map(([code]) => code).filter((code) => s.weekdays.includes(code)).join(',')}`);
    }
    if (s.frequency === 'MONTHLY') {
      parts.push(s.monthlyMode === 'weekday' ? `BYDAY=${s.ordinal}${s.ordinalDay}` : `BYMONTHDAY=${this.#startDate().getDate()}`);
    }
    if (s.end === 'count' && s.count > 0) parts.push(`COUNT=${s.count}`);
    if (s.end === 'until' && s.until) parts.push(`UNTIL=${s.until.replaceAll('-', '')}`);
    return parts.join(';');
  }

  /* ---------- Oberfläche ---------- */

  #build() {
    const id = uniqueId('scheduler-rrule');
    this.field.hidden = true;
    this.field.setAttribute('aria-hidden', 'true');

    this.presetSelect = h('select', { class: 'form-control', id, onchange: () => this.#onPreset() },
      ['none', 'daily', 'weekly', 'biweekly', 'monthly', 'yearly', 'custom', 'raw'].map((value) => [value, t(`repeat_${value}`)])
        .map(([value, label]) => h('option', { value }, label)));
    // Das bestehende Label des Textfelds zeigt jetzt auf die Auswahl.
    this.closest('.form-group')?.querySelector('label')?.setAttribute('for', id);

    this.intervalInput = h('input', { type: 'number', min: 1, max: 999, class: 'form-control scheduler-rrule-interval', 'aria-label': t('interval'), oninput: () => this.#update({ interval: Math.max(1, Number(this.intervalInput.value) || 1) }) });
    this.frequencySelect = h('select', { class: 'form-control', 'aria-label': t('unit'), onchange: () => this.#update({ frequency: this.frequencySelect.value }) },
      Object.keys(UNITS).map((value) => h('option', { value })));

    this.weekdayGroup = h('div', { class: 'scheduler-rrule-weekdays', role: 'group', 'aria-label': t('weekdays') },
      WEEKDAYS.map(([code, short, long]) => h('button', {
        type: 'button', class: 'scheduler-chip', dataset: { day: code }, title: long, 'aria-label': long,
        onclick: () => {
          // Ohne explizite Auswahl gilt der Wochentag des Beginns; er bleibt beim ersten Klick erhalten.
          const current = this.state.weekdays.length > 0 ? this.state.weekdays : [WEEKDAYS[(this.#startDate().getDay() + 6) % 7][0]];
          const weekdays = current.includes(code) ? current.filter((day) => day !== code) : [...current, code];
          this.#update({ weekdays });
        },
      }, short)));

    const monthlyName = uniqueId('scheduler-monthly');
    this.monthlyDayLabel = h('span');
    this.ordinalSelect = h('select', { class: 'form-control', 'aria-label': t('week_of_month'), onchange: () => this.#update({ monthlyMode: 'weekday', ordinal: this.ordinalSelect.value }) }, ORDINALS.map(([value, key]) => h('option', { value }, t(key))));
    this.ordinalDaySelect = h('select', { class: 'form-control', 'aria-label': t('weekday'), onchange: () => this.#update({ monthlyMode: 'weekday', ordinalDay: this.ordinalDaySelect.value }) }, WEEKDAYS.map(([value, , label]) => h('option', { value }, label)));
    this.monthlyGroup = h('div', { class: 'scheduler-rrule-monthly', role: 'radiogroup', 'aria-label': t('monthly_on') },
      h('label', { class: 'scheduler-choice' }, this.monthlyDayRadio = h('input', { type: 'radio', name: monthlyName, onchange: () => this.#update({ monthlyMode: 'day' }) }), ' ', this.monthlyDayLabel),
      h('label', { class: 'scheduler-choice' }, this.monthlyWeekdayRadio = h('input', { type: 'radio', name: monthlyName, onchange: () => this.#update({ monthlyMode: 'weekday' }) }), ` ${t('on_the')} `, this.ordinalSelect, ' ', this.ordinalDaySelect));

    const endName = uniqueId('scheduler-end');
    // Mit a11y_datetime bekommt das Enddatum denselben Picker wie der Zeitraum.
    const enhanced = this.getAttribute('picker') === 'a11y';
    this.untilInput = h('input', {
      type: enhanced ? 'text' : 'date', class: enhanced ? 'form-control a11y_datetime' : 'form-control', 'aria-label': t('end_date'), autocomplete: 'off',
      'data-dateFormat': enhanced ? 'Y-m-d' : null, 'data-altFormat': enhanced ? 'D, j. F Y' : null, 'data-allowInput': enhanced ? 'true' : null,
      onchange: () => this.#update({ end: 'until', until: this.untilInput.value }),
    });
    this.countInput = h('input', { type: 'number', min: 1, max: 999, class: 'form-control scheduler-rrule-interval', 'aria-label': t('count'), oninput: () => this.#update({ end: 'count', count: Math.max(1, Number(this.countInput.value) || 1) }) });
    this.endRadios = {};
    const endOption = (value, ...content) => h('label', { class: 'scheduler-choice' },
      this.endRadios[value] = h('input', { type: 'radio', name: endName, onchange: () => this.#update({ end: value }) }), ' ', ...content);
    this.endGroup = h('div', { class: 'scheduler-rrule-end', role: 'radiogroup', 'aria-label': t('repeat_end') },
      endOption('never', t('end_never')), endOption('until', `${t('end_on')} `, this.untilInput), endOption('count', `${t('end_after')} `, this.countInput, ` ${t('end_after_events')}`));

    this.customPanel = h('div', { class: 'scheduler-rrule-custom' },
      h('div', { class: 'scheduler-rrule-row' }, h('span', { class: 'scheduler-rrule-label' }, t('repeat_every')), this.intervalInput, this.frequencySelect),
      this.weekdayRow = h('div', { class: 'scheduler-rrule-row' }, h('span', { class: 'scheduler-rrule-label' }, t('on_days')), this.weekdayGroup),
      this.monthlyRow = h('div', { class: 'scheduler-rrule-row' }, h('span', { class: 'scheduler-rrule-label' }, t('each')), this.monthlyGroup),
      h('div', { class: 'scheduler-rrule-row' }, h('span', { class: 'scheduler-rrule-label' }, t('ends')), this.endGroup));

    this.rawInput = h('input', { type: 'text', class: 'form-control', spellcheck: 'false', 'aria-label': 'RRULE', placeholder: 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1', oninput: () => this.#update({ raw: this.rawInput.value }) });
    this.rawPanel = h('div', { class: 'scheduler-rrule-custom' }, this.rawInput,
      h('p', { class: 'help-block' }, t('raw_help')));

    this.summary = h('p', { class: 'scheduler-rrule-summary', 'aria-live': 'polite' });
    this.nextList = h('ol', { class: 'scheduler-rrule-next' });
    this.preview = h('div', { class: 'scheduler-rrule-preview' }, this.summary, this.nextList);

    this.append(this.presetSelect, this.customPanel, this.rawPanel, this.preview);
    enhancePickers(this);
  }

  #onPreset() {
    const preset = this.presetSelect.value;
    const changes = { preset };
    if (preset === 'custom' && this.state.preset !== 'custom') {
      // Sinnvolle Vorbelegung aus dem bisherigen Preset übernehmen.
      const from = { daily: ['DAILY', 1], weekly: ['WEEKLY', 1], biweekly: ['WEEKLY', 2], monthly: ['MONTHLY', 1], yearly: ['YEARLY', 1] }[this.state.preset];
      if (from) [changes.frequency, changes.interval] = from;
    }
    if (preset === 'raw') changes.raw = this.#rule();
    this.#update(changes);
  }

  #update(changes) {
    Object.assign(this.state, changes);
    this.#render();
    this.#commit();
  }

  #commit() {
    this.field.value = this.#rule();
    this.refreshPreview();
  }

  #render() {
    const s = this.state;
    const start = this.#startDate();
    this.presetSelect.value = s.preset;
    this.customPanel.hidden = s.preset !== 'custom';
    this.rawPanel.hidden = s.preset !== 'raw';
    if (document.activeElement !== this.rawInput) this.rawInput.value = s.raw;

    if (document.activeElement !== this.intervalInput) this.intervalInput.value = s.interval;
    this.frequencySelect.value = s.frequency;
    for (const option of this.frequencySelect.options) option.textContent = t(UNITS[option.value][s.interval > 1 ? 1 : 0]);

    this.weekdayRow.hidden = s.frequency !== 'WEEKLY';
    const startDay = WEEKDAYS[(start.getDay() + 6) % 7][0];
    for (const chip of this.weekdayGroup.children) {
      // Ohne Auswahl gilt der Wochentag des Beginns; er wird entsprechend markiert.
      const active = s.weekdays.length > 0 ? s.weekdays.includes(chip.dataset.day) : chip.dataset.day === startDay;
      chip.setAttribute('aria-pressed', String(active));
    }

    this.monthlyRow.hidden = s.frequency !== 'MONTHLY';
    this.monthlyDayLabel.textContent = t('on_day_of_month', start.getDate());
    this.monthlyDayRadio.checked = s.monthlyMode === 'day';
    this.monthlyWeekdayRadio.checked = s.monthlyMode === 'weekday';
    this.ordinalSelect.value = s.ordinal;
    this.ordinalDaySelect.value = s.ordinalDay;

    for (const [value, radio] of Object.entries(this.endRadios)) radio.checked = s.end === value;
    if (document.activeElement !== this.untilInput && this.untilInput.value !== s.until) setFieldValue(this.untilInput, s.until);
    if (document.activeElement !== this.countInput) this.countInput.value = s.count;
    this.preview.hidden = s.preset === 'none';
  }

  async #loadPreview() {
    const rrule = this.#rule();
    if (rrule === '') {
      this.summary.textContent = '';
      this.nextList.replaceChildren();
      return;
    }
    try {
      const period = this.period?.value ?? {};
      const result = await this.api.post('rrulePreview', { rrule, start: period.start, allDay: period.allDay, timezone: period.timezone });
      this.summary.classList.toggle('scheduler-error', Boolean(result.error));
      this.summary.textContent = result.error ?? result.text;
      this.nextList.replaceChildren(...(result.next ?? []).map((date) => h('li', {}, date)));
      this.field.setCustomValidity(result.error ?? '');
    } catch (error) {
      this.summary.textContent = error.message;
    }
  }
}

customElements.define('scheduler-recurrence', SchedulerRecurrence);
