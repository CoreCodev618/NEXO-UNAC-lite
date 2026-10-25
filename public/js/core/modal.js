import { escapeHtml, focusables, lockScroll, qs, trapFocus, unlockScroll } from './dom.js';
import { icon } from './icons.js';

const lastFocus = new WeakMap();

export function openModal(target) {
  const dialog = typeof target === 'string' ? document.getElementById(target) : target;
  if (!(dialog instanceof HTMLDialogElement)) return null;

  if (!dialog.open) {
    lastFocus.set(dialog, document.activeElement);
    dialog.showModal();
    lockScroll();

    window.requestAnimationFrame(() => {
      const preferred = qs('[autofocus]', dialog) || focusables(dialog)[0];
      preferred?.focus();
    });
  }

  return dialog;
}

export function closeModal(target) {
  const dialog = typeof target === 'string' ? document.getElementById(target) : target;
  if (dialog instanceof HTMLDialogElement && dialog.open) {
    dialog.close();
  }
}

function initDialog(dialog) {
  if (dialog.dataset.modalReady === '1') return;
  dialog.dataset.modalReady = '1';

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });

  dialog.addEventListener('keydown', (event) => {
    trapFocus(dialog, event);
  });

  dialog.addEventListener('close', () => {
    unlockScroll();
    const previous = lastFocus.get(dialog);
    if (previous instanceof HTMLElement && previous.isConnected) {
      previous.focus();
    }
  });
}

export function initModals() {
  document.querySelectorAll('dialog.nx-modal').forEach(initDialog);

  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) {
      event.preventDefault();
      openModal(opener.dataset.dialogOpen);
      return;
    }

    const closer = event.target.closest('[data-dialog-close]');
    if (closer) {
      const dialog = closer.closest('dialog');
      if (dialog) {
        event.preventDefault();
        dialog.close();
      }
    }
  });

  const observer = new MutationObserver((records) => {
    for (const record of records) {
      record.addedNodes.forEach((node) => {
        if (node instanceof HTMLDialogElement && node.classList.contains('nx-modal')) {
          initDialog(node);
        }
      });
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * Confirmación con el lenguaje visual de NEXO. Devuelve una promesa que
 * resuelve true si la persona confirma.
 */
export function confirmDialog({
  title = '¿Confirmas la acción?',
  message = '',
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  tone = 'primary',
} = {}) {
  return new Promise((resolve) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'nx-modal nx-modal--sm';
    dialog.setAttribute('aria-labelledby', 'nx-confirm-title');

    const confirmClass = tone === 'danger' ? 'nx-btn nx-btn--danger' : 'nx-btn nx-btn--primary';

    dialog.innerHTML = `
      <div class="nx-modal__panel">
        <div class="nx-modal__head">
          <div>
            <h2 class="nx-modal__title" id="nx-confirm-title">${escapeHtml(title)}</h2>
            ${message ? `<p class="nx-modal__desc">${escapeHtml(message)}</p>` : ''}
          </div>
          <button class="nx-icon-btn" type="button" data-confirm-cancel aria-label="Cerrar">${icon('x')}</button>
        </div>
        <div class="nx-modal__foot">
          <button class="nx-btn nx-btn--secondary" type="button" data-confirm-cancel>${escapeHtml(cancelLabel)}</button>
          <button class="${confirmClass}" type="button" data-confirm-ok>${escapeHtml(confirmLabel)}</button>
        </div>
      </div>
    `;

    let settled = false;
    const finish = (value) => {
      if (settled) return;
      settled = true;
      if (dialog.open) dialog.close();
      dialog.remove();
      resolve(value);
    };

    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      finish(false);
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) finish(false);
      if (event.target.closest('[data-confirm-cancel]')) finish(false);
      if (event.target.closest('[data-confirm-ok]')) finish(true);
    });

    document.body.appendChild(dialog);
    initDialog(dialog);
    openModal(dialog);
  });
}
