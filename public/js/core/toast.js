import { escapeHtml, qs } from './dom.js';
import { icon } from './icons.js';

const iconFor = {
  success: 'check-circle',
  danger: 'alert-circle',
  warning: 'alert-triangle',
  info: 'info',
};

function region() {
  return qs('[data-toast-region]') || qs('#nx-toasts');
}

export function toast(message, options = {}) {
  const target = region();
  if (!target) return null;

  const {
    title = '',
    type = 'info',
    duration = 4200,
    dismissible = true,
  } = typeof options === 'string' ? { title: options } : options;

  const safeType = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
  const element = document.createElement('div');
  element.className = `nx-toast nx-toast--${safeType}`;
  element.setAttribute('role', safeType === 'danger' ? 'alert' : 'status');

  element.innerHTML = `
    ${icon(iconFor[safeType])}
    <div class="nx-toast__body">
      ${title ? `<strong class="nx-toast__title">${escapeHtml(title)}</strong>` : ''}
      <span class="nx-toast__text">${escapeHtml(message)}</span>
    </div>
    ${dismissible ? `<button class="nx-toast__close" type="button" aria-label="Cerrar aviso">${icon('x')}</button>` : ''}
  `;

  const remove = () => {
    if (!element.isConnected || element.classList.contains('is-leaving')) return;
    element.classList.add('is-leaving');
    window.setTimeout(() => element.remove(), 220);
  };

  element.querySelector('.nx-toast__close')?.addEventListener('click', remove);
  target.appendChild(element);

  while (target.children.length > 3) {
    target.firstElementChild?.remove();
  }

  if (duration > 0) {
    window.setTimeout(remove, duration);
  }

  return element;
}

toast.success = (message, title = 'Listo') => toast(message, { type: 'success', title });
toast.error = (message, title = 'No se pudo completar') => toast(message, { type: 'danger', title });
toast.warning = (message, title = 'Atención') => toast(message, { type: 'warning', title });
toast.info = (message, title = 'Información') => toast(message, { type: 'info', title });
