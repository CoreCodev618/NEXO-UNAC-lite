import { api, ApiError } from '../core/api.js';
import {
  appUrl,
  escapeHtml,
  lockScroll,
  qs,
  qsa,
  trapFocus,
  unlockScroll,
} from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toast } from '../core/toast.js';

let chat = null;
let lastFocus = null;
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
    'El tutor no está conectado en este entorno. No voy a inventar una respuesta ni citar fuentes que no existen.',
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

function appendTyping() {
  const messages = qs('[data-chat-messages]', chat);
  if (!messages) return null;
  const wrapper = document.createElement('div');
  wrapper.className = 'nx-msg';
  wrapper.dataset.chatTyping = '';
  wrapper.innerHTML = `
    <span class="nx-msg__avatar nx-chat__head-icon" aria-hidden="true">${icon('sparkles')}</span>
    <div class="nx-msg__main">
      <div class="nx-msg__bubble"><span class="nx-typing" aria-label="Esperando respuesta"><span></span><span></span><span></span></span></div>
    </div>
  `;
  messages.appendChild(wrapper);
  scrollToEnd();
  return wrapper;
}

function renderSources(data) {
  const sources = Array.isArray(data?.sources) ? data.sources : Array.isArray(data?.citations) ? data.citations : [];
  if (sources.length === 0) return null;

  const container = document.createElement('div');
  container.className = 'nx-msg__citations';
  container.innerHTML = '<span class="nx-msg__citations-label">Fuentes del material</span>';

  sources.slice(0, 5).forEach((source) => {
    const label = source.title || source.page_title || 'Página del espacio';
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
    const pageId = Number(document.body.dataset.pageId || 0);
    const response = await api.chat.send({
      message: text,
      page_id: pageId > 0 ? pageId : null,
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

export function openChat() {
  if (!chat) return;
  lastFocus = document.activeElement;
  chat.classList.add('is-open');
  chat.removeAttribute('inert');
  chat.setAttribute('aria-hidden', 'false');
  qs('.nx-chat__backdrop')?.classList.add('is-visible');
  qsa('[data-chat-toggle]').forEach((button) => button.setAttribute('aria-expanded', 'true'));
  lockScroll();
  window.requestAnimationFrame(() => qs('[data-chat-input]', chat)?.focus());
}

export function closeChat() {
  if (!chat) return;
  chat.classList.remove('is-open');
  chat.setAttribute('aria-hidden', 'true');
  chat.setAttribute('inert', '');
  qs('.nx-chat__backdrop')?.classList.remove('is-visible');
  qsa('[data-chat-toggle]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
  unlockScroll();

  if (lastFocus instanceof HTMLElement && lastFocus.isConnected) {
    lastFocus.focus();
  }
}

function resetConversation() {
  sessionId = null;
  const messages = qs('[data-chat-messages]', chat);
  const intro = qs('[data-chat-intro]', chat);
  if (messages) messages.innerHTML = '';
  if (intro) intro.hidden = false;
  qs('[data-chat-input]', chat)?.focus();
  toast.info('Empezamos una conversación nueva. Todavía no hay historial guardado.', 'Conversación nueva');
}

function autoGrow(textarea) {
  textarea.style.height = 'auto';
  textarea.style.height = `${Math.min(textarea.scrollHeight, 116)}px`;
}

export function initChat() {
  chat = qs('#nx-chat');
  if (!chat) return;

  qsa('[data-chat-toggle]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      if (chat.classList.contains('is-open')) closeChat();
      else openChat();
    });
  });

  qsa('[data-chat-close]').forEach((button) => button.addEventListener('click', closeChat));
  qsa('[data-chat-new]').forEach((button) => button.addEventListener('click', resetConversation));
  qs('.nx-chat__backdrop')?.addEventListener('click', closeChat);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && chat.classList.contains('is-open')) {
      event.preventDefault();
      closeChat();
      return;
    }

    if (event.key === 'Tab' && chat.classList.contains('is-open')) {
      trapFocus(chat, event);
    }

    // Ctrl+/ (⌘/ en Mac). En teclados latinos «/» es Mayús+7, por eso no se filtra Shift.
    if ((event.metaKey || event.ctrlKey) && event.key === '/') {
      event.preventDefault();
      if (!chat.classList.contains('is-open')) openChat();
    }
  });

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
