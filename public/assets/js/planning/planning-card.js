/**
 * Logique pure de <rb-planning-card> (#330), testable sans DOM : où mène une carte du planning, et quelles touches l'activent.
 */

/** Date locale « AAAA-MM-JJ » (jamais toISOString : il convertit en UTC et décale d'un jour le soir). */
function isoDay(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Prochaine date d'un jour de semaine, aujourd'hui compris. `weekday` suit l'enum du serveur : lundi = 0 … dimanche = 6.
 *
 * @param {number} weekday
 * @param {Date} [from]
 */
export function nextOccurrence(weekday, from = new Date()) {
  const today = (from.getDay() + 6) % 7; // JavaScript : dimanche = 0
  const next = new Date(from.getFullYear(), from.getMonth(), from.getDate() + ((weekday - today + 7) % 7));

  return isoDay(next);
}

/**
 * Où mène une carte du planning :
 * - un groupe dont on est membre : son espace ;
 * - un autre groupe, quand on a un groupe pour réserver : « Réserver le local » déjà rempli avec ce créneau (sa prochaine date),
 *   pour que la demande d'échange soit officielle et que le planning reste à jour ;
 * - sinon (aucun groupe, ou créneau illisible) : la page « nouvelle conversation » (/messages/new/{id}).
 * Le serveur revérifie tout ; l'adresse e-mail de contact n'est jamais exposée au client.
 *
 * @param {{groupId: string, slug: string, isMember: boolean, canBook?: boolean, weekday?: string, startTime?: string, endTime?: string}} card
 * @param {Date} [from]
 */
export function destinationFor({ groupId, slug, isMember, canBook = false, weekday = '', startTime = '', endTime = '' }, from = new Date()) {
  if (isMember) {
    return `/groups/${slug}/space`;
  }
  const day = Number(weekday);
  const readable = weekday !== '' && Number.isInteger(day) && day >= 0 && day <= 6 && /^\d{2}:\d{2}$/.test(startTime) && /^\d{2}:\d{2}$/.test(endTime);
  if (canBook && readable) {
    return '/bookings?' + new URLSearchParams({ date: nextOccurrence(day, from), start: startTime, end: endTime });
  }

  return `/messages/new/${groupId}`;
}

/** Entrée et Espace activent la carte, comme un bouton. */
export function isActivationKey(key) {
  return key === 'Enter' || key === ' ';
}
