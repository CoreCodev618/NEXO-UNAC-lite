import { initDropdowns } from './core/dropdown.js';
import { initModals } from './core/modal.js';
import { toast } from './core/toast.js';
import { initRagUpload } from './modules/rag-upload.js';
import { initChat, openChat } from './modules/chat.js';
import { initEditor } from './modules/editor.js';
import { initSidebar } from './modules/sidebar.js';

function boot() {
  initDropdowns();
  initModals();
  initSidebar();
  initChat();
  initEditor();
  initRagUpload();

  window.NEXO = { toast, openChat };
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
