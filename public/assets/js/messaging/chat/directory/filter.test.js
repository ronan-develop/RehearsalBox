import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchesName, visibility } from './filter.js';

test('une requête vide laisse tout le monde visible', () => {
  assert.equal(matchesName('Alice', ''), true);
  assert.equal(matchesName('Alice', '   '), true);
});

test('la recherche est une sous-chaîne, sans tenir compte de la casse', () => {
  assert.equal(matchesName('Alice Martin', 'mart'), true);
  assert.equal(matchesName('Alice Martin', 'ALICE'), true);
  assert.equal(matchesName('Alice Martin', 'bob'), false);
});

test('les accents ne comptent pas', () => {
  assert.equal(matchesName('Élodie', 'elo'), true);
  assert.equal(matchesName('Elodie', 'élo'), true);
  assert.equal(matchesName('Zoé', 'zoe'), true);
});

test('les espaces autour de la requête sont ignorés', () => {
  assert.equal(matchesName('Alice', '  ali '), true);
});

test('visibility rend un booléen par nom et le nombre de visibles', () => {
  assert.deepEqual(visibility(['Alice', 'Bob', 'Alicia'], 'ali'), { flags: [true, false, true], visible: 2 });
  assert.deepEqual(visibility([], 'x'), { flags: [], visible: 0 });
});
