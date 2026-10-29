import { api, ApiError } from '../core/api.js';
import { appUrl, escapeHtml, qs, qsa } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toast } from '../core/toast.js';

let chat = null;
let sessionId = null;
let sending = false;

function initials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function scrollToEnd() {
  const body = qs('[data-chat-body]', chat);
  if (body) body.scrollTop = body.scrollHeight;
}

function hideIntro() {
  const intro = qs('[data-chat-intro]', chat);
  if (intro) intro.hidden = true;
}

function messageShell(role, html) {
  const user = role === 'user';
  const wrapper = document.createElement('div');
  wrapper.className = `nx-msg${user ? ' nx-msg--user' : ''}`;
  wrapper.dataset.chatMessage = '';

  const avatar = user
    ? `<span class="nx-avatar nx-avatar--sm nx-avatar--wine nx-msg__avatar" aria-hidden="true">${escapeHtml(initials(document.body.dataset.userName || ''))}</span>`
    : `<span class="nx-msg__avatar nx-chat__head-icon" aria-hidden="true">${icon('sparkles')}</span>`;

  wrapper.innerHTML = `
    ${avatar}
    <div class="nx-msg__main">
      <div class="nx-msg__bubble">${html}</div>
      <div class="nx-msg__meta">
        <span>${user ? 'Tú' : 'Tutor NEXO'} · ahora</span>
        ${!user ? `<button type="button" data-copy-message>Copiar</button>` : ''}
        ${!user ? `<button type="button" data-save-note hidden>Guardar como apunte</button>` : ''}
      </div>
    </div>
  `;

  return wrapper;
}

function appendMessage(role, html) {
  const messages = qs('[data-chat-messages]', chat);
  if (!messages) return null;
  hideIntro();
  const element = messageShell(role, html);
  messages.appendChild(element);
  scrollToEnd();
  return element;
}

function appendUserMessage(text) {
  appendMessage('user', escapeHtml(text));
}

function appendFallback() {
  const element = appendMessage(
    'assistant',
    'No hay conexión con el tutor. Intenta de nuevo en unos segundos.',
  );

  const main = qs('.nx-msg__main', element);
  const note = document.createElement('div');
  note.className = 'nx-msg__note';
  note.innerHTML = `${icon('info')}<span>El servicio del tutor no está disponible ahora. Tus apuntes siguen funcionando.</span>`;
  main?.appendChild(note);
  scrollToEnd();
}

function appendError(message) {
  appendMessage(
    'assistant',
    `${escapeHtml(message)}<div class="nx-msg__note">${icon('alert-triangle')}<span>No se guardó nada de esta conversación. Intenta de nuevo cuando el servicio esté disponible.</span></div>`,
  );
}

// Lo que el tutor va haciendo, para que se note que no se trabó.
const THINKING_STEPS = [
  [0, 'Buscando en tu material…'],
  [3, 'Leyendo los fragmentos que encontré…'],
  [7, 'Escribiendo la respuesta…'],
  [20, 'Sigo escribiendo, es una respuesta larga…'],
];

function appendTyping() {
  const messages = qs('[data-chat-messages]', chat);
  if (!messages) return null;
  const wrapper = document.createElement('div');
  wrapper.className = 'nx-msg';
  wrapper.dataset.chatTyping = '';
  wrapper.innerHTML = `
    <span class="nx-msg__avatar nx-chat__head-icon" aria-hidden="true">${icon('sparkles')}</span>
    <div class="nx-msg__main">
      <div class="nx-msg__bubble nx-thinking">
        <span class="nx-typing" aria-hidden="true"><span></span><span></span><span></span></span>
        <span class="nx-thinking__text" role="status" data-thinking-text>${THINKING_STEPS[0][1]}</span>
        <span class="nx-thinking__time" data-thinking-time>0 s</span>
      </div>
    </div>
  `;
  messages.appendChild(wrapper);
  scrollToEnd();

  const started = Date.now();
  const timer = window.setInterval(() => {
    if (!wrapper.isConnected) return window.clearInterval(timer);
    const seconds = Math.round((Date.now() - started) / 1000);
    const step = THINKING_STEPS.filter(([at]) => seconds >= at).pop();
    qs('[data-thinking-text]', wrapper).textContent = step[1];
    qs('[data-thinking-time]', wrapper).textContent = `${seconds} s`;
  }, 1000);

  return wrapper;
}

function renderSources(data) {
  const sources = Array.isArray(data?.sources) ? data.sources : Array.isArray(data?.citations) ? data.citations : [];
  if (sources.length === 0) return null;

  const container = document.createElement('div');
  container.className = 'nx-msg__citations';
  container.innerHTML = '<span class="nx-msg__citations-label">Fuentes del material</span>';

  sources.slice(0, 5).forEach((source) => {
    const label = source.title || source.page_title || 'Tu material';
    const detail = [source.location, source.topic].filter(Boolean).join(' · ');
    const href = source.url || (source.page_id ? appUrl(`pages/${source.page_id}`) : null);
    const element = document.createElement(href ? 'a' : 'span');
    element.className = 'nx-msg__citation';
    if (href) element.href = href;
    element.innerHTML = `
      ${icon('file-text')}
      <span class="nx-msg__citation-main">
        <strong>${escapeHtml(label)}</strong>
        ${detail ? `<small>${escapeHtml(detail)}</small>` : ''}
      </span>
    `;
    container.appendChild(element);
  });

  return container;
}

async function send(message) {
  const text = String(message || '').trim();
  if (!text || sending) return;

  const input = qs('[data-chat-input]', chat);
  const sendButton = qs('[data-chat-send]', chat);
  if (!input || !sendButton) return;

  sending = true;
  sendButton.disabled = true;
  input.value = '';
  input.style.height = 'auto';
  appendUserMessage(text);

  const typing = appendTyping();

  try {
    const pageId = Number(chat.dataset.pageId || 0);
    const docId = Number(chat.dataset.docId || 0);
    const response = await api.chat.send({
      message: text,
      page_id: pageId > 0 ? pageId : null,
      document_id: docId > 0 ? docId : null,
      session_id: sessionId,
    });

    typing?.remove();
    sessionId = response?.session_id || response?.sessionId || sessionId;

    const reply =
      response?.reply ||
      response?.answer ||
      response?.message?.content ||
      response?.message ||
      response?.content;

    if (!reply) {
      appendFallback();
      return;
    }

    const safeReply = escapeHtml(String(reply)).replace(/\r?\n/g, '<br>');
    const element = appendMessage('assistant', safeReply);
    const sourceList = Array.isArray(response?.sources) ? response.sources : [];
    const saveButton = qs('[data-save-note]', element);
    if (saveButton && (sourceList.length > 0 || pageId > 0)) {
      saveButton.hidden = false;
      saveButton.dataset.question = text;
      saveButton.dataset.documentId = String(sourceList[0]?.doc_id || docId || '');
    }
    if (response?.degraded) {
      const note = document.createElement('div');
      note.className = 'nx-msg__note';
      note.innerHTML = `${icon('alert-triangle')}<span>Respondí sin consultar tu material: el buscador de material falló.</span>`;
      qs('.nx-msg__main', element)?.appendChild(note);
    }
    const sources = renderSources(response);
    if (sources) {
      qs('.nx-msg__main', element)?.appendChild(sources);
      scrollToEnd();
    }
  } catch (error) {
    typing?.remove();
    if (error instanceof ApiError && error.unavailable) {
      appendFallback();
    } else {
      appendError(error.message || 'No se pudo contactar al tutor.');
    }
  } finally {
    sending = false;
    sendButton.disabled = false;
    input.focus();
  }
}

function resetConversation() {
  sessionId = null;
  const messages = qs('[data-chat-messages]', chat);
  const intro = qs('[data-chat-intro]', chat);
  if (messages) messages.innerHTML = '';
  if (intro) intro.hidden = false;
  qs('[data-chat-input]', chat)?.focus();
  toast.info('Empezamos una conversación nueva.', 'Conversación nueva');
}

function autoGrow(textarea) {
  textarea.style.height = 'auto';
  textarea.style.height = `${Math.min(textarea.scrollHeight, 116)}px`;
}

async function saveNote(button) {
  const bubble = button.closest('.nx-msg__main')?.querySelector('.nx-msg__bubble');
  const text = bubble?.innerText || '';
  if (!text) return;

  button.disabled = true;
  try {
    const note = await api.notes.create({
      title: (button.dataset.question || 'Apunte del tutor').slice(0, 120),
      text,
      document_id: Number(button.dataset.documentId || 0) || null,
    });
    button.outerHTML = `<a href="${escapeHtml(note?.url || appUrl(`pages/${note?.id}`))}">Ver apunte</a>`;
    toast.success('Lo guardé en tus apuntes.', 'Apunte guardado');
  } catch (error) {
    button.disabled = false;
    toast.error(error.message || 'No se pudo guardar el apunte.', 'No se guardó');
  }
}

export function initChat() {
  chat = qs('#nx-chat');

  // Ctrl+/ (⌘/ en Mac) abre el tutor. En teclados latinos «/» es Mayús+7, por eso no se filtra Shift.
  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key === '/') {
      event.preventDefault();
      if (chat) qs('[data-chat-input]', chat)?.focus();
      else window.location.href = appUrl('tutor');
    }
  });

  if (!chat) return;

  qsa('[data-chat-new]').forEach((button) => button.addEventListener('click', resetConversation));

  const form = qs('[data-chat-form]', chat);
  const input = qs('[data-chat-input]', chat);

  form?.addEventListener('submit', (event) => {
    event.preventDefault();
    send(input?.value || '');
  });

  input?.addEventListener('input', () => autoGrow(input));
  input?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      send(input.value);
    }
  });

  qsa('[data-chat-suggestion]', chat).forEach((button) => {
    button.addEventListener('click', () => {
      send(button.dataset.chatSuggestion || button.textContent || '');
    });
  });

  qs('[data-chat-messages]', chat)?.addEventListener('click', async (event) => {
    const save = event.target.closest('[data-save-note]');
    if (save) {
      saveNote(save);
      return;
    }

    const copy = event.target.closest('[data-copy-message]');
    if (!copy) return;
    const bubble = copy.closest('.nx-msg__main')?.querySelector('.nx-msg__bubble');
    const text = bubble?.innerText || '';
    if (!text) return;

    try {
      await navigator.clipboard.writeText(text);
      copy.textContent = 'Copiado';
      window.setTimeout(() => {
        copy.textContent = 'Copiar';
      }, 1600);
    } catch {
      toast.warning('El navegador no permitió copiar el mensaje.', 'No se pudo copiar');
    }
  });
}
