import test from 'node:test';
import assert from 'node:assert/strict';
import { isSubtitle, toggleSubtitle } from './texts-format.js';

test('le préfixe est posé sur la ligne du curseur, le curseur reste sur la même lettre', () => {
  assert.deepEqual(toggleSubtitle('un\ndeux\ntrois', 5), { value: 'un\n## deux\ntrois', caret: 8 });
});

test('le même bouton retire le préfixe', () => {
  assert.deepEqual(toggleSubtitle('## un', 4), { value: 'un', caret: 1 });
  assert.deepEqual(toggleSubtitle('a\n## b', 6), { value: 'a\nb', caret: 3 });
});

test('curseur dans le préfixe lui-même : il ne sort pas de la ligne', () => {
  assert.deepEqual(toggleSubtitle('## un', 1), { value: 'un', caret: 0 });
});

test('première et dernière ligne, texte vide', () => {
  assert.deepEqual(toggleSubtitle('a\nb', 0), { value: '## a\nb', caret: 3 });
  assert.deepEqual(toggleSubtitle('a\nb', 3), { value: 'a\n## b', caret: 6 });
  assert.deepEqual(toggleSubtitle('', 0), { value: '## ', caret: 3 });
});

test('un curseur hors du texte est ramené dans le texte', () => {
  assert.deepEqual(toggleSubtitle('ab', 99), { value: '## ab', caret: 5 });
  assert.deepEqual(toggleSubtitle('ab', -3), { value: '## ab', caret: 3 });
});

test('seule la ligne qui commence par « ## » est un sous-titre', () => {
  assert.equal(isSubtitle('## un', 2), true);
  assert.equal(isSubtitle('# un', 2), false);
  assert.equal(isSubtitle('un ## deux', 4), false);
  assert.equal(isSubtitle('a\n## b', 0), false);
  assert.equal(isSubtitle('a\n## b', 4), true);
});
