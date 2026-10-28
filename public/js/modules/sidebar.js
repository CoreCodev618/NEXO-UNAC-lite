import { lockScroll, normalizeText, qs, qsa, unlockScroll } from '../core/dom.js';

export function initSidebar() {
  const sidebar = qs('#nx-sidebar');
  const backdrop = qs('[data-sidebar-backdrop]');
  const openButton = qs('[data-sidebar-open]');
  const closeButton = qs('[data-sidebar-close]');
  if (!sidebar) return;

  const search = qs('[data-sidebar-search]', sidebar);
  search?.addEventListener('input', () => {
    const query = normalizeText(search.value);
    qsa('.nx-tree__link', sidebar).forEach((link) => {
      link.hidden = query !== '' && !normalizeText(link.textContent).includes(query);
    });
  });

  const open = () => {
    if (sidebar.classList.contains('is-open')) return;
    sidebar.classList.add('is-open');
    backdrop?.classList.add('is-visible');
    openButton?.setAttribute('aria-expanded', 'true');
    lockScroll();
    closeButton?.focus();
  };

  const close = () => {
    if (!sidebar.classList.contains('is-open')) return;
    sidebar.classList.remove('is-open');
    backdrop?.classList.remove('is-visible');
    openButton?.setAttribute('aria-expanded', 'false');
    openButton?.focus();
    unlockScroll();
  };

  openButton?.addEventListener('click', () => {
    open();
  });

  closeButton?.addEventListener('click', close);
  backdrop?.addEventListener('click', close);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
      close();
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 899) {
      close();
    }
  });
}
