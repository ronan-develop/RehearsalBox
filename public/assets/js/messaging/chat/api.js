/** Appels de la messagerie (#169, #183). Toute écriture passe par apiFetch (CSRF) : jamais de fetch() brut. */
import { apiFetch } from '../../core/api.js';

const base = (id) => `/api/conversations/${encodeURIComponent(id)}`;

/** Totaux « non lu » (pastille du dashboard). */
export function fetchList(box, { signal } = {}) {
  return apiFetch(`/api/conversations?box=${encodeURIComponent(box)}`, { signal });
}

/** Messages plus récents que `after`, déjà dessinés par le serveur : { html, lastId, hasNew, status, typing, title, displayTitle, label, edited, editedAt }. */
export function fetchUpdates(id, after, { signal, editedAfter } = {}) {
  // `editedAfter` : curseur des corrections déjà reçues (secondes Unix) ; sans lui, le serveur n'en renvoie aucune.
  const edited = editedAfter === undefined || editedAfter === null ? '' : `&editedAfter=${encodeURIComponent(editedAfter)}`;

  return apiFetch(`${base(id)}/updates?after=${encodeURIComponent(after)}${edited}`, { signal });
}

/** Liste des conversations dessinée par le serveur : { html, empty, archivedUnread }. */
export function fetchListFragment(box, activeId = null, { signal } = {}) {
  const active = activeId === null || activeId === '' ? '' : `&active=${encodeURIComponent(activeId)}`;

  return apiFetch(`/api/conversation-list?box=${encodeURIComponent(box)}${active}`, { signal });
}

/** Envoie un message (en citant éventuellement `replyTo`, #214) ; la réponse contient les messages plus récents que `after` (dont le mien), déjà dessinés. */
export function sendMessage(id, message, after, mentions = [], replyTo = null) {
  return apiFetch(`${base(id)}/messages`, {
    method: 'POST',
    body: JSON.stringify({ message, after, ...(mentions.length > 0 ? { mentions } : {}), ...(replyTo !== null ? { replyTo } : {}) }),
  });
}

export function renameConversation(id, title) {
  return apiFetch(base(id), { method: 'PATCH', body: JSON.stringify({ title }) });
}

/** Sourdine de la conversation, propre à la personne connectée (#210) ; idempotent. */
export function setMute(id, muted) {
  return apiFetch(`${base(id)}/mute`, { method: muted ? 'PUT' : 'DELETE' });
}

export function sendTyping(id) {
  return apiFetch(`${base(id)}/typing`, { method: 'POST', body: JSON.stringify({}) });
}

/** Crée la conversation à l'envoi du premier message (page de démarrage). Le serveur revérifie tout. */
export function startConversation({ groupId, targetGroupId, message, mentions = [] }) {
  return apiFetch('/api/conversations', {
    method: 'POST',
    body: JSON.stringify({ groupId, targetGroupId, message, ...(mentions.length > 0 ? { mentions } : {}) }),
  });
}

/**
 * Liste proposée après « @ » : { members: [{ id, name, groups, participant }] }. Le contexte est une conversation
 * (`conversation`) ou, sur la page de démarrage, le groupe émetteur et le groupe visé.
 */
export function searchMembers({ query, conversation = null, groupId = null, targetGroupId = null }, { signal } = {}) {
  const params = new URLSearchParams({ q: query });
  if (conversation !== null) {
    params.set('conversation', conversation);
  } else {
    params.set('groupId', groupId);
    params.set('targetGroupId', targetGroupId);
  }

  return apiFetch(`/api/members?${params}`, { signal });
}

/** Retire un invité (celui qui l'a ajouté, l'initiateur, ou l'invité qui quitte). */
export function removeGuest(conversationId, userId) {
  return apiFetch(`${base(conversationId)}/guests/${encodeURIComponent(userId)}`, { method: 'DELETE' });
}

/** Corbeille (#190) : mise à la corbeille (initiateur), restauration, suppression définitive, fermeture d'un avis. */
export function trashConversation(id) {
  return apiFetch(base(id), { method: 'DELETE' });
}

export function restoreConversation(id) {
  return apiFetch(`${base(id)}/restore`, { method: 'POST', body: JSON.stringify({}) });
}

export function purgeConversation(id) {
  return apiFetch(`${base(id)}/permanent`, { method: 'DELETE' });
}

export function dismissAlert(id) {
  return apiFetch(`/api/conversation-alerts/${encodeURIComponent(id)}/dismiss`, { method: 'POST', body: JSON.stringify({}) });
}

/** Corrige son propre message (15 minutes) : { status, edited: [{ id, html }], editedAt }. */
export function editMessage(conversationId, messageId, message, mentions = []) {
  return apiFetch(`${base(conversationId)}/messages/${encodeURIComponent(messageId)}`, {
    method: 'PATCH',
    body: JSON.stringify({ message, ...(mentions.length > 0 ? { mentions } : {}) }),
  });
}
