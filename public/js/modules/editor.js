import { api, ApiError } from '../core/api.js';
import {
  appUrl,
  debounce,
  escapeHtml,
  qs,
  qsa,
  uid,
} from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toast } from '../core/toast.js';

const TYPE_DESCRIPTORS = [
  { key: 'p', label: 'Texto', description: 'Un párrafo normal', icon: 'pilcrow', type: 'parrafo', style: 'p' },
  { key: 'h2', label: 'Título', description: 'Sección principal', icon: 'heading-2', type: 'parrafo', style: 'h2' },
  { key: 'h3', label: 'Subtítulo', description: 'Sección dentro de un tema', icon: 'heading-3', type: 'parrafo', style: 'h3' },
  { key: 'lead', label: 'Entradilla', description: 'Un párrafo de apertura', icon: 'type', type: 'parrafo', style: 'lead' },
  { key: 'checklist', label: 'Lista de tareas', description: 'Elementos para marcar', icon: 'check-square', type: 'checklist' },
  { key: 'codigo', label: 'Código', description: 'Bloque con lenguaje y copiado', icon: 'code', type: 'codigo' },
  { key: 'tabla', label: 'Tabla', description: 'Filas y columnas editables', icon: 'table', type: 'tabla' },
  { key: 'quote', label: 'Cita', description: 'Una idea con su fuente', icon: 'quote', type: 'quote' },
  { key: 'callout', label: 'Aviso', description: 'Información destacada', icon: 'sparkles', type: 'callout' },
];

let editor = null;
let blocksRoot = null;
let pageId = 0;
let deletedIds = [];
let slashMenu = null;
let slashTarget = null;
let slashReplace = false;
let slashActive = 0;
let dirty = false;
let saving = false;
let pendingSave = false;
let lastErrorToast = 0;
let lastCell = null;

function blockIdForNew() {
  return `new-${uid('b')}`;
}

function normalizeId(value) {
  const string = String(value ?? '');
  return /^\d+$/.test(string) ? Number(string) : string;
}

function menu(id) {
  return `
    <div class="nx-dropdown">
      <button class="nx-icon-btn" type="button" data-menu-trigger="block-menu-${escapeHtml(id)}" aria-expanded="false" aria-label="Opciones del bloque">${icon('more-horizontal')}</button>
      <div class="nx-menu nx-block__menu" id="block-menu-${escapeHtml(id)}" hidden>
        <div class="nx-menu__label">Bloque</div>
        <button class="nx-menu__item" type="button" data-block-action="duplicate">${icon('copy')}<span>Duplicar</span><span class="nx-menu__shortcut">⌘ D</span></button>
        <button class="nx-menu__item" type="button" data-block-action="up">${icon('arrow-up')}<span>Mover arriba</span></button>
        <button class="nx-menu__item" type="button" data-block-action="down">${icon('arrow-down')}<span>Mover abajo</span></button>
        <div class="nx-menu__separator"></div>
        <button class="nx-menu__item nx-menu__item--danger" type="button" data-block-action="delete">${icon('trash')}<span>Eliminar bloque</span></button>
      </div>
    </div>
  `;
}

function shell(id, type, main, extraClass = '') {
  const element = document.createElement('div');
  element.className = 'nx-block';
  element.dataset.blockId = String(id);
  element.dataset.blockType = type;
  if (extraClass) element.classList.add(extraClass);
  element.innerHTML = `
    <div class="nx-block__gutter">
      <button type="button" data-block-add aria-label="Insertar bloque">${icon('plus')}</button>
      <button type="button" data-block-drag aria-label="Mover bloque">${icon('grip-vertical')}</button>
    </div>
    <div class="nx-block__main">${main}</div>
    <div class="nx-block__actions">${menu(id)}</div>
  `;
  return element;
}

function paragraphMarkup(content = {}) {
  const style = ['p', 'h2', 'h3', 'lead'].includes(content.style) ? content.style : 'p';
  const styleClass = style === 'p' ? '' : ` nx-block__editor--${style}`;
  return `<div class="nx-block__editor${styleClass}" contenteditable="true" spellcheck="true" role="textbox" aria-multiline="true" data-field="text" data-style="${style}" data-placeholder="Escribe algo, o usa / para insertar un bloque">${escapeHtml(content.text || '')}</div>`;
}

function checklistItemMarkup(item = {}) {
  const checked = Boolean(item.checked);
  return `
    <div class="nx-checklist__item${checked ? ' is-checked' : ''}">
      <button class="nx-checklist__box" type="button" data-check aria-pressed="${checked ? 'true' : 'false'}" aria-label="Marcar elemento">${icon('check')}</button>
      <div class="nx-checklist__text" contenteditable="true" data-field="text" data-placeholder="Elemento de la lista">${escapeHtml(item.text || '')}</div>
      <button class="nx-checklist__remove" type="button" data-check-remove aria-label="Eliminar elemento">${icon('x')}</button>
    </div>
  `;
}

function checklistMarkup(content = {}) {
  const items = Array.isArray(content.items) && content.items.length > 0 ? content.items : [{ text: '', checked: false }];
  return `
    <div class="nx-checklist" data-checklist>${items.map(checklistItemMarkup).join('')}</div>
    <button class="nx-checklist__add" type="button" data-checklist-add>${icon('plus')} Añadir elemento</button>
  `;
}

function codeMarkup(content = {}) {
  const languages = ['python', 'javascript', 'php', 'sql', 'bash', 'json', 'html', 'css', 'texto'];
  const language = languages.includes(content.language) ? content.language : 'texto';
  const id = uid('code');
  return `
    <div class="nx-codeblock">
      <div class="nx-codeblock__head">
        <label class="nx-sr-only" for="lang-${id}">Lenguaje del bloque de código</label>
        <select class="nx-codeblock__lang" id="lang-${id}" data-field="language">
          ${languages.map((entry) => `<option value="${entry}"${entry === language ? ' selected' : ''}>${entry}</option>`).join('')}
        </select>
        <button class="nx-codeblock__copy" type="button" data-copy-code>${icon('copy')} Copiar</button>
      </div>
      <label class="nx-sr-only" for="area-${id}">Código</label>
      <textarea class="nx-codeblock__area" id="area-${id}" rows="5" spellcheck="false" data-field="code">${escapeHtml(content.code || '')}</textarea>
    </div>
  `;
}

function tableMarkup(content = {}) {
  const headers = Array.isArray(content.headers) && content.headers.length > 0 ? content.headers : ['Columna 1', 'Columna 2'];
  const rows = Array.isArray(content.rows) && content.rows.length > 0 ? content.rows : [headers.map(() => '')];
  return `
    <div class="nx-tableblock" data-table>
      <table>
        <thead><tr>${headers.map((value) => `<th><div class="nx-tableblock__cell" contenteditable="true" data-field="header" data-placeholder="Encabezado">${escapeHtml(value)}</div></th>`).join('')}</tr></thead>
        <tbody>
          ${rows.map((row) => `<tr>${headers.map((_, index) => `<td><div class="nx-tableblock__cell" contenteditable="true" data-field="cell" data-placeholder="Celda">${escapeHtml(row?.[index] || '')}</div></td>`).join('')}</tr>`).join('')}
        </tbody>
      </table>
      <div class="nx-tableblock__tools">
        <button type="button" data-table-row>${icon('plus')} Fila</button>
        <button type="button" data-table-col>${icon('plus')} Columna</button>
        <button type="button" data-table-del-row>${icon('x')} Quitar fila</button>
        <button type="button" data-table-del-col>${icon('x')} Quitar columna</button>
      </div>
    </div>
  `;
}

function quoteMarkup(content = {}) {
  return `
    <div class="nx-quoteblock">
      <div class="nx-quoteblock__text" contenteditable="true" data-field="text" data-placeholder="Una idea que merece quedarse">${escapeHtml(content.text || '')}</div>
      <input class="nx-quoteblock__cite" type="text" data-field="cite" value="${escapeHtml(content.cite || '')}" placeholder="Fuente (opcional)">
    </div>
  `;
}

function calloutMarkup(content = {}) {
  const variant = ['info', 'success', 'warning', 'danger'].includes(content.variant) ? content.variant : 'info';
  const variantIcon = { success: 'check-circle', warning: 'alert-triangle', danger: 'alert-circle', info: 'info' }[variant];
  return `
    <div class="nx-callout nx-callout--${variant}" data-variant="${variant}">
      <span class="nx-callout__icon" aria-hidden="true">${icon(variantIcon)}</span>
      <div class="nx-callout__body">
        <div class="nx-callout__title" contenteditable="true" data-field="title" data-placeholder="Título del aviso">${escapeHtml(content.title || '')}</div>
        <div class="nx-callout__text" contenteditable="true" data-field="text" data-placeholder="Escribe el detalle…">${escapeHtml(content.text || '')}</div>
      </div>
    </div>
  `;
}

function buildBlock(type, content = {}, style = 'p', id = blockIdForNew()) {
  switch (type) {
    case 'checklist':
      return shell(id, type, checklistMarkup(content));
    case 'codigo':
      return shell(id, type, codeMarkup(content));
    case 'tabla':
      return shell(id, type, tableMarkup(content));
    case 'quote':
      return shell(id, type, quoteMarkup(content));
    case 'callout':
      return shell(id, type, calloutMarkup(content));
    default:
      return shell(id, 'parrafo', paragraphMarkup({ ...content, style }));
  }
}

function readBlock(block) {
  const type = block.dataset.blockType || 'parrafo';
  const id = normalizeId(block.dataset.blockId);
  let content = {};

  if (type === 'parrafo') {
    const field = qs('[data-field="text"]', block);
    content = {
      style: field?.dataset.style || 'p',
      text: (field?.innerText || '').replace(/\u00a0/g, ' ').replace(/\n{3,}/g, '\n\n').trimEnd(),
    };
  } else if (type === 'checklist') {
    content = {
      items: qsa('.nx-checklist__item', block).map((item) => ({
        text: (qs('.nx-checklist__text', item)?.innerText || '').trim(),
        checked: item.classList.contains('is-checked'),
      })),
    };
  } else if (type === 'codigo') {
    content = {
      language: qs('[data-field="language"]', block)?.value || 'texto',
      code: qs('[data-field="code"]', block)?.value || '',
    };
  } else if (type === 'tabla') {
    const headers = qsa('thead th .nx-tableblock__cell', block).map((cell) => (cell.innerText || '').trim());
    const rows = qsa('tbody tr', block).map((row) =>
      qsa('.nx-tableblock__cell', row).map((cell) => (cell.innerText || '').trim()),
    );
    content = { headers, rows };
  } else if (type === 'quote') {
    content = {
      text: (qs('[data-field="text"]', block)?.innerText || '').trim(),
      cite: qs('[data-field="cite"]', block)?.value.trim() || '',
    };
  } else if (type === 'callout') {
    const callout = qs('.nx-callout', block);
    content = {
      variant: callout?.dataset.variant || 'info',
      title: (qs('[data-field="title"]', block)?.innerText || '').trim(),
      text: (qs('[data-field="text"]', block)?.innerText || '').trim(),
    };
  }

  return { id, type, content };
}

function collect() {
  const title = (qs('[data-page-title-input]')?.innerText || '').replace(/\u00a0/g, ' ').trim();
  const blocks = qsa('.nx-block', blocksRoot).map((block, index) => {
    const data = readBlock(block);
    data.order_index = index;
    return data;
  });
  return { title, blocks };
}

function saveState(state, label) {
  const element = qs('#nx-save-state');
  if (!element) return;
  element.dataset.state = state;
  const text = qs('.nx-save-state__text', element);
  if (text) text.textContent = label;
  const use = qs('use', element);
  if (use) {
    const iconName = state === 'error' ? 'alert-circle' : state === 'saving' ? 'loader' : 'check-circle';
    const base = (document.body.dataset.base || '/').replace(/\/?$/, '/');
    use.setAttribute('href', `${base}icons/sprite.svg#i-${iconName}`);
  }
}

function updatePageChrome(title) {
  const safeTitle = title || 'Página sin título';
  document.title = `${safeTitle} · NEXO UNAC`;
  document.body.dataset.pageTitle = safeTitle;
  const current = qs('.nx-breadcrumb__current');
  if (current) current.textContent = safeTitle;
  const link = qs('#nx-sidebar .nx-tree__link.is-active span');
  if (link) link.textContent = safeTitle;
}

/** Añade la página recién creada a «Mis apuntes» (mismo markup que nexo_page_tree). */
function addToSidebar(id, title) {
  let tree = qs('#nx-sidebar .nx-tree');
  if (!tree) {
    tree = document.createElement('div');
    tree.className = 'nx-tree';
    const empty = qs('#nx-sidebar .nx-tree__more');
    if (empty) empty.replaceWith(tree);
    else qs('#nx-sidebar .nx-nav__new')?.before(tree);
  }
  qsa('.nx-tree__link.is-active', tree).forEach((link) => {
    link.classList.remove('is-active');
    link.removeAttribute('aria-current');
  });
  const link = document.createElement('a');
  link.className = 'nx-tree__link is-active';
  link.style.setProperty('--depth', '0');
  link.href = appUrl(`pages/${id}`);
  link.setAttribute('aria-current', 'page');
  link.innerHTML = `${icon('file-text')}<span>${escapeHtml(title || 'Página sin título')}</span>`;
  tree.appendChild(link);
}

function applyCreatedIds(results) {
  (results || []).forEach((result) => {
    if (!result?.tempId || !result?.data?.id) return;
    const block = qsa('.nx-block', blocksRoot).find((candidate) => candidate.dataset.blockId === String(result.tempId));
    if (block) {
      block.dataset.blockId = String(result.data.id);
    } else if (!/^\d+$/.test(String(result.tempId))) {
      // El bloque se creó en el servidor pero se borró mientras se guardaba.
      deletedIds.push(Number(result.data.id));
      markDirty();
    }
  });
}

async function persist() {
  if (!editor || !blocksRoot) return;

  if (saving) {
    dirty = true;
    pendingSave = true;
    return;
  }

  saving = true;
  dirty = false;
  saveState('saving', 'Guardando…');

  const { title, blocks } = collect();
  const finalTitle = title || 'Página sin título';
  updatePageChrome(finalTitle);

  const sentDeletedIds = deletedIds;
  deletedIds = [];

  try {
    if (pageId <= 0) {
      const created = await api.pages.create({
        workspace_id: Number(editor.dataset.pageWorkspace || document.body.dataset.workspaceId || 0),
        parent_id: null,
        title: finalTitle,
      });

      const newId = Number(created?.id || created?.page?.id || 0);
      if (!newId) throw new ApiError('El servidor no devolvió el identificador de la página.', 422, created);

      pageId = newId;
      editor.dataset.pageId = String(pageId);
      document.body.dataset.pageId = String(pageId);
      window.history.replaceState({}, '', appUrl(`pages/${pageId}`));
      addToSidebar(pageId, finalTitle);
    }

    const results = await api.blocks.savePage(pageId, {
      title: finalTitle,
      blocks,
      deletedIds: sentDeletedIds,
    });

    applyCreatedIds(results);
    if (!dirty) saveState('saved', 'Guardado');
  } catch (error) {
    deletedIds = [...sentDeletedIds, ...deletedIds];
    dirty = true;
    saveState('error', 'Sin guardar');

    if (Date.now() - lastErrorToast > 10000) {
      lastErrorToast = Date.now();
      if (error instanceof ApiError && error.unavailable) {
        toast.warning(
          'El endpoint de páginas y bloques todavía no está disponible. El documento no se guardó en el servidor.',
          'Guardado pendiente',
        );
      } else {
        toast.error(error.message || 'No se pudo guardar la página.');
      }
    }
  } finally {
    saving = false;
    if (pendingSave) {
      pendingSave = false;
      scheduleSave();
    }
  }
}

const scheduleSave = debounce(() => {
  if (dirty) persist();
}, 700);

function markDirty() {
  dirty = true;
  scheduleSave();
}

function insertAfter(reference, element) {
  if (reference?.parentElement) {
    reference.after(element);
  } else {
    blocksRoot.appendChild(element);
  }
}

function focusEditable(block) {
  const target = qs('[contenteditable="true"], textarea', block);
  if (target) {
    target.focus();
    if (target.isContentEditable) {
      const range = document.createRange();
      const selection = window.getSelection();
      range.selectNodeContents(target);
      range.collapse(false);
      selection?.removeAllRanges();
      selection?.addRange(range);
    }
  }
}

function addBlockAfter(reference, descriptor, { replaceEmpty = true } = {}) {
  const emptyParagraph =
    reference?.dataset.blockType === 'parrafo' &&
    !(qs('[data-field="text"]', reference)?.innerText || '').trim();

  const block = buildBlock(descriptor.type, {}, descriptor.style || 'p');

  if (reference && replaceEmpty && emptyParagraph) {
    reference.replaceWith(block);
  } else if (reference) {
    insertAfter(reference, block);
  } else {
    blocksRoot.appendChild(block);
  }

  if (!qs('.nx-block', blocksRoot)) {
    blocksRoot.appendChild(buildBlock('parrafo'));
  }

  focusEditable(block);
  markDirty();
  return block;
}

function removeBlock(block) {
  if (!block) return;
  const id = String(block.dataset.blockId || '');
  if (/^\d+$/.test(id)) deletedIds.push(Number(id));
  const next = block.nextElementSibling || block.previousElementSibling;
  block.remove();
  if (!qs('.nx-block', blocksRoot)) {
    blocksRoot.appendChild(buildBlock('parrafo'));
  }
  if (next) focusEditable(next);
  markDirty();
}

function moveBlock(block, direction) {
  if (!block) return;
  const sibling = direction === 'up' ? block.previousElementSibling : block.nextElementSibling;
  if (!sibling) return;
  if (direction === 'up') blocksRoot.insertBefore(block, sibling);
  else blocksRoot.insertBefore(sibling, block);
  focusEditable(block);
  markDirty();
}

function duplicateBlock(block) {
  if (!block) return;
  const data = readBlock(block);
  const clone = buildBlock(data.type, data.content, data.content.style || 'p');
  insertAfter(block, clone);
  focusEditable(clone);
  markDirty();
}

function addChecklistItem(list, reference = null) {
  if (!list) return null;
  const item = document.createElement('div');
  item.innerHTML = checklistItemMarkup({ text: '', checked: false });
  const element = item.firstElementChild;
  if (reference) reference.after(element);
  else list.appendChild(element);
  focusEditable(element);
  markDirty();
  return element;
}

function handleTableAction(action, button) {
  const table = button.closest('[data-table]');
  if (!table) return;
  const body = qs('tbody', table);
  const headerRow = qs('thead tr', table);

  if (action === 'row') {
    const row = document.createElement('tr');
    const count = qsa('th', headerRow).length;
    for (let index = 0; index < count; index += 1) {
      const cell = document.createElement('td');
      cell.innerHTML = '<div class="nx-tableblock__cell" contenteditable="true" data-field="cell" data-placeholder="Celda"></div>';
      row.appendChild(cell);
    }
    body?.appendChild(row);
    row.querySelector('[contenteditable]')?.focus();
  }

  if (action === 'col') {
    const th = document.createElement('th');
    th.innerHTML = '<div class="nx-tableblock__cell" contenteditable="true" data-field="header" data-placeholder="Encabezado"></div>';
    headerRow?.appendChild(th);
    qsa('tbody tr', table).forEach((row) => {
      const cell = document.createElement('td');
      cell.innerHTML = '<div class="nx-tableblock__cell" contenteditable="true" data-field="cell" data-placeholder="Celda"></div>';
      row.appendChild(cell);
    });
  }

  if (action === 'del-row') {
    const rows = qsa('tbody tr', table);
    const activeRow = lastCell?.closest('tr');
    const row = activeRow && body?.contains(activeRow) ? activeRow : rows[rows.length - 1];
    if (!row) return;
    if (rows.length <= 1) {
      qsa('.nx-tableblock__cell', row).forEach((cell) => {
        cell.textContent = '';
      });
    } else {
      row.remove();
    }
  }

  if (action === 'del-col') {
    const headers = qsa('thead th', table);
    const activeCell = lastCell && table.contains(lastCell) ? lastCell.closest('th, td') : null;
    const index = activeCell ? activeCell.cellIndex : headers.length - 1;

    if (headers.length <= 1) {
      qsa('.nx-tableblock__cell', table).forEach((cell) => {
        cell.textContent = '';
      });
    } else {
      qsa('tr', table).forEach((row) => {
        row.children[index]?.remove();
      });
    }
  }

  markDirty();
}

function openSlash(reference, anchor, { replace = true } = {}) {
  closeSlash();
  slashTarget = reference || null;
  slashReplace = replace;
  slashActive = 0;

  slashMenu = document.createElement('div');
  slashMenu.className = 'nx-slash';
  slashMenu.setAttribute('role', 'menu');
  slashMenu.innerHTML = `
    <div class="nx-slash__label">Insertar bloque</div>
    ${TYPE_DESCRIPTORS.map((descriptor, index) => `
      <button class="nx-slash__item${index === 0 ? ' is-active' : ''}" type="button" role="menuitem" data-slash-key="${descriptor.key}" data-index="${index}">
        ${icon(descriptor.icon)}
        <span class="nx-slash__item-text">
          <span class="nx-slash__item-title">${descriptor.label}</span>
          <span class="nx-slash__item-desc">${descriptor.description}</span>
        </span>
      </button>
    `).join('')}
  `;

  document.body.appendChild(slashMenu);

  const rect = anchor?.getBoundingClientRect?.() || { left: 16, bottom: 80 };
  const width = 286;
  const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 12);
  const estimatedHeight = Math.min(326, 48 + TYPE_DESCRIPTORS.length * 46);
  const top = rect.bottom + estimatedHeight + 12 > window.innerHeight ? rect.top - estimatedHeight - 6 : rect.bottom + 6;

  slashMenu.style.left = `${left + window.scrollX}px`;
  slashMenu.style.top = `${Math.max(8, top) + window.scrollY}px`;

  const choose = (key) => {
    const descriptor = TYPE_DESCRIPTORS.find((entry) => entry.key === key);
    if (!descriptor) return;
    const target = slashTarget;
    closeSlash();
    addBlockAfter(target, descriptor, { replaceEmpty: slashReplace });
  };

  slashMenu.addEventListener('click', (event) => {
    const item = event.target.closest('[data-slash-key]');
    if (item) choose(item.dataset.slashKey);
  });

  slashMenu.addEventListener('mousemove', (event) => {
    const item = event.target.closest('[data-slash-key]');
    if (item) setSlashActive(Number(item.dataset.index));
  });

  const onKeyDown = (event) => {
    if (!slashMenu) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      closeSlash();
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const next = event.key === 'ArrowDown'
        ? (slashActive + 1) % TYPE_DESCRIPTORS.length
        : (slashActive - 1 + TYPE_DESCRIPTORS.length) % TYPE_DESCRIPTORS.length;
      setSlashActive(next);
      return;
    }

    if (event.key === 'Enter') {
      event.preventDefault();
      choose(TYPE_DESCRIPTORS[slashActive]?.key);
    }
  };

  const onPointerDown = (event) => {
    if (!slashMenu?.contains(event.target)) closeSlash();
  };

  document.addEventListener('keydown', onKeyDown, true);
  document.addEventListener('pointerdown', onPointerDown, true);

  slashMenu.dataset.keyHandler = '1';
  slashMenu._cleanup = () => {
    document.removeEventListener('keydown', onKeyDown, true);
    document.removeEventListener('pointerdown', onPointerDown, true);
  };
}

function setSlashActive(index) {
  if (!slashMenu) return;
  slashActive = index;
  qsa('[data-slash-key]', slashMenu).forEach((item, itemIndex) => {
    item.classList.toggle('is-active', itemIndex === index);
  });
  qsa('[data-slash-key]', slashMenu)[index]?.scrollIntoView({ block: 'nearest' });
}

function closeSlash() {
  if (!slashMenu) return;
  slashMenu._cleanup?.();
  slashMenu.remove();
  slashMenu = null;
  slashTarget = null;
}

function handleBlockAction(action, button) {
  const block = button.closest('.nx-block');
  if (!block) return;
  if (action === 'duplicate') duplicateBlock(block);
  if (action === 'up') moveBlock(block, 'up');
  if (action === 'down') moveBlock(block, 'down');
  if (action === 'delete') removeBlock(block);
}

function bindEditorEvents() {
  blocksRoot.addEventListener('input', (event) => {
    if (event.target.closest('.nx-block')) markDirty();
  });

  blocksRoot.addEventListener('click', (event) => {
    const add = event.target.closest('[data-block-add]');
    if (add) {
      event.preventDefault();
      const block = add.closest('.nx-block');
      const field = qs('[data-field="text"]', block);
      const empty = block?.dataset.blockType === 'parrafo' && !(field?.innerText || '').trim();
      openSlash(block, add, { replace: empty });
      return;
    }

    const actionButton = event.target.closest('[data-block-action]');
    if (actionButton) {
      event.preventDefault();
      handleBlockAction(actionButton.dataset.blockAction, actionButton);
      return;
    }

    const check = event.target.closest('[data-check]');
    if (check) {
      const item = check.closest('.nx-checklist__item');
      if (item) {
        const checked = !item.classList.contains('is-checked');
        item.classList.toggle('is-checked', checked);
        check.setAttribute('aria-pressed', checked ? 'true' : 'false');
        markDirty();
      }
      return;
    }

    const removeItem = event.target.closest('[data-check-remove]');
    if (removeItem) {
      const item = removeItem.closest('.nx-checklist__item');
      const list = removeItem.closest('[data-checklist]');
      if (item && list) {
        item.remove();
        if (!qs('.nx-checklist__item', list)) addChecklistItem(list);
        markDirty();
      }
      return;
    }

    const addItem = event.target.closest('[data-checklist-add]');
    if (addItem) {
      const list = addItem.closest('.nx-block')?.querySelector('[data-checklist]');
      addChecklistItem(list);
      return;
    }

    const copy = event.target.closest('[data-copy-code]');
    if (copy) {
      const area = copy.closest('.nx-codeblock')?.querySelector('[data-field="code"]');
      if (!area?.value) {
        toast.info('El bloque de código está vacío.', 'Nada que copiar');
        return;
      }
      navigator.clipboard.writeText(area.value).then(() => {
        copy.innerHTML = `${icon('check')} Copiado`;
        window.setTimeout(() => {
          copy.innerHTML = `${icon('copy')} Copiar`;
        }, 1500);
      }).catch(() => toast.warning('El navegador no permitió copiar el código.', 'No se pudo copiar'));
      return;
    }

    const tableButton = event.target.closest('[data-table-row], [data-table-col], [data-table-del-row], [data-table-del-col]');
    if (tableButton) {
      const action = tableButton.matches('[data-table-row]') ? 'row'
        : tableButton.matches('[data-table-col]') ? 'col'
        : tableButton.matches('[data-table-del-row]') ? 'del-row' : 'del-col';
      handleTableAction(action, tableButton);
    }
  });

  blocksRoot.addEventListener('keydown', (event) => {
    const block = event.target.closest('.nx-block');

    if (event.key === 'Enter' && event.target.closest('.nx-checklist')) {
      event.preventDefault();
      const list = event.target.closest('[data-checklist]');
      const item = event.target.closest('.nx-checklist__item');
      addChecklistItem(list, item);
      return;
    }

    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'd' && block) {
      event.preventDefault();
      duplicateBlock(block);
      return;
    }

    if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown') && block) {
      event.preventDefault();
      moveBlock(block, event.key === 'ArrowUp' ? 'up' : 'down');
      return;
    }

    const slashField = event.target.closest('.nx-block__editor[data-field="text"]');
    if (event.key === '/' && slashField && !event.ctrlKey && !event.metaKey) {
      const text = (slashField.innerText || '').trim();
      if (text === '') {
        event.preventDefault();
        openSlash(block, slashField, { replace: true });
      }
    }
  });

  blocksRoot.addEventListener('paste', (event) => {
    if (!event.target.closest('[contenteditable="true"]')) return;
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    document.execCommand('insertText', false, text);
  });

  blocksRoot.addEventListener('focusin', (event) => {
    const cell = event.target.closest('.nx-tableblock__cell');
    if (cell) lastCell = cell;
  });
}

function bindPageEvents() {
  const title = qs('[data-page-title-input]');
  title?.addEventListener('input', () => {
    updatePageChrome((title.innerText || '').trim());
    markDirty();
  });
  title?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      title.blur();
    }
  });
  title?.addEventListener('paste', (event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    document.execCommand('insertText', false, text);
  });

  qsa('[data-editor-add]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const last = qsa('.nx-block', blocksRoot).at(-1) || null;
      openSlash(last, button, { replace: false });
    });
  });

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      persist();
    }
  });

  window.addEventListener('beforeunload', (event) => {
    if (dirty || saving) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
}

export function initEditor() {
  editor = qs('[data-editor]');
  if (!editor) return;

  blocksRoot = qs('[data-blocks]', editor);
  pageId = Number(editor.dataset.pageId || 0);

  if (!qs('.nx-block', blocksRoot)) {
    blocksRoot.appendChild(buildBlock('parrafo'));
  }

  bindEditorEvents();
  bindPageEvents();

  if (window.Sortable) {
    new window.Sortable(blocksRoot, {
      animation: 150,
      handle: '[data-block-drag]',
      draggable: '.nx-block',
      ghostClass: 'is-dragging',
      onEnd() {
        markDirty();
      },
    });
  }

  const initialTitle = (qs('[data-page-title-input]')?.innerText || '').trim();
  if (initialTitle) updatePageChrome(initialTitle);
}
