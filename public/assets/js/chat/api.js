/** Appels de la messagerie (#169). Toute écriture passe par apiFetch (CSRF) : jamais de fetch() brut. */
import { apiFetch } from '../api.js';

const base = (id) => `/api/conversations/${encodeURIComponent(id)}`;

export function fetchList(box, { signal } = {}) {
  return apiFetch(`/api/conversations?box=${encodeURIComponent(box)}`, { signal });
}

/** Sans `after` : fil complet (marqué lu). Avec `after` : seulement les messages plus récents (polling). */
export function fetchThread(id, after, { signal } = {}) {
  return apiFetch(after === undefined ? base(id) : `${base(id)}?after=${encodeURIComponent(after)}`, { signal });
}

export function sendMessage(id, message) {
  return apiFetch(`${base(id)}/messages`, { method: 'POST', body: JSON.stringify({ message }) });
}

export function renameConversation(id, title) {
  return apiFetch(base(id), { method: 'PATCH', body: JSON.stringify({ title }) });
}

export function sendTyping(id) {
  return apiFetch(`${base(id)}/typing`, { method: 'POST', body: JSON.stringify({}) });
}

/** Crée la conversation à l'envoi du premier message (page de démarrage). Le serveur revérifie tout. */
export function startConversation({ groupId, targetGroupId, message }) {
  return apiFetch('/api/conversations', { method: 'POST', body: JSON.stringify({ groupId, targetGroupId, message }) });
}
