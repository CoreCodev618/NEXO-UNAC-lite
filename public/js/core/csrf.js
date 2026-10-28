/**
 * Token CSRF de CodeIgniter. Es estable durante la sesión; si alguna
 * respuesta trae un X-CSRF-TOKEN nuevo, se adopta.
 */

const tokenMeta = document.querySelector('meta[name="csrf-token"]');
const headerMeta = document.querySelector('meta[name="csrf-header"]');

export function csrfToken() {
  return tokenMeta?.getAttribute('content') || '';
}

export function csrfHeaderName() {
  return headerMeta?.getAttribute('content') || 'X-CSRF-TOKEN';
}

export function csrfHeaders() {
  const token = csrfToken();
  return token ? { [csrfHeaderName()]: token } : {};
}

export function refreshCsrf(response) {
  if (!response) return;

  const bodyToken =
    response.headers?.get?.('X-CSRF-TOKEN') ||
    response.getResponseHeader?.('X-CSRF-TOKEN');

  if (bodyToken && tokenMeta) {
    tokenMeta.setAttribute('content', bodyToken);
  }
}
