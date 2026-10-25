import { toast } from './core/toast.js';
import { initAuth } from './modules/auth.js';

function boot() {
  initAuth();
  window.NEXO = { toast };
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
