/**
 * <scheduler-period> umschließt die serverseitig gerenderten Zeitraum-Felder:
 * Ganztägig-Schalter, Beginn, Ende (jeweils Datum und Uhrzeit), Zeitzone.
 *
 * Verhalten wie in gängigen Kalender-Apps: Ganztägig blendet die Uhrzeiten aus, das Ende folgt
 * dem Beginn unter Beibehaltung der Dauer, ein Ende vor dem Beginn ist nicht möglich.
 */
import { setFieldDisabled, setFieldValue, setMinDate } from './util.js';

class SchedulerPeriod extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    const field = (name) => this.querySelector(`[data-period="${name}"]`);
    this.allDay = field('all_day');
    this.startDate = field('start_date');
    this.startTime = field('start_time');
    this.endDate = field('end_date');
    this.endTime = field('end_time');
    this.timezone = field('timezone');
    this.duration = this.#end() - this.#start();

    this.allDay.addEventListener('change', () => {
      // Von ganztägig auf Uhrzeit: statt 00:00 bis 00:00 eine übliche Stunde am Vormittag vorschlagen.
      if (!this.allDay.checked && (this.startTime.value || '00:00') === '00:00' && (this.endTime.value || '00:00') === '00:00') {
        setFieldValue(this.startTime, '09:00');
        setFieldValue(this.endTime, '10:00');
      }
      this.duration = this.#end() - this.#start();
      this.#sync();
    });
    // Der Picker entsteht erst nach dieser Komponente und übernimmt dann den gesperrten Zustand
    // eines ganztägigen Termins; nach dem Laden wird der Zustand deshalb noch einmal abgeglichen.
    window.addEventListener('load', () => this.#sync(false), { once: true });
    for (const input of [this.startDate, this.startTime]) {
      input.addEventListener('change', () => {
        if (!this.startDate.value) return;
        // Das Ende wandert mit, die Dauer bleibt.
        this.#setEnd(new Date(this.#start().getTime() + Math.max(0, this.duration)));
        this.#sync();
      });
    }
    for (const input of [this.endDate, this.endTime]) {
      input.addEventListener('change', () => {
        if (this.#end() < this.#start()) this.#setEnd(this.#start());
        this.duration = this.#end() - this.#start();
        this.#sync();
      });
    }
    this.timezone?.addEventListener('change', () => this.#sync());
    this.#sync(false);
  }

  /** Aktueller Zustand für andere Komponenten, etwa die Wiederholung. */
  get value() {
    const allDay = this.allDay.checked;
    return {
      allDay,
      start: allDay ? this.startDate.value : `${this.startDate.value}T${this.startTime.value || '00:00'}`,
      timezone: this.timezone?.value ?? '',
    };
  }

  #sync(notify = true) {
    const allDay = this.allDay.checked;
    for (const input of [this.startTime, this.endTime]) {
      input.closest('[data-period-time]').hidden = allDay;
      setFieldDisabled(input, allDay);
    }
    if (this.timezone) this.timezone.closest('[data-period-timezone]').hidden = allDay;
    setMinDate(this.endDate, this.startDate.value);
    if (notify) this.dispatchEvent(new CustomEvent('scheduler-period-change', { bubbles: true, detail: this.value }));
  }

  #start() {
    return this.#read(this.startDate, this.startTime);
  }

  #end() {
    return this.#read(this.endDate, this.endTime);
  }

  #read(date, time) {
    return new Date(`${date.value || '1970-01-01'}T${this.allDay.checked ? '00:00' : (time.value || '00:00')}`);
  }

  #setEnd(date) {
    const pad = (n) => String(n).padStart(2, '0');
    setFieldValue(this.endDate, `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`);
    if (!this.allDay.checked) setFieldValue(this.endTime, `${pad(date.getHours())}:${pad(date.getMinutes())}`);
  }
}

customElements.define('scheduler-period', SchedulerPeriod);
