import test from 'node:test';
import assert from 'node:assert/strict';
import { deleteRuleRequest, discardLineRequest, formatCents, previewRequest, previewSummary, ruleRequest, splitErrorKey, stageRequest, validateRequest } from './import-requests.js';

const PDF = new Blob(['x'], { type: 'text/csv' });

test('aperçu et mise en attente : multipart, seuls les champs du profil, jamais autre chose', () => {
  const { path, options } = previewRequest({ delimiter: 'semicolon', dateColumn: '1', role: 'admin', amount: '1' }, PDF);

  assert.equal(path, '/api/admin/accounting/imports/preview');
  assert.equal(options.method, 'POST');
  assert.ok(options.body instanceof FormData);
  assert.deepEqual([...options.body.keys()].sort(), ['dateColumn', 'delimiter', 'file']);
  assert.equal(stageRequest({}, PDF).path, '/api/admin/accounting/imports');
});

test('sans fichier ou avec un fichier vide, rien ne part', () => {
  assert.throws(() => previewRequest({}, null));
  assert.throws(() => stageRequest({}, new Blob([])));
});

test('la validation n\'envoie que les lignes cochées et les champs relus, jamais le montant ni la date', () => {
  const { path, options } = validateRequest([
    { line: 12, include: true, label: ' Don ', category: 'autres_recettes', paymentMethod: 'virement', voucherRef: '', amount: '999', occurredOn: '2026-01-01', remember: true },
    { line: 13, include: false, category: 'materiel' },
    { line: 14, include: true, category: 'cotisations', memberUserId: '7', periodMonth: '2025-11', occurrence: '' },
  ]);

  assert.equal(path, '/api/admin/accounting/imports/validate');
  assert.deepEqual(JSON.parse(options.body), { rows: [
    { line: '12', label: 'Don', category: 'autres_recettes', paymentMethod: 'virement', remember: true },
    { line: '14', category: 'cotisations', memberUserId: '7', periodMonth: '2025-11' },
  ] });
});

test('aucune ligne cochée ou identifiant invalide : refus avant tout envoi', () => {
  assert.throws(() => validateRequest([{ line: 1, include: false }]));
  assert.throws(() => validateRequest([]));
  assert.throws(() => validateRequest([{ line: '0', include: true }]));
  assert.throws(() => validateRequest([{ line: '1/../2', include: true }]));
});

test('écarter une ligne, modifier et supprimer une règle : routes et identifiants contrôlés', () => {
  assert.equal(discardLineRequest('5').path, '/api/admin/accounting/imports/lines/5/discard');
  assert.throws(() => discardLineRequest('abc'));
  assert.deepEqual(ruleRequest('3', { category: 'materiel', paymentMethod: 'carte', id: 9 }), { path: '/api/admin/accounting/rules/3', options: { method: 'PUT', body: '{"category":"materiel","paymentMethod":"carte"}' } });
  assert.equal(deleteRuleRequest('3').options.method, 'DELETE');
  assert.throws(() => ruleRequest('0', {}));
  assert.throws(() => deleteRuleRequest('-1'));
});

test('une clé d\'erreur « ligne.champ » est découpée, tout le reste est ignoré', () => {
  assert.deepEqual(splitErrorKey('12.category'), { line: '12', field: 'category' });
  assert.equal(splitErrorKey('rows'), null);
  assert.equal(splitErrorKey('rows.0'), null);
  assert.equal(splitErrorKey('0.category'), null);
  assert.equal(splitErrorKey('12.cat-egory'), null);
});

test('les montants s\'écrivent à la française avec leur sens, sans flottant', () => {
  assert.equal(formatCents(1250, 'recette'), '+12,50 €');
  assert.equal(formatCents(7, 'depense'), '−0,07 €');
  assert.equal(formatCents(123456789, 'recette'), '+1 234 567,89 €');
});

test('le résumé de l\'aperçu compte les lignes lues et refusées', () => {
  assert.equal(previewSummary({ total: 1, errors: {} }), '1 ligne lue');
  assert.equal(previewSummary({ total: 3, errors: { 4: 'x', 7: 'y' } }), '3 lignes lues, 2 refusées');
  assert.equal(previewSummary({ total: 0, errors: { 0: 'Fichier vide' } }), '0 lignes lues');
});
