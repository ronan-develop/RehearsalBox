import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EVT, emit } from './events.js';

test('emit dispatches a bubbling CustomEvent carrying its detail', () => {
  const target = new EventTarget();
  let received = null;
  target.addEventListener(EVT.SUBMIT, (event) => { received = event; });

  emit(target, EVT.SUBMIT, { text: 'Salut' });

  assert.equal(received.type, 'composer:submit');
  assert.equal(received.bubbles, true);
  assert.deepEqual(received.detail, { text: 'Salut' });
});

test('the event contract between components is namespaced and stable', () => {
  assert.deepEqual(EVT, {
    SUBMIT: 'composer:submit',
    TYPING: 'composer:typing',
    RENAME: 'header:rename',
    SELECT: 'sidebar:select',
    ARCHIVES: 'sidebar:archives',
    BACK: 'header:back',
  });
});
