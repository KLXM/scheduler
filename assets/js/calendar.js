/**
 * <scheduler-calendar> zeigt die Kalenderansicht des Backends auf Basis von FullCalendar.
 *
 * Attribute: api (Basis-URL der Backend-API), edit-url (Editor), calendars (JSON-Liste),
 * locale, first-day, timezone.
 */
import { Api, ask, h, language, t, toast } from './util.js';

const STORAGE_KEY = 'scheduler.calendar';

class SchedulerCalendar extends HTMLElement {
  connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    this.api = new Api(this.getAttribute('api'));
    this.calendars = JSON.parse(this.getAttribute('calendars') || '[]');
    this.stored = this.#loadState();
    this.hiddenCalendars = new Set(this.stored.hidden ?? []);

    this.bar = h('div', { class: 'scheduler-calendar-bar', role: 'group', 'aria-label': t('toggle_calendars') },
      this.calendars.map((calendar) => h('label', { class: 'scheduler-calendar-filter', style: `--scheduler-color:${calendar.color}` },
        h('input', {
          type: 'checkbox', checked: !this.hiddenCalendars.has(calendar.id),
          onchange: (event) => {
            event.target.checked ? this.hiddenCalendars.delete(calendar.id) : this.hiddenCalendars.add(calendar.id);
            this.#saveState();
            this.view.refetchEvents();
          },
        }), calendar.name)));
    // Die ID gibt den Farbregeln genug Gewicht gegen global geladene Kalender-Styles anderer Addons.
    this.container = h('div', { class: 'scheduler-calendar-view', id: 'scheduler-calendar-view' });
    this.append(this.bar, this.container);

    if (!window.FullCalendar) {
      this.container.textContent = t('fullcalendar_missing');
      return;
    }
    this.#init();
  }

  disconnectedCallback() {
    this.view?.destroy();
    delete this.dataset.ready;
    this.replaceChildren();
  }

  #init() {
    this.view = new window.FullCalendar.Calendar(this.container, {
      locale: this.getAttribute('locale') || language(),
      firstDay: Number(this.getAttribute('first-day') ?? 1),
      initialView: this.stored.view ?? 'dayGridMonth',
      initialDate: this.stored.date,
      height: 'auto',
      nowIndicator: true,
      navLinks: true,
      weekNumbers: true,
      dayMaxEvents: 5,
      selectable: this.calendars.some((calendar) => calendar.editable),
      selectMirror: true,
      editable: true,
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'multiMonthYear,dayGridMonth,timeGridWeek,timeGridDay,listMonth' },
      // Lädt nur den sichtbaren Zeitraum; der Server fragt dafür den Vorkommens-Index ab.
      events: (info, success, failure) => {
        const visible = this.calendars.filter((calendar) => !this.hiddenCalendars.has(calendar.id)).map((calendar) => calendar.id);
        if (visible.length === 0) {
          success([]);
          return;
        }
        this.api.get('occurrences', { start: info.startStr, end: info.endStr, calendars: visible }).then(success, (error) => {
          toast(error.message, 'error');
          failure(error);
        });
      },
      eventClassNames: ({ event }) => [
        event.extendedProps.published ? '' : 'is-offline',
        event.extendedProps.cancelled ? 'is-cancelled' : '',
      ].filter(Boolean),
      eventDidMount: ({ event, el }) => {
        const notes = [event.extendedProps.calendar, event.extendedProps.recurring ? t('series') : '', event.extendedProps.published ? '' : t('offline')].filter(Boolean);
        el.title = `${event.title} (${notes.join(', ')})`;
      },
      eventClick: (info) => {
        info.jsEvent.preventDefault();
        const url = new URL(info.event.url, window.location.href);
        if (info.event.extendedProps.recurring) url.searchParams.set('occurrence', info.event.extendedProps.key);
        window.location.assign(url);
      },
      select: (info) => this.#quickCreate(info),
      eventDrop: (info) => this.#move(info),
      eventResize: (info) => this.#move(info),
      datesSet: (info) => {
        this.stored.view = info.view.type;
        this.stored.date = info.view.currentStart.toISOString();
        this.#saveState();
      },
    });
    this.view.render();
  }

  async #quickCreate(info) {
    const editable = this.calendars.filter((calendar) => calendar.editable && !this.hiddenCalendars.has(calendar.id));
    const candidates = editable.length > 0 ? editable : this.calendars.filter((calendar) => calendar.editable);
    if (candidates.length === 0) {
      this.view.unselect();
      return;
    }

    const title = h('input', { type: 'text', class: 'form-control', required: true, placeholder: t('new_event'), 'aria-label': t('title') });
    const select = h('select', { class: 'form-control', 'aria-label': t('calendar') }, candidates.map((calendar) => h('option', { value: calendar.id, selected: calendar.id === this.stored.lastCalendar }, calendar.name)));
    const range = new Intl.DateTimeFormat(this.getAttribute('locale') || language(), info.allDay ? { dateStyle: 'full' } : { dateStyle: 'full', timeStyle: 'short' })
      .formatRange(info.start, info.allDay ? new Date(info.end.getTime() - 86400000) : info.end);

    const answer = await ask({
      title: t('new_event'),
      body: h('div', {}, h('p', { class: 'text-muted' }, range), h('div', { class: 'form-group' }, title), h('div', { class: 'form-group' }, select)),
      buttons: [
        { label: t('cancel'), value: null },
        { label: t('details'), value: 'details' },
        { label: t('create'), value: 'create', class: 'btn-save', autofocus: false },
      ],
    });
    this.view.unselect();
    if (answer === null) return;

    this.stored.lastCalendar = Number(select.value);
    this.#saveState();

    if (answer === 'details') {
      const url = new URL(this.getAttribute('edit-url'), window.location.href);
      url.searchParams.set('func', 'add');
      url.searchParams.set('return', 'calendar');
      url.searchParams.set('calendar_id', select.value);
      url.searchParams.set('all_day', info.allDay ? '1' : '0');
      url.searchParams.set('start', info.startStr);
      url.searchParams.set('end', info.allDay ? new Date(info.end.getTime() - 86400000).toISOString().slice(0, 10) : info.endStr);
      window.location.assign(url);
      return;
    }

    try {
      await this.api.post('quickCreate', { title: title.value, calendarId: Number(select.value), start: info.startStr, end: info.endStr, allDay: info.allDay });
      this.view.refetchEvents();
    } catch (error) {
      toast(error.message, 'error');
    }
  }

  async #move(info) {
    const { event } = info;
    let scope = 'this';
    if (event.extendedProps.recurring) {
      scope = await ask({
        title: t('series_change_title'),
        text: t('series_change_text', event.title),
        buttons: [
          { label: t('cancel'), value: null },
          { label: t('scope_all'), value: 'all' },
          { label: t('scope_following'), value: 'following' },
          { label: t('scope_this'), value: 'this', class: 'btn-save', autofocus: true },
        ],
      });
      if (scope === null) {
        info.revert();
        return;
      }
    }

    // Ohne Ende rechnet FullCalendar mit seiner Standarddauer; die übernimmt der Server genauso.
    const local = (date) => (date ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, event.allDay ? 10 : 19) : '');
    try {
      await this.api.post('move', {
        eventId: event.extendedProps.eventId, key: event.extendedProps.key, scope,
        start: local(event.start), end: local(event.end), allDay: event.allDay,
      });
      this.view.refetchEvents();
    } catch (error) {
      info.revert();
      toast(error.message, 'error');
    }
  }

  #loadState() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) ?? {};
    } catch {
      return {};
    }
  }

  #saveState() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...this.stored, hidden: [...this.hiddenCalendars] }));
    } catch {
      // Ohne localStorage funktioniert der Kalender trotzdem, er merkt sich nur nichts.
    }
  }
}

customElements.define('scheduler-calendar', SchedulerCalendar);
