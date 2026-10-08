import { test } from 'node:test';
import assert from 'node:assert/strict';
import { durationFor, createToastStack, MAX_TOASTS } from './toasts.js';

test('an error stays longer than the other messages', () => {
  assert.ok(durationFor('error') > durationFor('success'));
  assert.equal(durationFor('success'), durationFor('info'));
});

test('push gives each new message its own id', () => {
  const stack = createToastStack();

  const first = stack.push('Enregistré.', 'success');
  const second = stack.push('Erreur.', 'error');

  assert.equal(first.created, true);
  assert.equal(second.created, true);
  assert.notEqual(first.id, second.id);
  assert.deepEqual(stack.ids(), [first.id, second.id]);
});

test('the same message and type is not stacked twice: the visible one is reused', () => {
  const stack = createToastStack();
  const first = stack.push('Identifiants invalides.', 'error');

  const again = stack.push('Identifiants invalides.', 'error');

  assert.equal(again.created, false);
  assert.equal(again.id, first.id);
  assert.deepEqual(stack.ids(), [first.id]);
});

test('the same text with another type is a different message', () => {
  const stack = createToastStack();
  stack.push('Fait.', 'success');

  assert.equal(stack.push('Fait.', 'info').created, true);
});

test('the stack is bounded: the oldest message is evicted first', () => {
  const stack = createToastStack(2);
  const a = stack.push('a');
  const b = stack.push('b');
  const c = stack.push('c');

  assert.deepEqual(c.evicted, [a.id]);
  assert.deepEqual(stack.ids(), [b.id, c.id]);
});

test('a removed message can be shown again', () => {
  const stack = createToastStack();
  const first = stack.push('Salut');
  stack.remove(first.id);

  const again = stack.push('Salut');

  assert.equal(again.created, true);
  assert.notEqual(again.id, first.id);
  assert.equal(MAX_TOASTS, 4);
});

test('a missing message becomes an empty text instead of throwing', () => {
  assert.doesNotThrow(() => createToastStack().push(undefined));
});
