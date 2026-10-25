/**
 * Token CSRF de CodeIgniter. Se refresca después de cada petición que
 * devuelve un token nuevo (regeneración o primera petición).
 */

const tokenMeta = document.querySelector('meta[name="csrf-token"]');
const headerMeta = document.querySelector('meta[name="csrf-header"]');
const cookieMeta = document.querySelector('meta[name="csrf-cookie"]');

export function csrfToken() {
  return tokenMeta?.getAttribute('content') || '';
}

export function csrfHeaderName() {
  return headerMeta?.getAttribute('content') || 'X-CSRF-TOKEN';
}

export function csrfCookieName() {
  return cookieMeta?.getAttribute('content') || 'csrf_cookie_name';
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

  if (csrfCookieName()) {
    const cookie = document.cookie
      .split('; ')
      .find((entry) => entry.startsWith(`${csrfCookieName()}=`));

    if (cookie && tokenMeta) {
      const value = decodeURIComponent(cookie.split('=').slice(1).join('='));
      if (value) tokenMeta.setAttribute('content', value);
    }
  }
}
