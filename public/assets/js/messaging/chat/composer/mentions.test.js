import { test } from 'node:test';
import assert from 'node:assert/strict';
import { activeQuery, applyMention, mentionedIds, pendingGuests } from './mentions.js';

test('activeQuery finds the mention being typed at the caret', () => {
  assert.deepEqual(activeQuery('Salut @de', 9), { start: 6, query: 'de' });
  assert.deepEqual(activeQuery('@', 1), { start: 0, query: '' });
  assert.deepEqual(activeQuery('ligne\n@Den', 10), { start: 6, query: 'Den' });
});

test('activeQuery ignores an at sign glued to a word (e-mail address) or far from the caret', () => {
  assert.equal(activeQuery('ecris a bob@exemple', 19), null);
  assert.equal(activeQuery('Salut @Denis, ça va', 19), null, 'le curseur est loin de la mention');
  assert.equal(activeQuery('pas de arobase', 5), null);
});

test('activeQuery allows one space for a first and last name but not two, nor a newline, nor a very long query', () => {
  assert.deepEqual(activeQuery('@Denis Mar', 10), { start: 0, query: 'Denis Mar' });
  assert.equal(activeQuery('@Denis Martin et', 16), null);
  assert.equal(activeQuery('@Den\nis', 7), null);
  assert.equal(activeQuery(`@${'a'.repeat(31)}`, 32), null);
});

test('applyMention replaces the typed query by the chosen name and moves the caret after it', () => {
  const result = applyMention('Salut @de et plus', 6, 9, 'Denis Martin');

  assert.equal(result.text, 'Salut @Denis Martin  et plus');
  assert.equal(result.caret, 'Salut @Denis Martin '.length);
});

test('mentionedIds keeps only the people whose @Name is still in the text', () => {
  const picks = new Map([[7, { name: 'Denis', participant: false }], [8, { name: 'Bob', participant: true }]]);

  assert.deepEqual(mentionedIds('Salut @Denis et Bob', picks), [7]);
  assert.deepEqual(mentionedIds('', picks), []);
});

test('mentionedIds does not confuse a name with a longer one that starts the same way', () => {
  const picks = new Map([[7, { name: 'Denis', participant: false }]]);

  assert.deepEqual(mentionedIds('Salut @Denise', picks), []);
  assert.deepEqual(mentionedIds('Salut @Denis.', picks), [7]);
});

test('pendingGuests lists the names of mentioned people who are not in the conversation yet', () => {
  const picks = new Map([[7, { name: 'Denis', participant: false }], [8, { name: 'Bob', participant: true }], [9, { name: 'Erin', participant: false }]]);

  assert.deepEqual(pendingGuests('@Denis @Bob', picks), ['Denis']);
  assert.deepEqual(pendingGuests('rien', picks), []);
});
