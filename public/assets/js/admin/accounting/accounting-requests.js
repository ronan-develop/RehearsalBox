/**
 * Requêtes de la page « Comptabilité » (#362), sans DOM : chemins de l'API et corps. Seuls les champs attendus partent, le montant reste
 * du TEXTE (le serveur le lit en centimes entiers, jamais de nombre côté navigateur), et un identifiant doit être une chaîne de chiffres
 * sans zéro initial avant de toucher une adresse. La page (admin-accounting.js) s'en sert ; les composants n'appellent jamais l'API.
 */
import { failureMessage } from '../assembly/assembly-requests.js';

export { failureMessage };

const ID = /^[1-9]\d{0,9}$/;

function pick(values, keys) {
  const body = {};
  for (const key of keys) {
    if (values[key] !== undefined) {
      body[key] = values[key];
    }
  }

  return body;
}

function idOrThrow(id, what) {
  if (typeof id !== 'string' || !ID.test(id)) {
    throw new Error(`Identifiant ${what} invalide.`);
  }

  return id;
}

/** @returns {{ path: string, options: { method: string, body: string } }} */
export function createYearRequest(values) {
  const body = pick(values, ['label', 'startsOn', 'endsOn', 'openingBalance']);
  if (body.openingBalance === '') {
    delete body.openingBalance;
  }

  return { path: '/api/admin/accounting/years', options: { method: 'POST', body: JSON.stringify(body) } };
}

export function recordEntryRequest(yearId, values) {
  const body = pick(values, ['occurredOn', 'label', 'amount', 'direction', 'category', 'paymentMethod', 'voucherRef', 'note']);

  return { path: `/api/admin/accounting/years/${idOrThrow(yearId, 'd\'exercice')}/entries`, options: { method: 'POST', body: JSON.stringify(body) } };
}

export function reverseRequest(entryId, values) {
  return {
    path: `/api/admin/accounting/entries/${idOrThrow(entryId, 'd\'écriture')}/reverse`,
    options: { method: 'POST', body: JSON.stringify(pick(values, ['reason', 'occurredOn'])) },
  };
}

/** La révision vient de la page (entier) : elle protège d'une clôture décidée sur un écran périmé. */
export function closeYearRequest(yearId, values) {
  const revision = Number(values.revision);
  if (!Number.isInteger(revision) || revision < 0) {
    throw new Error('Révision invalide.');
  }

  return { path: `/api/admin/accounting/years/${idOrThrow(yearId, 'd\'exercice')}/close`, options: { method: 'POST', body: JSON.stringify({ revision }) } };
}

export function approveYearRequest(yearId, values) {
  return { path: `/api/admin/accounting/years/${idOrThrow(yearId, 'd\'exercice')}/approve`, options: { method: 'POST', body: JSON.stringify(pick(values, ['approvedOn'])) } };
}

/** Dépôt d'un relevé : envoi multipart (le navigateur fixe l'en-tête). Seuls les champs attendus partent ; le serveur fixe tout le reste. */
export function depositStatementRequest(yearId, values, file) {
  const body = new FormData();
  body.set('fiscalYearId', idOrThrow(yearId, 'd\'exercice'));
  for (const key of ['periodStart', 'periodEnd', 'closingBalance']) {
    if (values[key] !== undefined) {
      body.set(key, values[key]);
    }
  }
  if (values.replaces !== undefined && values.replaces !== '') {
    body.set('replaces', idOrThrow(values.replaces, 'de relevé'));
  }
  body.set('file', file);

  return { path: '/api/admin/accounting/statements', options: { method: 'POST', body } };
}
