/**
 * <scheduler-frontend-calendar> für die Website. Lädt FullCalendar erst, wenn das Element auf der Seite steht.
 */
const loaded = new Map();

function loadScript(src) {
  if (!loaded.has(src)) {
    loaded.set(src, new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.onload = resolve;
      script.onerror = () => reject(new Error(`Konnte ${src} nicht laden`));
      document.head.append(script);
    }));
  }
  return loaded.get(src);
}

class SchedulerFrontendCalendar extends HTMLElement {
  async connectedCallback() {
    if (this.dataset.ready) return;
    this.dataset.ready = '1';

    try {
      await loadScript(this.getAttribute('script'));
      await loadScript(this.getAttribute('locales'));
    } catch (error) {
      this.textContent = error.message;
      return;
    }

    const container = document.createElement('div');
    this.replaceChildren(container);
    const narrow = window.matchMedia('(max-width: 640px)');

    this.calendar = new window.FullCalendar.Calendar(container, {
      locale: this.getAttribute('locale') || document.documentElement.lang || 'de',
      firstDay: Number(this.getAttribute('first-day') ?? 1),
      // Auf schmalen Bildschirmen ist die Liste lesbarer als das Monatsraster.
      initialView: narrow.matches ? 'listMonth' : this.getAttribute('view') || 'dayGridMonth',
      height: 'auto',
      dayMaxEvents: 4,
      navLinks: false,
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
      events: this.getAttribute('feed'),
      eventDidMount: ({ event, el }) => {
        const parts = [event.extendedProps.location, event.extendedProps.teaser].filter(Boolean);
        if (parts.length > 0) el.title = parts.join(' · ');
      },
    });
    this.calendar.render();
  }

  disconnectedCallback() {
    this.calendar?.destroy();
  }
}

customElements.define('scheduler-frontend-calendar', SchedulerFrontendCalendar);
