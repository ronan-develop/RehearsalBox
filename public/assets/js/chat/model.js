/**
 * Logique pure du direct (#183) : cadence du polling et limite du signal « écrit… ». Tout ce qui se lit à l'écran
 * (jours, « Vu par », « écrit… », heures) est calculé et dessiné par le serveur : rien de tel ici.
 */

export const TYPING_MIN_INTERVAL_MS = 3000;
const POLL_BASE_MS = 4000;
const POLL_SLOW_MS = 8000;
const POLL_IDLE_MS = 15000;

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
