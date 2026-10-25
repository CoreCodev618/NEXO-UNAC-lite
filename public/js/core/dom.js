/**
 * Utilidades de DOM compartidas.
 * Sin dependencias: el resto de módulos se apoyan en estas funciones.
 */

export const qs = (selector, root = document) => root.querySelector(selector);

export const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export function on(root, eventName, selector, handler, options = {}) {
  if (!root) return () => {};
  const listener = (event) => {
    const target = event.target.closest(selector);
    if (target && root.contains(target)) handler(event, target);
  };
  root.addEventListener(eventName, listener, options);
  return () => root.removeEventListener(eventName, listener, options);
}

export function debounce(fn, wait = 180) {
  let timer = null;
  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), wait);
  };
}

export function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

/**
 * Construye una URL de la aplicación respetando el prefijo /preview.
 * Los fixtures de revisión viven bajo /preview, así que un enlace relativo
 * a la raíz perdería ese prefijo.
 */
export function appUrl(path = '') {
  const base = (document.body.dataset.base || '/').replace(/\/?$/, '/');
  const cleanPath = String(path).replace(/^\/+/, '');
  const prefix = document.body.dataset.preview === '1' ? 'preview/' : '';
  return `${base}${prefix}${cleanPath}`;
}

export function uid(prefix = 'nx') {
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`;
}

export function normalizeText(value) {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

export function setHidden(element, hidden) {
  if (!element) return;
  if (hidden) {
    element.setAttribute('hidden', '');
  } else {
    element.removeAttribute('hidden');
  }
}

export function isEditing() {
  const active = document.activeElement;
  return Boolean(
    active &&
      (active.matches('input, textarea, select') ||
        active.isContentEditable ||
        active.closest('[contenteditable="true"]')),
  );
}

export function focusables(root) {
  if (!root) return [];
  return Array.from(
    root.querySelectorAll(
      'a[href], button:not([disabled]):not([hidden]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((element) => element.offsetParent !== null || element === document.activeElement);
}

export function trapFocus(container, event) {
  if (event.key !== 'Tab') return;
  const items = focusables(container);
  if (items.length === 0) {
    event.preventDefault();
    container.focus();
    return;
  }
  const first = items[0];
  const last = items[items.length - 1];

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

export function placeCaretAtEnd(element) {
  if (!element) return;
  element.focus();
  const range = document.createRange();
  const selection = window.getSelection();
  range.selectNodeContents(element);
  range.collapse(false);
  selection?.removeAllRanges();
  selection?.addRange(range);
}

export function todayIso() {
  const now = new Date();
  const offset = now.getTimezoneOffset();
  return new Date(now.getTime() - offset * 60000).toISOString().slice(0, 10);
}

export function formatDueDate(value) {
  if (!value) return 'Sin fecha';
  const parts = String(value).split('-').map(Number);
  if (parts.length !== 3 || parts.some(Number.isNaN)) return 'Sin fecha';
  const date = new Date(parts[0], parts[1] - 1, parts[2], 12);
  const today = new Date();
  today.setHours(12, 0, 0, 0);
  const diff = Math.round((date - today) / 86400000);

  if (diff === 0) return 'Hoy';
  if (diff === 1) return 'Mañana';
  if (diff === -1) return 'Ayer';

  const format = new Intl.DateTimeFormat('es-PE', {
    day: 'numeric',
    month: 'short',
    ...(date.getFullYear() !== today.getFullYear() ? { year: 'numeric' } : {}),
  });

  return format.format(date).replace('.', '');
}

export function isOverdue(value) {
  if (!value) return false;
  return String(value) < todayIso();
}

let scrollLocks = 0;

export function lockScroll() {
  scrollLocks += 1;
  if (scrollLocks === 1) {
    document.documentElement.style.overflow = 'hidden';
  }
}

export function unlockScroll() {
  scrollLocks = Math.max(0, scrollLocks - 1);
  if (scrollLocks === 0) {
    document.documentElement.style.overflow = '';
  }
}

export function dispatch(element, name, detail = {}) {
  if (!element) return;
  element.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}
