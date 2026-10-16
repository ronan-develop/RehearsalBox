import test from 'node:test';
import assert from 'node:assert/strict';
import { createYearRequest, recordEntryRequest, reverseRequest } from './accounting-requests.js';

test('chaque requête vise la bonne route et ne porte que les champs attendus', () => {
  assert.deepEqual(createYearRequest({ label: '2025-2026', startsOn: '2025-10-01', endsOn: '2026-09-30', openingBalance: '100,00', role: 'admin' }), {
    path: '/api/admin/accounting/years',
    options: { method: 'POST', body: '{"label":"2025-2026","startsOn":"2025-10-01","endsOn":"2026-09-30","openingBalance":"100,00"}' },
  });
  const entry = recordEntryRequest('4', { occurredOn: '2025-11-05', label: 'Cotisation', amount: '20,00', direction: 'recette', category: 'cotisations', paymentMethod: 'virement', voucherRef: '', note: '', entryNumber: 99, createdBy: 1 });
  assert.equal(entry.path, '/api/admin/accounting/years/4/entries');
  assert.equal(entry.options.method, 'POST');
  assert.deepEqual(Object.keys(JSON.parse(entry.options.body)).sort(), ['amount', 'category', 'direction', 'label', 'note', 'occurredOn', 'paymentMethod', 'voucherRef']);
  assert.deepEqual(reverseRequest('7', { reason: 'Erreur', occurredOn: '2025-11-06', fiscalYearId: 3 }), {
    path: '/api/admin/accounting/entries/7/reverse',
    options: { method: 'POST', body: '{"reason":"Erreur","occurredOn":"2025-11-06"}' },
  });
});

test('le solde d\'ouverture vide n\'est pas envoyé (le report est calculé)', () => {
  const { options } = createYearRequest({ label: 'X', startsOn: '2026-10-01', endsOn: '2027-09-30', openingBalance: '' });
  assert.equal('openingBalance' in JSON.parse(options.body), false);
});

test('le montant reste du texte : jamais converti en nombre', () => {
  const { options } = recordEntryRequest('1', { amount: '12,50' });
  assert.equal(JSON.parse(options.body).amount, '12,50');
  assert.equal(typeof JSON.parse(options.body).amount, 'string');
});

test('un identifiant qui n\'est pas une chaîne de chiffres sans zéro initial est refusé', () => {
  for (const id of ['', '0', '007', 'abc', '1/../2', '1e0', ' 1', '-1', '1.5', 12, undefined, null, '99999999999']) {
    assert.throws(() => recordEntryRequest(id, {}), /invalide/, `année ${String(id)}`);
    assert.throws(() => reverseRequest(id, {}), /invalide/, `écriture ${String(id)}`);
  }
});
