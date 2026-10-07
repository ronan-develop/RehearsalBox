/**
 * Logique pure du formulaire « Réserver le local » (#263), sans DOM : la requête du plan, les lignes lisibles, l'action principale et
 * ses étapes (« réserver la partie libre ET demander le reste » en un geste), la requête de chaque étape et le bilan. Le composant
 * <rb-booking-form> n'appelle jamais l'API ; la page (bookings.js) s'en sert. Les textes sont posés avec textContent, jamais en HTML.
 */
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const range = (item) => `${item.startTime} – ${item.endTime}`;

/** Chaîne de requête de GET /api/bookings/plan, ou null tant que la saisie est incomplète ou incohérente. */
export function planQuery({ groupId, date, start, end } = {}) {
  if (!/^\d+$/.test(groupId ?? '') || !DATE.test(date ?? '') || !TIME.test(start ?? '') || !TIME.test(end ?? '') || end <= start) {
    return null;
  }

  return new URLSearchParams({ groupId, bookingDate: date, startTime: start, endTime: end }).toString();
}

/** Les parties libres et les recouvrements, dans l'ordre de la journée : [{ tone: 'free' | 'request' | 'info', text }]. */
export function planLines(plan) {
  const lines = [];
  for (const part of plan?.freeParts ?? []) {
    lines.push({ at: part.startTime, tone: 'free', text: `Libre : ${range(part)}` });
  }
  for (const conflict of plan?.conflicts ?? []) {
    let tone = 'info';
    let text;
    if (conflict.kind === 'fixed' && conflict.own) {
      text = `Votre groupe a déjà ce créneau fixe : ${range(conflict)}`;
    } else if (conflict.kind === 'fixed') {
      tone = 'request';
      text = `Chevauche le créneau de ${conflict.groupName} : ${range(conflict)} (une demande est possible)`;
    } else {
      text = `Déjà réservé par ${conflict.own ? 'votre groupe' : conflict.groupName} : ${range(conflict)}`;
    }
    lines.push({ at: conflict.startTime, tone, text });
  }

  return lines.sort((a, b) => a.at.localeCompare(b.at)).map(({ tone, text }) => ({ tone, text }));
}

/** @returns {{ label: string, steps: object[] } | null} null quand il n'y a rien à réserver ni à demander */
export function primaryAction(plan) {
  const bookings = (plan?.freeParts ?? []).map(({ startTime, endTime }) => ({ type: 'booking', startTime, endTime }));
  const requests = (plan?.conflicts ?? [])
    .filter((conflict) => conflict.kind === 'fixed' && !conflict.own && Number.isInteger(conflict.slotId))
    .map(({ slotId, groupName, startTime, endTime }) => ({ type: 'request', slotId, groupName, startTime, endTime }));
  const steps = [...bookings, ...requests]; // les réservations d'abord : une demande refusée n'annule jamais une réservation
  if (steps.length === 0) {
    return null;
  }

  let label;
  if (bookings.length > 0 && requests.length > 0) {
    label = bookings.length > 1 ? 'Réserver les parties libres et demander le reste' : 'Réserver la partie libre et demander le reste';
  } else if (bookings.length > 0) {
    const everythingFree = (plan.conflicts ?? []).length === 0 && bookings.length === 1;
    label = everythingFree ? `Réserver ${range(bookings[0])}` : bookings.length > 1 ? 'Réserver les parties libres' : 'Réserver la partie libre';
  } else {
    label = requests.length === 1 ? `Demander à ${requests[0].groupName}` : 'Envoyer les demandes';
  }

  return { label, steps };
}

/** @returns {{ path: string, options: { method: string, body: string } }} l'appel d'une étape ; échoue sur un identifiant ou une étape invalide */
export function stepRequest(step, { groupId, date, reason = '' }) {
  if (!/^\d+$/.test(groupId ?? '')) {
    throw new Error('Identifiant de groupe invalide.');
  }
  const note = typeof reason === 'string' ? reason.trim() : '';
  const withNote = (body) => JSON.stringify(note === '' ? body : { ...body, reason: note });

  if (step?.type === 'booking') {
    return { path: '/api/bookings', options: { method: 'POST', body: withNote({ groupId: Number(groupId), bookingDate: date, startTime: step.startTime, endTime: step.endTime }) } };
  }
  if (step?.type === 'request') {
    return {
      path: '/api/availability',
      options: { method: 'POST', body: withNote({ recurringSlotId: Number(step.slotId), groupId: Number(groupId), occurrenceDate: date, startTime: step.startTime, endTime: step.endTime }) },
    };
  }

  throw new Error('Étape inconnue.');
}

/** Bilan de l'enchaînement : ce qui est parti, ce qui a échoué et pourquoi. @param {{ step: object, ok: boolean, error?: { message?: string } | null }[]} results */
export function summarize(results) {
  const lines = results.map(({ step, ok, error }) => {
    const label = step.type === 'booking' ? `Réservation ${range(step)}` : `Demande à ${step.groupName} ${range(step)}`;
    if (!ok) {
      return `${label} : non envoyée — ${typeof error?.message === 'string' ? error.message : 'Une erreur est survenue.'}`;
    }

    return step.type === 'booking'
      ? `${label} : envoyée, elle attend la validation d’un administrateur.`
      : `${label} : envoyée, le groupe titulaire doit répondre.`;
  });

  return { ok: results.every((result) => result.ok), anySent: results.some((result) => result.ok), lines };
}

/** Message lisible d'une erreur de l'API : le premier message de champ, sinon le message du serveur, sinon un message générique. */
export function errorText(error) {
  const field = Object.values(error?.fields ?? {}).find((message) => typeof message === 'string' && message !== '');

  return field ?? (typeof error?.message === 'string' && error.message !== '' ? error.message : 'Une erreur est survenue.');
}

/** L'annulation d'une réservation du groupe ; l'identifiant doit être strictement numérique avant de toucher l'URL. */
export function cancelRequest(id) {
  if (typeof id !== 'string' || !/^\d+$/.test(id)) {
    throw new Error('Identifiant de réservation invalide.');
  }

  return { path: `/api/bookings/${id}`, options: { method: 'DELETE' } };
}
