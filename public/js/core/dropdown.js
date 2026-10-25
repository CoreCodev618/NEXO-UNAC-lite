import { qsa } from './dom.js';

let openMenu = null;
let openTrigger = null;

function setExpanded(trigger, expanded) {
  trigger?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
}

function closeMenu({ restoreFocus = false } = {}) {
  if (!openMenu) return;
  openMenu.hidden = true;
  setExpanded(openTrigger, false);
  if (restoreFocus && openTrigger instanceof HTMLElement) {
    openTrigger.focus();
  }
  openMenu = null;
  openTrigger = null;
}

function positionMenu(menu) {
  menu.classList.remove('nx-menu--up');
  const rect = menu.getBoundingClientRect();
  if (rect.bottom > window.innerHeight - 12) {
    menu.classList.add('nx-menu--up');
  }
}

function open(trigger) {
  const id = trigger.dataset.menuTrigger;
  const menu = document.getElementById(id);
  if (!menu) return;

  if (openMenu === menu) {
    closeMenu();
    return;
  }

  closeMenu();
  menu.hidden = false;
  setExpanded(trigger, true);
  openMenu = menu;
  openTrigger = trigger;
  positionMenu(menu);
}

export function initDropdowns() {
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-menu-trigger]');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      open(trigger);
      return;
    }

    if (openMenu && !openMenu.contains(event.target)) {
      closeMenu();
    }

    if (openMenu && event.target.closest('.nx-menu__item, [role="menuitem"]')) {
      const shouldStayOpen = event.target.closest('[data-menu-keep-open]');
      if (!shouldStayOpen) {
        closeMenu();
      }
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!openMenu) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      closeMenu({ restoreFocus: true });
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const items = qsa('[role="menuitem"], .nx-menu__item', openMenu).filter(
        (item) => item.offsetParent !== null,
      );
      if (items.length === 0) return;
      event.preventDefault();
      const current = items.indexOf(document.activeElement);
      const next =
        event.key === 'ArrowDown'
          ? (current + 1 + items.length) % items.length
          : (current - 1 + items.length) % items.length;
      items[next].focus();
    }
  });

  window.addEventListener('resize', () => {
    if (openMenu) positionMenu(openMenu);
  });

  window.addEventListener('scroll', () => {
    if (openMenu) closeMenu();
  }, { passive: true });
}
