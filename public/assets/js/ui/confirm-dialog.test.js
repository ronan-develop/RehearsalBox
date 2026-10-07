import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeConfirmation, isOutsideRect } from './confirm-dialog.js';

test('describeConfirmation splits a multi-line message into paragraphs and keeps raw text (the component uses textContent)', () => {
  const described = describeConfirmation('Première ligne\n<b>Seconde</b> ligne');

  assert.deepEqual(described.paragraphs, ['Première ligne', '<b>Seconde</b> ligne']);
});

test('describeConfirmation defaults to no title and the standard labels', () => {
  assert.deepEqual(describeConfirmation('Texte'), { title: '', paragraphs: ['Texte'], confirmLabel: 'Confirmer', cancelLabel: 'Annuler' });
});

test('describeConfirmation keeps an optional title and custom labels', () => {
  const described = describeConfirmation('Texte', { title: 'Supprimer ?', confirmLabel: 'Mettre à la corbeille', cancelLabel: 'Garder' });

  assert.equal(described.title, 'Supprimer ?');
  assert.equal(described.confirmLabel, 'Mettre à la corbeille');
  assert.equal(described.cancelLabel, 'Garder');
});

test('describeConfirmation tolerates a missing message', () => {
  assert.deepEqual(describeConfirmation(undefined).paragraphs, ['']);
});

test('isOutsideRect is true only outside the window rectangle, edges included inside', () => {
  const rect = { left: 100, right: 400, top: 50, bottom: 250 };

  assert.equal(isOutsideRect(rect, 200, 100), false);
  assert.equal(isOutsideRect(rect, 100, 50), false);
  assert.equal(isOutsideRect(rect, 400, 250), false);
  assert.equal(isOutsideRect(rect, 99, 100), true);
  assert.equal(isOutsideRect(rect, 200, 251), true);
  assert.equal(isOutsideRect(rect, 401, 100), true);
  assert.equal(isOutsideRect(rect, 200, 49), true);
});
