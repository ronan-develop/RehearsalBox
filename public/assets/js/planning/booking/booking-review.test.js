import { test } from 'node:test';
import assert from 'node:assert/strict';
import { decisionRequest, outcomeMessage, pendingCount, failureMessage, isAlreadyDecided } from './booking-review.js';

test('approving posts to the approve route with no body', () => {
  assert.deepEqual(decisionRequest('12', 'approve'), { path: '/api/admin/bookings/12/approve', options: { method: 'POST' } });
});

test('refusing carries the trimmed note, and omits an empty one', () => {
  assert.deepEqual(decisionRequest('12', 'refuse', '  Local fermé  '), {
    path: '/api/admin/bookings/12/refuse',
    options: { method: 'POST', body: JSON.stringify({ note: 'Local fermé' }) },
  });
  assert.deepEqual(decisionRequest('12', 'refuse', '   '), { path: '/api/admin/bookings/12/refuse', options: { method: 'POST' } });
  assert.deepEqual(decisionRequest('12', 'refuse'), { path: '/api/admin/bookings/12/refuse', options: { method: 'POST' } });
});

test('the identifier must be strictly numeric, so nothing else can ever reach the URL', () => {
  for (const bad of ['', 'abc', '12/../x', '1 2', '-1', '1.5', undefined, null]) {
    assert.throws(() => decisionRequest(bad, 'approve'), /identifiant/i, String(bad));
  }
});

test('only approve and refuse are decisions', () => {
  assert.throws(() => decisionRequest('1', 'delete'), /décision/i);
});

test('the outcome message says what happened', () => {
  assert.equal(outcomeMessage('approve'), 'Réservation validée.');
  assert.equal(outcomeMessage('refuse'), 'Réservation refusée.');
});

test('the pending count reads the list and tolerates anything else', () => {
  assert.equal(pendingCount({ bookings: [{ id: 1 }, { id: 2 }] }), 2);
  assert.equal(pendingCount({ bookings: [] }), 0);
  for (const bad of [null, undefined, {}, { bookings: 'x' }, 'x', 5]) {
    assert.equal(pendingCount(bad), 0, String(bad));
  }
});

test('an already-decided booking gets a clear message, any other failure keeps the server text (a 409 can also mean a new fixed slot)', () => {
  assert.equal(failureMessage({ status: 409, message: 'Cette réservation a déjà été traitée.' }), 'Cette réservation a déjà été traitée par un autre administrateur.');
  assert.equal(failureMessage({ status: 409, message: 'Cette plage chevauche un créneau fixe : faites une demande au groupe concerné.' }), 'Cette plage chevauche un créneau fixe : faites une demande au groupe concerné.');
  assert.equal(failureMessage({ status: 422, message: 'Le motif ne peut pas dépasser 255 caractères.' }), 'Le motif ne peut pas dépasser 255 caractères.');
  assert.equal(failureMessage({}), 'Une erreur est survenue.');
  assert.equal(failureMessage(null), 'Une erreur est survenue.');
});

test('only a 409 saying the booking was already handled counts as already decided', () => {
  assert.equal(isAlreadyDecided({ status: 409, message: 'Cette réservation a déjà été traitée.' }), true);
  assert.equal(isAlreadyDecided({ status: 409, message: 'Cette plage chevauche un créneau fixe.' }), false);
  assert.equal(isAlreadyDecided({ status: 422, message: 'déjà été traitée' }), false);
  assert.equal(isAlreadyDecided(null), false);
});
