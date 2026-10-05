import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { ComposerDrafts } from './composer-drafts.js';

function setup({ saved = '', fieldValue = '', suspended = false } = {}) {
  const field = { value: fieldValue };
  const saves = [];
  const store = { clearExpired: () => saves.push('clearExpired'), load: () => saved, save: (key, text) => saves.push([key, text]) };
  const state = { suspended };
  const restored = [];
  mock.timers.enable({ apis: ['setTimeout'] });
  const drafts = new ComposerDrafts({ field, isSuspended: () => state.suspended, delayMs: 300 });
  drafts.configure(store, 'conv-12', () => restored.push(field.value));

  return { field, saves, state, restored, drafts };
}

test('a saved draft comes back into an empty field and the caller is told', () => {
  const { field, restored } = setup({ saved: 'Brouillon' });
  assert.equal(field.value, 'Brouillon');
  assert.deepEqual(restored, ['Brouillon']);
  mock.timers.reset();
});

test('a draft never overwrites what the person already typed', () => {
  const { field, restored } = setup({ saved: 'Ancien', fieldValue: 'Déjà tapé' });
  assert.equal(field.value, 'Déjà tapé');
  assert.deepEqual(restored, []);
  mock.timers.reset();
});

test('typing is saved once, after the delay, whatever the number of keystrokes', () => {
  const { field, saves, drafts } = setup();
  saves.length = 0;
  field.value = 'a';
  drafts.schedule();
  mock.timers.tick(200);
  field.value = 'ab';
  drafts.schedule();
  mock.timers.tick(299);
  assert.deepEqual(saves, []);
  mock.timers.tick(1);

  assert.deepEqual(saves, [['conv-12', 'ab']]);
  mock.timers.reset();
});

test('flush saves right away and cancels the pending save', () => {
  const { field, saves, drafts } = setup();
  saves.length = 0;
  field.value = 'Texte';
  drafts.schedule();

  drafts.flush();
  mock.timers.tick(1000);

  assert.deepEqual(saves, [['conv-12', 'Texte']]);
  mock.timers.reset();
});

test('while suspended (correcting a sent message) nothing is ever saved', () => {
  const { field, saves, state, drafts } = setup();
  saves.length = 0;
  state.suspended = true;
  field.value = 'Texte déjà envoyé';

  drafts.schedule();
  mock.timers.tick(1000);
  drafts.flush();

  assert.deepEqual(saves, []);
  mock.timers.reset();
});
