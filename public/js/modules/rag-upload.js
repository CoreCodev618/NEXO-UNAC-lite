import { api, ApiError } from '../core/api.js';
import { escapeHtml, qs } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toast } from '../core/toast.js';

const ALLOWED = new Set(['pdf', 'docx', 'pptx', 'jpg', 'jpeg', 'png', 'zip']);
const BADGE = {
  indexed: `<span class="nx-badge nx-badge--success">${icon('check-circle')} Listo</span>`,
  error: `<span class="nx-badge nx-badge--danger">${icon('alert-triangle')} No se pudo leer</span>`,
  pending: `<span class="nx-badge nx-badge--outline">${icon('clock')} Pendiente</span>`,
};

function extension(name = '') {
  const parts = String(name).toLowerCase().split('.');
  return parts.length > 1 ? parts.pop() : '';
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

async function upload(root, file) {
  if (root.classList.contains('is-uploading')) return;
  const maxMb = Number(root.dataset.ragMaxMb || 20);

  if (!ALLOWED.has(extension(file.name))) return status(root, 'Ese formato no se puede leer. Usa PDF, Word, PowerPoint, foto o ZIP.', 'error');
  if (file.size > maxMb * 1024 * 1024) return status(root, `El archivo pesa más de ${maxMb} MB.`, 'error');

  const form = new FormData();
  form.append('file', file);

  root.classList.add('is-uploading');
  status(root, `Leyendo «${file.name}»… puede tardar un poco.`, 'busy');

  try {
    await api.documents.upload(form);
    status(root, '');
    toast.success(`«${file.name}» está listo. Ya puedes preguntarle al tutor.`, 'Material listo');
  } catch (error) {
    status(root, error instanceof ApiError ? error.message : 'No se pudo subir el archivo.', 'error');
  } finally {
    root.classList.remove('is-uploading');
    await refresh(root);
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
