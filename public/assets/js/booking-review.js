/**
 * Logique pure de la revue des réservations par un administrateur (#263), sans DOM : la requête d'une décision, les messages, le
 * décompte de la pastille. Le composant <rb-booking-card> n'appelle jamais l'API ; la page (admin-bookings.js) s'en sert.
 */
const DECISIONS = ['approve', 'refuse'];

/** @returns {{ path: string, options: { method: string, body?: string } }} */
export function decisionRequest(id, decision, note = '') {
  if (typeof id !== 'string' || !/^\d+$/.test(id)) {
    throw new Error('Identifiant de réservation invalide.');
  }
  if (!DECISIONS.includes(decision)) {
    throw new Error('Décision inconnue.');
  }
  const options = { method: 'POST' };
  const trimmed = typeof note === 'string' ? note.trim() : '';
  if (decision === 'refuse' && trimmed !== '') {
    options.body = JSON.stringify({ note: trimmed });
  }

  return { path: `/api/admin/bookings/${id}/${decision}`, options };
}

export function outcomeMessage(decision) {
  return decision === 'approve' ? 'Réservation validée.' : 'Réservation refusée.';
}

/** Nombre de réservations à valider dans la réponse de GET /api/admin/bookings ; 0 pour tout ce qui n'est pas une liste. */
export function pendingCount(response) {
  return Array.isArray(response?.bookings) ? response.bookings.length : 0;
}

/** Un autre administrateur a déjà tranché : la carte n'a plus lieu d'être. */
export function isAlreadyDecided(error) {
  return error?.status === 409 && typeof error.message === 'string' && error.message.includes('déjà été traitée');
}

/** Message d'échec : « déjà traitée » reformulé, tout autre message du serveur conservé tel quel. */
export function failureMessage(error) {
  const message = typeof error?.message === 'string' ? error.message : 'Une erreur est survenue.';

  return isAlreadyDecided(error) ? 'Cette réservation a déjà été traitée par un autre administrateur.' : message;
}
