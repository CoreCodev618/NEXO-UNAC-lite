import { initDropdowns } from './core/dropdown.js';
import { initRagUpload } from './modules/rag-upload.js';
import { initChat } from './modules/chat.js';
import { initEditor } from './modules/editor.js';
import { initNotesFilter } from './modules/notes-filter.js';
import { initSidebar } from './modules/sidebar.js';

function boot() {
  initDropdowns();
  initSidebar();
  initChat();
  initEditor();
  initRagUpload();
  initNotesFilter();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
