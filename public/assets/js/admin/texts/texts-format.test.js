import test from 'node:test';
import assert from 'node:assert/strict';
import { toggleHeading } from './texts-format.js';

test('un titre est posé sur la ligne du curseur, le curseur reste sur la même lettre', () => {
  assert.deepEqual(toggleHeading('un\ndeux\ntrois', 5, 1), { value: 'un\n# deux\ntrois', caret: 7 });
  assert.deepEqual(toggleHeading('un\ndeux\ntrois', 5, 2), { value: 'un\n## deux\ntrois', caret: 8 });
});

test('le même bouton retire le préfixe, l\'autre niveau le remplace', () => {
  assert.deepEqual(toggleHeading('# un', 3, 1), { value: 'un', caret: 1 });
  assert.deepEqual(toggleHeading('## un', 4, 1), { value: '# un', caret: 3 });
  assert.deepEqual(toggleHeading('# un', 3, 2), { value: '## un', caret: 4 });
});

test('première et dernière ligne, texte vide', () => {
  assert.deepEqual(toggleHeading('a\nb', 0, 1), { value: '# a\nb', caret: 2 });
  assert.deepEqual(toggleHeading('a\nb', 3, 1), { value: 'a\n# b', caret: 5 });
  assert.deepEqual(toggleHeading('', 0, 1), { value: '# ', caret: 2 });
});

test('un curseur hors du texte est ramené dans le texte', () => {
  assert.deepEqual(toggleHeading('ab', 99, 1), { value: '# ab', caret: 4 });
  assert.deepEqual(toggleHeading('ab', -3, 1), { value: '# ab', caret: 2 });
});

test('un niveau inconnu ne change rien', () => {
  assert.deepEqual(toggleHeading('ab', 1, 3), { value: 'ab', caret: 1 });
  assert.deepEqual(toggleHeading('ab', 1, 'x'), { value: 'ab', caret: 1 });
});
