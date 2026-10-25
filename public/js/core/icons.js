import { escapeHtml } from './dom.js';

const base = (() => {
  const value = document.body?.dataset.base || '/';
  return value.endsWith('/') ? value : `${value}/`;
})();

/**
 * Icono del sprite local. Los nombres pasan por una lista blanca para evitar
 * que datos externos controlen el atributo href.
 */
const known = new Set([
  'menu', 'x', 'search', 'plus', 'chevron-down', 'chevron-up', 'chevron-right', 'chevron-left',
  'arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'home', 'layout-dashboard', 'file-text',
  'folder', 'layers', 'book-open', 'graduation-cap', 'code', 'terminal', 'table', 'check-square',
  'quote', 'sparkles', 'compass', 'flask', 'lightbulb', 'building', 'user', 'users', 'settings',
  'log-out', 'more-horizontal', 'trash', 'check', 'check-circle', 'alert-circle', 'alert-triangle',
  'info', 'clock', 'command', 'grip-vertical', 'upload', 'download', 'paperclip', 'link', 'tag',
  'bell', 'external-link', 'loader', 'type', 'heading-2', 'heading-3', 'text', 'list', 'kanban',
  'calendar', 'message-square', 'send', 'panel-left', 'panel-right', 'history', 'refresh', 'wifi-off',
  'eye', 'eye-off', 'lock', 'mail', 'star', 'archive', 'filter', 'sliders', 'move', 'copy',
  'corner-down-left', 'pilcrow', 'columns', 'list-filter', 'circle-help', 'pencil', 'square-plus',
  'dot',
]);

export function icon(name, className = 'nx-icon') {
  const safeName = known.has(name) ? name : 'file-text';
  return `<svg class="${escapeHtml(className)}" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="${base}icons/sprite.svg#i-${safeName}"></use></svg>`;
}
