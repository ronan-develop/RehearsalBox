import test from 'node:test';
import assert from 'node:assert/strict';
import { VACATE_WARNING, assignRequest, parseUserId, vacateRequest } from './board-requests.js';

test('chaque requête vise le bon poste, la bonne méthode et le bon corps', () => {
  assert.deepEqual(assignRequest('president', 12, 'Alice Martin'), { path: '/api/admin/board/president', options: { method: 'PUT', body: '{"userId":12,"fullName":"Alice Martin"}' } });
  assert.deepEqual(vacateRequest('tresorier'), { path: '/api/admin/board/tresorier', options: { method: 'DELETE' } });
});

test('un poste qui n\'est pas l\'un des trois est refusé', () => {
  for (const post of ['', 'chef', 'president/../x', 'PRESIDENT', undefined, 3]) {
    assert.throws(() => assignRequest(post, 1, 'A'), /Poste invalide/);
    assert.throws(() => vacateRequest(post), /Poste invalide/);
  }
});

test('le compte choisi est un entier, sinon null (le serveur refuse)', () => {
  assert.equal(parseUserId('12'), 12);
  assert.equal(parseUserId(''), null);
  assert.equal(parseUserId('abc'), null);
  assert.equal(parseUserId('1.5'), null);
  assert.equal(parseUserId('0'), null);
});

test('l\'avertissement dit que les comptes rendus déjà validés ne changent pas', () => {
  assert.match(VACATE_WARNING, /validés/);
});
