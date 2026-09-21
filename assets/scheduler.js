/**
 * Einstiegspunkt der Backend-Oberfläche. Jede Datei registriert ihre Web Components selbst.
 */
import './js/form-components.js';
import './js/period.js';
import './js/recurrence.js';
import './js/calendar.js';
import './js/field-builder.js';
import './js/quick-create.js';

// Rückfrage vor Aktionen mit data-confirm, ohne Inline-Skripte.
document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-confirm]');
  if (trigger && !window.confirm(trigger.dataset.confirm)) {
    event.preventDefault();
    event.stopPropagation();
  }
}, true);
