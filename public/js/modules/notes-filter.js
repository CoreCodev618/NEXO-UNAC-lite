import { qs, qsa } from '../core/dom.js';

/** Inicio: filtra la lista de apuntes por el archivo del que salieron. */
export function initNotesFilter() {
  const select = qs('[data-notes-filter]');
  if (!select) return;

  select.addEventListener('change', () => {
    qsa('[data-note-doc]').forEach((row) => {
      row.hidden = select.value !== '' && row.dataset.noteDoc !== select.value;
    });
  });
}
