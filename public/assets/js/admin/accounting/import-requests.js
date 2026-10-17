/**
 * Requêtes et logique pure de la page « Importer la banque » (#362), sans DOM. Le fichier part en multipart avec les seuls champs du profil de
 * colonnes ; à la validation ne partent que ce que la personne a relu (jamais la date, le montant ni le sens : le serveur les tient de la
 * ligne en attente). Un identifiant doit être une chaîne de chiffres sans zéro initial avant de toucher une adresse.
 */
const ID = /^[1-9]\d{0,9}$/;
export const PROFILE_FIELDS = ['delimiter', 'encoding', 'skipRows', 'dateColumn', 'labelColumn', 'amountMode', 'amountColumn', 'debitColumn', 'creditColumn', 'decimalSeparator', 'dateFormat'];
const ROW_FIELDS = ['label', 'category', 'paymentMethod', 'voucherRef', 'memberUserId', 'periodMonth', 'occurrence'];

function idOrThrow(id, what) {
  if (typeof id !== 'string' || !ID.test(id)) {
    throw new Error(`Identifiant ${what} invalide.`);
  }

  return id;
}

function fileRequest(path, values, file) {
  if (!(file instanceof Blob) || file.size === 0) {
    throw new Error('Choisissez le fichier CSV de la banque.');
  }
  const body = new FormData();
  for (const key of PROFILE_FIELDS) {
    if (values[key] !== undefined) {
      body.set(key, values[key]);
    }
  }
  body.set('file', file);

  return { path, options: { method: 'POST', body } };
}

export const previewRequest = (values, file) => fileRequest('/api/admin/accounting/imports/preview', values, file);
export const stageRequest = (values, file) => fileRequest('/api/admin/accounting/imports', values, file);

export function discardLineRequest(lineId) {
  return { path: `/api/admin/accounting/imports/lines/${idOrThrow(lineId, 'de ligne')}/discard`, options: { method: 'POST', body: '{}' } };
}

/** @param {Array<Record<string, unknown>>} cards une entrée par ligne affichée : `line`, `include` et les champs relus */
export function validateRequest(cards) {
  const rows = [];
  for (const card of cards) {
    if (card.include !== true) {
      continue;
    }
    const row = { line: idOrThrow(String(card.line), 'de ligne') };
    for (const key of ROW_FIELDS) {
      const value = card[key];
      if (typeof value === 'string' && value.trim() !== '') {
        row[key] = value.trim();
      }
    }
    if (card.remember === true) {
      row.remember = true;
    }
    rows.push(row);
  }
  if (rows.length === 0) {
    throw new Error('Cochez au moins une ligne à valider.');
  }

  return { path: '/api/admin/accounting/imports/validate', options: { method: 'POST', body: JSON.stringify({ rows }) } };
}

export function ruleRequest(ruleId, values) {
  const body = { category: values.category, paymentMethod: values.paymentMethod };

  return { path: `/api/admin/accounting/rules/${idOrThrow(ruleId, 'de règle')}`, options: { method: 'PUT', body: JSON.stringify(body) } };
}

export function deleteRuleRequest(ruleId) {
  return { path: `/api/admin/accounting/rules/${idOrThrow(ruleId, 'de règle')}`, options: { method: 'DELETE' } };
}

/** « 12 » → { line: '12', field: 'category' } pour une clé `12.category` du serveur ; null pour toute autre clé. */
export function splitErrorKey(key) {
  const match = /^([1-9]\d{0,9})\.([A-Za-z]+)$/.exec(key);

  return match === null ? null : { line: match[1], field: match[2] };
}

/** Centimes → « 12,50 € » avec espaces insécables, signe − pour une dépense (jamais de nombre à virgule). */
export function formatCents(cents, direction) {
  const euros = String(Math.trunc(cents / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const text = `${euros},${String(cents % 100).padStart(2, '0')} €`;

  return direction === 'depense' ? `−${text}` : `+${text}`;
}

export function previewSummary(preview) {
  const refused = Object.keys(preview.errors ?? {}).filter((key) => key !== '0').length;
  const read = preview.total === 1 ? '1 ligne lue' : `${preview.total} lignes lues`;

  return refused === 0 ? read : `${read}, ${refused} refusée${refused > 1 ? 's' : ''}`;
}
