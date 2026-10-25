import { csrfHeaders, refreshCsrf } from './csrf.js';

const API_BASE = (() => {
  const value = document.body?.dataset.api || '/api/v1';
  return value.replace(/\/$/, '');
})();

export class ApiError extends Error {
  constructor(message, status = 0, payload = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }

  get unavailable() {
    return this.status === 0 || this.status === 404 || this.status === 405 || this.status === 501;
  }
}

async function request(path, options = {}) {
  const { method = 'GET', body, headers = {}, signal } = options;
  const requestHeaders = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...csrfHeaders(),
    ...headers,
  };

  let payload = body;
  if (body !== undefined && !(body instanceof FormData)) {
    requestHeaders['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      body: payload,
      headers: requestHeaders,
      credentials: 'same-origin',
      signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('No se pudo conectar con el servidor.', 0, null);
  }

  refreshCsrf(response);

  const contentType = response.headers.get('content-type') || '';
  let data = null;

  if (response.status !== 204) {
    try {
      data = contentType.includes('application/json') ? await response.json() : await response.text();
    } catch {
      data = null;
    }
  }

  if (!response.ok || data?.success === false) {
    const message =
      data?.message ||
      (typeof data === 'string' && data.trim() !== '' ? data : null) ||
      `La petición falló (${response.status}).`;

    throw new ApiError(message, response.status, data);
  }

  return data?.data ?? data;
}

export const api = {
  request,

  pages: {
    create(payload) {
      return request('/pages', { method: 'POST', body: payload });
    },
    update(id, payload) {
      return request(`/pages/${id}`, { method: 'PUT', body: payload });
    },
  },

  blocks: {
    async savePage(pageId, { title, blocks, deletedIds = [] }) {
      const payload = {
        title,
        blocks: blocks.map((block, index) => ({
          id: /^\d+$/.test(String(block.id)) ? Number(block.id) : null,
          client_id: String(block.id ?? `new-${index}`),
          type: block.type,
          content: block.content,
          order_index: Number(block.order_index ?? index),
        })),
        deleted_ids: deletedIds
          .map((id) => Number(id))
          .filter((id) => Number.isInteger(id) && id > 0),
      };

      const response = await request(`/pages/${pageId}`, { method: 'PUT', body: payload });

      if (!Array.isArray(response?.blocks)) {
        throw new ApiError('El servidor no devolvió los bloques guardados.', 500, response);
      }

      return response.blocks.map((block) => ({
        tempId: block.client_id,
        data: { id: block.id },
      }));
    },
  },

  comments: {
    create(payload) {
      return request('/comments', { method: 'POST', body: payload });
    },
  },

  tasks: {
    create(payload) {
      return request('/tasks', { method: 'POST', body: payload });
    },
    update(id, payload) {
      return request(`/tasks/${id}`, { method: 'PUT', body: payload });
    },
    move(id, payload) {
      return request(`/tasks/${id}/move`, { method: 'PUT', body: payload });
    },
    remove(id) {
      return request(`/tasks/${id}`, { method: 'DELETE' });
    },
  },

  attachments: {
    upload(pageId, file, onProgress) {
      return new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('page_id', String(pageId));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${API_BASE}/attachments`);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        Object.entries(csrfHeaders()).forEach(([name, value]) => xhr.setRequestHeader(name, value));

        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable && typeof onProgress === 'function') {
            onProgress(Math.round((event.loaded / event.total) * 100));
          }
        });

        xhr.addEventListener('load', () => {
          let payload = null;
          try {
            payload = JSON.parse(xhr.responseText || 'null');
          } catch {
            payload = null;
          }

          if (xhr.status >= 200 && xhr.status < 300 && payload?.success !== false) {
            resolve(payload?.data ?? payload);
          } else {
            reject(
              new ApiError(
                payload?.message || `No se pudo subir el archivo (${xhr.status}).`,
                xhr.status,
                payload,
              ),
            );
          }
        });

        xhr.addEventListener('error', () => reject(new ApiError('No se pudo conectar con el servidor.', 0, null)));
        xhr.send(formData);
      });
    },
  },

  documents: {
    list() {
      return request('/documents');
    },

    remove(id) {
      return request(`/documents/${id}`, { method: 'DELETE' });
    },

    upload(formData, onProgress) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', `${API_BASE}/documents`);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        Object.entries(csrfHeaders()).forEach(([name, value]) => xhr.setRequestHeader(name, value));

        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable && typeof onProgress === 'function') {
            onProgress(Math.round((event.loaded / event.total) * 100));
          }
        });

        xhr.addEventListener('load', () => {
          refreshCsrf(xhr);

          let payload = null;
          try {
            payload = JSON.parse(xhr.responseText || 'null');
          } catch {
            payload = null;
          }

          if (xhr.status >= 200 && xhr.status < 300 && payload?.success !== false) {
            resolve(payload?.data ?? payload);
          } else {
            reject(
              new ApiError(
                payload?.message || `No se pudo subir el documento (${xhr.status}).`,
                xhr.status,
                payload,
              ),
            );
          }
        });

        xhr.addEventListener('error', () => reject(new ApiError('No se pudo conectar con el servidor.', 0, null)));
        xhr.send(formData);
      });
    },
  },

  chat: {
    send(payload, signal) {
      return request('/chat', { method: 'POST', body: payload, signal });
    },
  },
};
