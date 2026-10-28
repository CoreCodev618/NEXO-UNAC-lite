import { initAuth } from './modules/auth.js';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAuth, { once: true });
} else {
  initAuth();
}
