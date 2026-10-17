import test from 'node:test';
import assert from 'node:assert/strict';
import { approveYearRequest, closeYearRequest, createYearRequest, depositStatementRequest, emptyMonthRequest, exemptionRequest, confirmOccurrenceRequest, ignoreOccurrenceRequest, templateRequest, recordEntryRequest, reverseRequest } from './accounting-requests.js';

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

test('clôture et approbation : bonne route, seuls les champs attendus, identifiant contrôlé', () => {
  assert.deepEqual(closeYearRequest('4', { revision: '3', status: 'approuve' }), {
    path: '/api/admin/accounting/years/4/close',
    options: { method: 'POST', body: '{"revision":3}' },
  });
  assert.deepEqual(approveYearRequest('4', { approvedOn: '2026-11-20', status: 'x' }), {
    path: '/api/admin/accounting/years/4/approve',
    options: { method: 'POST', body: '{"approvedOn":"2026-11-20"}' },
  });
  assert.throws(() => closeYearRequest('4/../x', { revision: '1' }));
  assert.throws(() => approveYearRequest('0', {}));
  assert.throws(() => closeYearRequest('4', { revision: 'abc' }));
});

test('le dépôt d\'un relevé part en multipart avec les seuls champs attendus, sans « replaces » vide', () => {
  const file = new Blob(['%PDF-1.4'], { type: 'application/pdf' });
  const { path, options } = depositStatementRequest('4', { periodStart: '2025-10-01', periodEnd: '2025-10-31', closingBalance: '120,00', replaces: '', storedName: 'x', sha256: 'y' }, file);

  assert.equal(path, '/api/admin/accounting/statements');
  assert.equal(options.method, 'POST');
  assert.ok(options.body instanceof FormData);
  assert.deepEqual([...options.body.keys()].sort(), ['closingBalance', 'file', 'fiscalYearId', 'periodEnd', 'periodStart']);
  assert.equal(options.body.get('fiscalYearId'), '4');
  assert.equal(options.body.get('closingBalance'), '120,00');
  assert.equal(depositStatementRequest('4', { periodStart: 'a', periodEnd: 'b', replaces: '7' }, file).options.body.get('replaces'), '7');
  assert.throws(() => depositStatementRequest('4x', {}, file));
  assert.throws(() => depositStatementRequest('4', { replaces: '0' }, file));
});

test('modèle récurrent : création en POST, modification en PUT, case décochée = false, identifiant contrôlé', () => {
  const values = { label: 'Loyer', direction: 'depense', category: 'loyer', paymentMethod: 'virement', amount: '498,00', voucherRef: '', periodicity: 'trimestrielle', dueDay: '5', firstMonth: '2025-10', isActive: '1', role: 'admin' };
  const created = templateRequest('', values);
  assert.equal(created.path, '/api/admin/accounting/recurring');
  assert.equal(created.options.method, 'POST');
  assert.equal(JSON.parse(created.options.body).isActive, true);
  assert.equal('role' in JSON.parse(created.options.body), false);
  const updated = templateRequest('4', { ...values, isActive: undefined });
  assert.equal(updated.path, '/api/admin/accounting/recurring/4');
  assert.equal(updated.options.method, 'PUT');
  assert.equal(JSON.parse(updated.options.body).isActive, false);
  assert.throws(() => templateRequest('4/x', values));
});

test('confirmer ou abandonner une échéance n\'envoie que l\'échéance, le montant et la pièce', () => {
  const confirm = confirmOccurrenceRequest('4', { period: '2025-10-01', amount: '63,40', voucherRef: 'F-1', label: 'Piraté', direction: 'recette' });
  assert.equal(confirm.path, '/api/admin/accounting/recurring/4/confirm');
  assert.deepEqual(JSON.parse(confirm.options.body), { period: '2025-10-01', amount: '63,40', voucherRef: 'F-1' });
  const ignore = ignoreOccurrenceRequest('4', { period: '2025-10-01', amount: '1' });
  assert.equal(ignore.path, '/api/admin/accounting/recurring/4/ignore');
  assert.deepEqual(JSON.parse(ignore.options.body), { period: '2025-10-01' });
  assert.throws(() => confirmOccurrenceRequest('0', {}));
});

test('cotisation d\'un adhérent : identifiant et mois partent avec l\'écriture ; sans adhérent, le mois est retiré', () => {
  const withMember = recordEntryRequest('4', { label: 'Cotisation', memberUserId: '12', periodMonth: '2025-11', amount: '20,00' });
  assert.equal(JSON.parse(withMember.options.body).memberUserId, '12');
  assert.equal(JSON.parse(withMember.options.body).periodMonth, '2025-11');
  const without = JSON.parse(recordEntryRequest('4', { label: 'Don', memberUserId: '', periodMonth: '2025-11' }).options.body);
  assert.equal('memberUserId' in without, false);
  assert.equal('periodMonth' in without, false);
});

test('dispense : route, corps minimal, identifiant et mois contrôlés', () => {
  assert.deepEqual(exemptionRequest('12', '2025-11', true), {
    path: '/api/admin/accounting/cotisations/exemptions',
    options: { method: 'POST', body: '{"memberUserId":"12","periodMonth":"2025-11","exempt":true}' },
  });
  assert.equal(JSON.parse(exemptionRequest('12', '2025-11', 'oui').options.body).exempt, false);
  assert.throws(() => exemptionRequest('0', '2025-11', true));
  assert.throws(() => exemptionRequest('12', '2025-13', true));
  assert.throws(() => exemptionRequest('12', '2025-11-01', true));
});

test('« rien à saisir ce mois-ci » : route de l\'exercice, mois seul, tout est contrôlé', () => {
  assert.deepEqual(emptyMonthRequest('4', '2025-11'), {
    path: '/api/admin/accounting/years/4/empty-month',
    options: { method: 'POST', body: '{"month":"2025-11"}' },
  });
  assert.throws(() => emptyMonthRequest('0', '2025-11'));
  assert.throws(() => emptyMonthRequest('4', '2025-11-01'));
  assert.throws(() => emptyMonthRequest('4', '2025-13'));
  assert.throws(() => emptyMonthRequest('4', ['2025-11']));
});
