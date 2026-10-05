/**
 * Logique pure de la messagerie (#169) : regroupement par jour, textes « écrit… » / « Vu par », cadence du polling.
 * Aucun accès au DOM ni au réseau : testable seul (chat-model.test.js).
 */

export const TYPING_MIN_INTERVAL_MS = 3000;
const POLL_BASE_MS = 4000;
const POLL_SLOW_MS = 8000;
const POLL_IDLE_MS = 15000;

const two = (n) => String(n).padStart(2, '0');

function dayKey(date) {
  return `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())}`;
}

function parse(isoDate) {
  const date = new Date(isoDate);

  return Number.isNaN(date.getTime()) ? null : date;
}

function dayLabel(date, now) {
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  if (dayKey(date) === dayKey(now)) {
    return 'Aujourd\'hui';
  }
  if (dayKey(date) === dayKey(yesterday)) {
    return 'Hier';
  }

  return `${two(date.getDate())}/${two(date.getMonth() + 1)}/${date.getFullYear()}`;
}

/** Regroupe les messages (déjà triés) par jour calendaire. */
export function groupByDay(messages, now = new Date()) {
  const groups = [];
  for (const message of messages) {
    const date = parse(message.createdAt);
    const key = date === null ? '' : dayKey(date);
    const last = groups[groups.length - 1];
    if (last && last.key === key) {
      last.messages.push(message);
    } else {
      groups.push({ key, label: date === null ? '' : dayLabel(date, now), messages: [message] });
    }
  }

  return groups;
}

export function formatTime(isoDate) {
  const date = parse(isoDate);

  return date === null ? '' : `${two(date.getHours())}:${two(date.getMinutes())}`;
}

/** Date de la liste : l'heure aujourd'hui, « Hier », sinon jour/mois. */
export function formatListDate(isoDate, now = new Date()) {
  const date = parse(isoDate);
  if (date === null) {
    return '';
  }
  const label = dayLabel(date, now);

  return label === 'Aujourd\'hui' ? formatTime(isoDate) : label === 'Hier' ? 'Hier' : `${two(date.getDate())}/${two(date.getMonth() + 1)}`;
}

export function typingText(names) {
  if (names.length === 0) {
    return '';
  }
  if (names.length === 1) {
    return `${names[0]} écrit…`;
  }
  if (names.length === 2) {
    return `${names[0]} et ${names[1]} écrivent…`;
  }
  const others = names.length - 2;

  return `${names[0]}, ${names[1]} et ${others} autre${others > 1 ? 's' : ''} écrivent…`;
}

/** « Vu par » sous mon dernier message : noms si peu de lecteurs, sinon un décompte. */
export function seenText(seen) {
  if (!seen || seen.total === 0) {
    return '';
  }
  if (seen.names.length === 0) {
    return 'Envoyé';
  }
  if (seen.names.length <= 2) {
    return `Vu par ${seen.names.join(' et ')}`;
  }

  return `Vu par ${seen.names.length} sur ${seen.total}`;
}

/** Une couleur n'est appliquée que si c'est exactement #rrggbb : jamais de CSS injecté. */
export function safeColor(value) {
  return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value) ? value : null;
}

export function previewText(message) {
  const author = message.mine ? 'Vous' : message.authorName;

  return message.system ? `${author} ${message.body}` : `${author} : ${message.body}`;
}

/** Délai avant le prochain polling du fil : plus on n'a rien reçu, plus on espace. */
export function nextPollDelay(idlePolls) {
  if (idlePolls >= 15) {
    return POLL_IDLE_MS;
  }

  return idlePolls >= 5 ? POLL_SLOW_MS : POLL_BASE_MS;
}

export function shouldSendTyping(lastSentAt, now) {
  return lastSentAt === null || now - lastSentAt >= TYPING_MIN_INTERVAL_MS;
}

export function parseRoute(pathname) {
  const match = /^\/messages\/([1-9][0-9]{0,9})\/?$/.exec(pathname);

  return { id: match ? match[1] : null };
}

export function routeFor(id) {
  return id === null || id === undefined || id === '' ? '/messages' : `/messages/${id}`;
}

/** Ajoute les nouveaux messages sans doublon (un message déjà affiché ne revient pas) et garde l'ordre des identifiants. */
export function mergeMessages(existing, incoming) {
  const byId = new Map(existing.map((message) => [message.id, message]));
  for (const message of incoming) {
    byId.set(message.id, message);
  }

  return [...byId.values()].sort((a, b) => a.id - b.id);
}

export function lastMessageId(messages) {
  return messages.reduce((max, message) => (typeof message.id === 'number' && message.id > max ? message.id : max), 0);
}
