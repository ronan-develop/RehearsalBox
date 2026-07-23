/**
 * Wrapper fetch() unique — toute écriture passe par ici (cf. plan §5bis/§10.3).
 * Jamais de fetch() brut ailleurs dans le projet.
 */

export function normalizeError(status, body) {
  const safeBody = body && typeof body === 'object' ? body : {};

  return {
    status,
    message: typeof safeBody.error === 'string' ? safeBody.error : 'Une erreur est survenue.',
    fields: safeBody.fields && typeof safeBody.fields === 'object' ? safeBody.fields : {},
  };
}

function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.content : '';
}

export async function apiFetch(url, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  if (['POST', 'PATCH', 'DELETE', 'PUT'].includes(method)) {
    headers['X-CSRF-Token'] = getCsrfToken();
  }

  const response = await fetch(url, { ...options, method, headers });
  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw normalizeError(response.status, body);
  }

  return body;
}
