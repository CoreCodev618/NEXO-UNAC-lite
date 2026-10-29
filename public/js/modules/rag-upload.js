import { api, ApiError } from '../core/api.js';
import { appUrl, escapeHtml, qs } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toast } from '../core/toast.js';

const ALLOWED = new Set(['pdf', 'docx', 'pptx', 'jpg', 'jpeg', 'png', 'zip']);
const IMAGES = new Set(['jpg', 'jpeg', 'png']);
const BADGE = {
  indexed: `<span class="nx-badge nx-badge--success">${icon('check-circle')} Listo</span>`,
  error: `<span class="nx-badge nx-badge--danger">${icon('alert-triangle')} No se pudo leer</span>`,
  pending: `<span class="nx-badge nx-badge--outline">${icon('clock')} Sin leer</span>`,
};

function extension(name = '') {
  const parts = String(name).toLowerCase().split('.');
  return parts.length > 1 ? parts.pop() : '';
}

function actions(doc) {
  if (doc.status !== 'indexed') {
    return `<button class="nx-btn nx-btn--secondary nx-btn--sm" type="button" data-rag-retry="${doc.id}">${icon('refresh')} Leer de nuevo</button>`;
  }

  const summary = doc.summary_id
    ? `<a class="nx-btn nx-btn--secondary nx-btn--sm" href="${appUrl(`pages/${doc.summary_id}`)}">${icon('file-text')} Ver resumen</a>`
    : `<button class="nx-btn nx-btn--secondary nx-btn--sm" type="button" data-rag-summary="${doc.id}">${icon('file-text')} Generar resumen</button>`;

  return `${summary}
    <a class="nx-btn nx-btn--tutor nx-btn--sm" href="${appUrl(`tutor?doc=${doc.id}`)}">${icon('sparkles')} Preguntar al tutor</a>`;
}

function renderList(root, documents) {
  const list = qs('[data-rag-list]', root);

  list.innerHTML = documents.length
    ? documents
        .map(
          (doc) => `
        <article class="nx-rag__item">
          <span class="nx-rag__filetype" aria-hidden="true">${escapeHtml((extension(doc.file_name) || 'file').toUpperCase())}</span>
          <div class="nx-rag__item-main">
            <strong>${escapeHtml(doc.title || 'Archivo')}</strong>
            <span>${escapeHtml(doc.size_label || '')}</span>
            ${doc.status === 'error' && doc.error ? `<p class="nx-rag__item-error">${escapeHtml(doc.error)}</p>` : ''}
            <div class="nx-rag__actions">${actions(doc)}</div>
          </div>
          <div class="nx-rag__item-side">
            ${BADGE[doc.status] || BADGE.pending}
            <button class="nx-rag__remove" type="button" data-rag-remove="${doc.id}" data-rag-name="${escapeHtml(doc.title || 'este archivo')}" aria-label="Quitar ${escapeHtml(doc.title || 'archivo')}">Quitar</button>
          </div>
        </article>`,
        )
        .join('')
    : '<p class="nx-rag__empty">Todavía no subiste nada.</p>';
}

async function refresh(root) {
  try {
    renderList(root, await api.documents.list());
  } catch (error) {
    qs('[data-rag-list]', root).innerHTML = `<p class="nx-rag__empty">No se pudo cargar tu material. Recarga la página.</p>`;
  }
}

function status(root, text, state = '') {
  const el = qs('[data-rag-status]', root);
  el.textContent = text;
  el.dataset.state = state;
}

/**
 * Tarjeta de progreso: qué está haciendo, cuánto lleva y un botón para cancelar.
 * Devuelve { step(texto), done() }.
 */
function progress(root, name, onCancel) {
  const box = qs('[data-rag-progress]', root);
  const started = Date.now();
  box.hidden = false;
  box.innerHTML = `
    <span class="nx-rag__spinner" aria-hidden="true"></span>
    <div class="nx-rag__progress-main">
      <strong>${escapeHtml(name)}</strong>
      <span data-rag-step></span>
    </div>
    <span class="nx-rag__elapsed" data-rag-elapsed>0 s</span>
    <button class="nx-btn nx-btn--secondary nx-btn--sm" type="button" data-rag-cancel>Cancelar</button>`;

  qs('[data-rag-cancel]', box).addEventListener('click', onCancel, { once: true });
  const timer = window.setInterval(() => {
    qs('[data-rag-elapsed]', box).textContent = `${Math.round((Date.now() - started) / 1000)} s`;
  }, 1000);

  return {
    step(text) {
      qs('[data-rag-step]', box).textContent = text;
    },
    done() {
      window.clearInterval(timer);
      box.hidden = true;
      box.innerHTML = '';
    },
  };
}

async function read(docId, name, ext, bar, signal) {
  bar.step(
    IMAGES.has(ext)
      ? 'Leyendo la foto con IA (reconoce el texto)…'
      : 'Leyendo el archivo y preparándolo para el tutor…',
  );
  await api.documents.ingest(docId, signal);
  toast.success(`«${name}» está listo. Ya puedes generar su resumen o preguntarle al tutor.`, 'Material listo');
}

async function upload(root, file) {
  if (root.classList.contains('is-uploading')) return;
  const maxMb = Number(root.dataset.ragMaxMb || 20);
  const ext = extension(file.name);

  if (!ALLOWED.has(ext)) return status(root, 'Ese formato no se puede leer. Usa PDF, Word, PowerPoint, foto o ZIP.', 'error');
  if (file.size > maxMb * 1024 * 1024) return status(root, `El archivo pesa más de ${maxMb} MB.`, 'error');

  const form = new FormData();
  form.append('file', file);

  const controller = new AbortController();
  let docId = null;
  root.classList.add('is-uploading');
  status(root, '');

  const bar = progress(root, file.name, async () => {
    controller.abort();
    // El servidor puede seguir leyendo: se borra para que no quede a medias.
    if (docId) await api.documents.remove(docId).catch(() => {});
  });

  try {
    bar.step('Subiendo…');
    const doc = await api.documents.upload(form, (pct) => bar.step(`Subiendo… ${pct}%`), controller.signal);
    docId = doc.id;
    await read(docId, file.name, ext, bar, controller.signal);
  } catch (error) {
    if (error?.name === 'AbortError') {
      toast.info(`Se canceló la subida de «${file.name}».`, 'Cancelado');
    } else {
      status(root, error instanceof ApiError ? error.message : 'No se pudo subir el archivo.', 'error');
    }
  } finally {
    bar.done();
    root.classList.remove('is-uploading');
    await refresh(root);
  }
}

async function retry(root, button) {
  if (root.classList.contains('is-uploading')) return;
  const item = button.closest('.nx-rag__item');
  const name = qs('strong', item)?.textContent || 'Archivo';
  const ext = (qs('.nx-rag__filetype', item)?.textContent || '').trim().toLowerCase();
  const controller = new AbortController();

  root.classList.add('is-uploading');
  status(root, '');
  const bar = progress(root, name, () => controller.abort());

  try {
    await read(button.dataset.ragRetry, name, ext, bar, controller.signal);
  } catch (error) {
    if (error?.name !== 'AbortError') status(root, error.message || 'No se pudo leer el archivo.', 'error');
  } finally {
    bar.done();
    root.classList.remove('is-uploading');
    await refresh(root);
  }
}

async function summarize(button) {
  button.disabled = true;
  button.innerHTML = `<span class="nx-rag__spinner nx-rag__spinner--sm" aria-hidden="true"></span> El tutor está escribiendo…`;

  try {
    const note = await api.documents.summary(button.dataset.ragSummary);
    window.location.href = note?.url || appUrl(`pages/${note?.id}`);
  } catch (error) {
    button.disabled = false;
    button.innerHTML = `${icon('file-text')} Generar resumen`;
    toast.error(error.message || 'No se pudo generar el resumen.', 'Sin resumen');
  }
}

export function initRagUpload() {
  document.querySelectorAll('[data-rag-upload]').forEach((root) => {
    const input = qs('[data-rag-input]', root);
    const zone = qs('[data-rag-dropzone]', root);

    input.addEventListener('change', () => {
      if (input.files[0]) upload(root, input.files[0]);
      input.value = '';
    });

    ['dragenter', 'dragover'].forEach((name) =>
      zone.addEventListener(name, (event) => {
        event.preventDefault();
        zone.classList.add('is-dragging');
      }),
    );
    ['dragleave', 'drop'].forEach((name) =>
      zone.addEventListener(name, (event) => {
        event.preventDefault();
        zone.classList.remove('is-dragging');
      }),
    );
    zone.addEventListener('drop', (event) => {
      if (event.dataTransfer?.files?.[0]) upload(root, event.dataTransfer.files[0]);
    });

    qs('[data-rag-list]', root).addEventListener('click', async (event) => {
      const summary = event.target.closest('[data-rag-summary]');
      if (summary) return summarize(summary);

      const again = event.target.closest('[data-rag-retry]');
      if (again) return retry(root, again);

      const button = event.target.closest('[data-rag-remove]');
      if (!button || !window.confirm(`¿Quitar «${button.dataset.ragName}»? El tutor dejará de usarlo.`)) return;

      button.disabled = true;
      try {
        await api.documents.remove(button.dataset.ragRemove);
      } catch (error) {
        toast.error(error.message || 'No se pudo quitar el archivo.', 'No se pudo quitar');
      }
      await refresh(root);
    });

    refresh(root);
  });
}
