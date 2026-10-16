import test from 'node:test';
import assert from 'node:assert/strict';
import { PREFIXES, linePrefix, toggleLinePrefix } from './texts-format.js';

const SUB = PREFIXES.subtitle;
const LIST = PREFIXES.list;

test('le préfixe est posé sur la ligne du curseur, le curseur reste sur la même lettre', () => {
  assert.deepEqual(toggleLinePrefix('un\ndeux\ntrois', 5, SUB), { value: 'un\n## deux\ntrois', caret: 8 });
  assert.deepEqual(toggleLinePrefix('un\ndeux\ntrois', 5, LIST), { value: 'un\n- deux\ntrois', caret: 7 });
});

test('le même bouton retire le préfixe', () => {
  assert.deepEqual(toggleLinePrefix('## un', 4, SUB), { value: 'un', caret: 1 });
  assert.deepEqual(toggleLinePrefix('a\n- b', 5, LIST), { value: 'a\nb', caret: 3 });
});

test('l\'autre préfixe remplace le premier', () => {
  assert.deepEqual(toggleLinePrefix('## un', 4, LIST), { value: '- un', caret: 3 });
  assert.deepEqual(toggleLinePrefix('- un', 3, SUB), { value: '## un', caret: 4 });
});

test('curseur dans le préfixe lui-même : il ne sort pas de la ligne', () => {
  assert.deepEqual(toggleLinePrefix('## un', 1, SUB), { value: 'un', caret: 0 });
  assert.deepEqual(toggleLinePrefix('- un', 1, LIST), { value: 'un', caret: 0 });
});

test('première et dernière ligne, texte vide', () => {
  assert.deepEqual(toggleLinePrefix('a\nb', 0, LIST), { value: '- a\nb', caret: 2 });
  assert.deepEqual(toggleLinePrefix('a\nb', 3, SUB), { value: 'a\n## b', caret: 6 });
  assert.deepEqual(toggleLinePrefix('', 0, LIST), { value: '- ', caret: 2 });
});

test('un curseur hors du texte est ramené dans le texte', () => {
  assert.deepEqual(toggleLinePrefix('ab', 99, SUB), { value: '## ab', caret: 5 });
  assert.deepEqual(toggleLinePrefix('ab', -3, LIST), { value: '- ab', caret: 2 });
});

test('un préfixe inconnu ne change rien', () => {
  assert.deepEqual(toggleLinePrefix('ab', 1, '# '), { value: 'ab', caret: 1 });
  assert.deepEqual(toggleLinePrefix('ab', 1, undefined), { value: 'ab', caret: 1 });
});

test('le préfixe de la ligne du curseur est reconnu, sinon vide', () => {
  assert.equal(linePrefix('## un', 2), SUB);
  assert.equal(linePrefix('- un', 2), LIST);
  assert.equal(linePrefix('# un', 2), '');
  assert.equal(linePrefix('-un', 1), '');
  assert.equal(linePrefix('un - deux', 4), '');
  assert.equal(linePrefix('a\n## b', 0), '');
  assert.equal(linePrefix('a\n## b', 4), SUB);
});
