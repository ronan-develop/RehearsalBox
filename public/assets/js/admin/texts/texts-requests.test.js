import test from 'node:test';
import assert from 'node:assert/strict';
import { DELETE_WARNING, createRequest, deleteRequest, printStamp, updateRequest } from './texts-requests.js';

test('chaque requête vise la bonne route, la bonne méthode et le bon corps', () => {
  assert.deepEqual(createRequest('Règlement', 'Pas de bruit après 22 h'), { path: '/api/admin/texts', options: { method: 'POST', body: '{"title":"Règlement","body":"Pas de bruit après 22 h"}' } });
  assert.deepEqual(updateRequest('12', 3, 'Règlement', 'Texte'), { path: '/api/admin/texts/12', options: { method: 'PUT', body: '{"revision":3,"title":"Règlement","body":"Texte"}' } });
  assert.deepEqual(deleteRequest('12'), { path: '/api/admin/texts/12', options: { method: 'DELETE' } });
});

test('un identifiant qui n\'est pas une chaîne de chiffres est refusé', () => {
  for (const id of ['', 'abc', '1/../2', 12, undefined]) {
    assert.throws(() => deleteRequest(id), /invalide/);
    assert.throws(() => updateRequest(id, 1, 'T', 'B'), /invalide/);
  }
});

test('l\'avertissement de suppression demande confirmation et précise l\'effacement', () => {
  assert.equal(DELETE_WARNING, 'Supprimer ce texte ? Il sera effacé définitivement.');
});

test('le cachet d\'impression porte la date du jour en français', () => {
  assert.equal(printStamp(new Date(2026, 9, 16)), 'Imprimé le 16/10/2026');
  assert.equal(printStamp(new Date(2026, 0, 5)), 'Imprimé le 05/01/2026');
});
