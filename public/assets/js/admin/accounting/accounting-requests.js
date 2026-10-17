/**
 * Requêtes de la page « Comptabilité » (#362), sans DOM : chemins de l'API et corps. Seuls les champs attendus partent, le montant reste
 * du TEXTE (le serveur le lit en centimes entiers, jamais de nombre côté navigateur), et un identifiant doit être une chaîne de chiffres
 * sans zéro initial avant de toucher une adresse. La page (admin-accounting.js) s'en sert ; les composants n'appellent jamais l'API.
 */
import { failureMessage } from '../form-feedback.js';

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
  const body = pick(values, ['occurredOn', 'label', 'amount', 'direction', 'category', 'paymentMethod', 'voucherRef', 'note', 'memberUserId', 'periodMonth']);
  if (body.memberUserId === '') {
    delete body.memberUserId;
    delete body.periodMonth;
  }

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

const TEMPLATE_FIELDS = ['label', 'direction', 'category', 'paymentMethod', 'amount', 'voucherRef', 'periodicity', 'dueDay', 'firstMonth', 'isActive'];

/** Modèle récurrent : tous les champs du modèle ; une case décochée envoie « false », jamais l'absence. */
export function templateRequest(templateId, values) {
  const body = pick(values, TEMPLATE_FIELDS);
  body.isActive = values.isActive === true || values.isActive === '1';
  const path = templateId === '' ? '/api/admin/accounting/recurring' : `/api/admin/accounting/recurring/${idOrThrow(templateId, 'de modèle')}`;

  return { path, options: { method: templateId === '' ? 'POST' : 'PUT', body: JSON.stringify(body) } };
}

/** Confirmation d'une échéance : seuls l'échéance, le montant (variable) et la pièce partent ; le reste vient du modèle, côté serveur. */
export function confirmOccurrenceRequest(templateId, values) {
  return {
    path: `/api/admin/accounting/recurring/${idOrThrow(templateId, 'de modèle')}/confirm`,
    options: { method: 'POST', body: JSON.stringify(pick(values, ['period', 'amount', 'voucherRef'])) },
  };
}

export function ignoreOccurrenceRequest(templateId, values) {
  return {
    path: `/api/admin/accounting/recurring/${idOrThrow(templateId, 'de modèle')}/ignore`,
    options: { method: 'POST', body: JSON.stringify(pick(values, ['period'])) },
  };
}

/** Dispense de cotisation : l'adhérent (identifiant), le mois (AAAA-MM) et l'état voulu ; rien d'autre ne part. */
export function exemptionRequest(memberId, month, exempt) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) {
    throw new Error('Mois invalide.');
  }

  return {
    path: '/api/admin/accounting/cotisations/exemptions',
    options: { method: 'POST', body: JSON.stringify({ memberUserId: idOrThrow(memberId, 'd\'adhérent'), periodMonth: month, exempt: exempt === true }) },
  };
}

/** « Rien à saisir ce mois-ci » : le mois (AAAA-MM) est le seul champ ; l'exercice est dans l'adresse. */
export function emptyMonthRequest(yearId, month) {
  if (typeof month !== 'string' || !/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) {
    throw new Error('Mois invalide.');
  }

  return { path: `/api/admin/accounting/years/${idOrThrow(yearId, 'd\'exercice')}/empty-month`, options: { method: 'POST', body: JSON.stringify({ month }) } };
}
