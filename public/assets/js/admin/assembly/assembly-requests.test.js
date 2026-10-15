import test from 'node:test';
import assert from 'node:assert/strict';
import { correctRequest, createRequest, deleteRequest, failureMessage, isStale, saveRequest, validateRequest } from './assembly-requests.js';

test('chaque requête vise la bonne route, la bonne méthode et le bon corps', () => {
  assert.deepEqual(createRequest('ordinaire', '2026-10-10'), { path: '/api/admin/assemblies', options: { method: 'POST', body: '{"kind":"ordinaire","heldOn":"2026-10-10"}' } });
  assert.deepEqual(saveRequest('12', { revision: 3 }), { path: '/api/admin/assemblies/12', options: { method: 'PUT', body: '{"revision":3}' } });
  assert.deepEqual(validateRequest('12', 4), { path: '/api/admin/assemblies/12/validate', options: { method: 'POST', body: '{"revision":4}' } });
  assert.deepEqual(correctRequest('12', 'Faute'), { path: '/api/admin/assemblies/12/corrections', options: { method: 'POST', body: '{"reason":"Faute"}' } });
  assert.deepEqual(deleteRequest('12'), { path: '/api/admin/assemblies/12', options: { method: 'DELETE' } });
});

test('un identifiant qui n\'est pas une chaîne de chiffres est refusé', () => {
  for (const id of ['', 'abc', '1/../2', '1?x=1', undefined, 12, null]) {
    assert.throws(() => deleteRequest(id), /invalide/);
  }
});

test('409 = page périmée ; le message du serveur est conservé, sinon un message par défaut', () => {
  assert.equal(isStale({ status: 409 }), true);
  assert.equal(isStale({ status: 422 }), false);
  assert.equal(isStale(undefined), false);
  assert.equal(failureMessage({ message: 'Déjà validé.' }), 'Déjà validé.');
  assert.equal(failureMessage({}), 'Une erreur est survenue.');
  assert.equal(failureMessage(null), 'Une erreur est survenue.');
});
