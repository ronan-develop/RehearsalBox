/** Appels de la messagerie (#169, #183). Toute écriture passe par apiFetch (CSRF) : jamais de fetch() brut. */
import { apiFetch } from '../api.js';

const base = (id) => `/api/conversations/${encodeURIComponent(id)}`;

/** Totaux « non lu » (pastille du dashboard). */
export function fetchList(box, { signal } = {}) {
  return apiFetch(`/api/conversations?box=${encodeURIComponent(box)}`, { signal });
}

/** Messages plus récents que `after`, déjà dessinés par le serveur : { html, lastId, hasNew, status, typing, title, displayTitle, label }. */
export function fetchUpdates(id, after, { signal } = {}) {
  return apiFetch(`${base(id)}/updates?after=${encodeURIComponent(after)}`, { signal });
}

/** Liste des conversations dessinée par le serveur : { html, empty, archivedUnread }. */
export function fetchListFragment(box, activeId = null, { signal } = {}) {
  const active = activeId === null || activeId === '' ? '' : `&active=${encodeURIComponent(activeId)}`;

  return apiFetch(`/api/conversation-list?box=${encodeURIComponent(box)}${active}`, { signal });
}

/** Envoie un message ; la réponse contient les messages plus récents que `after` (dont le mien), déjà dessinés. */
export function sendMessage(id, message, after) {
  return apiFetch(`${base(id)}/messages`, { method: 'POST', body: JSON.stringify({ message, after }) });
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
