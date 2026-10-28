/**
 * Utilidades de DOM compartidas.
 * Sin dependencias: el resto de módulos se apoyan en estas funciones.
 */

export const qs = (selector, root = document) => root.querySelector(selector);

export const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export function debounce(fn, wait = 180) {
  let timer = null;
  return (...args) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => fn(...args), wait);
  };
}

export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

/** Construye una URL de la aplicación a partir de data-base. */
export function appUrl(path = '') {
  const base = (document.body.dataset.base || '/').replace(/\/?$/, '/');
  const cleanPath = String(path).replace(/^\/+/, '');
  return `${base}${cleanPath}`;
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
