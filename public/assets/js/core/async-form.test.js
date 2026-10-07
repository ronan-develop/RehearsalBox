import { test } from 'node:test';
import assert from 'node:assert/strict';
import { serializeFormEntries, requestFor, fieldErrorEntries, createSubmitGuard } from './async-form.js';

test('serializeFormEntries converts FormData-like entries into a plain object', () => {
  assert.deepEqual(serializeFormEntries([['email', 'alice@rehearsalbox.test'], ['password', 'secret']]), { email: 'alice@rehearsalbox.test', password: 'secret' });
});

test('serializeFormEntries handles an empty entries list and keeps the last value of a repeated field', () => {
  assert.deepEqual(serializeFormEntries([]), {});
  assert.deepEqual(serializeFormEntries([['a', '1'], ['a', '2']]), { a: '2' });
});

test('requestFor defaults to POST and upper-cases the method', () => {
  assert.deepEqual(requestFor({ endpoint: '/api/x' }), { endpoint: '/api/x', method: 'POST' });
  assert.deepEqual(requestFor({ endpoint: '/api/x', method: 'patch' }), { endpoint: '/api/x', method: 'PATCH' });
  assert.deepEqual(requestFor({ endpoint: '/api/x', method: '' }), { endpoint: '/api/x', method: 'POST' });
});

test('requestFor refuses a form without an endpoint instead of falling back to a native submit', () => {
  assert.equal(requestFor({}), null);
  assert.equal(requestFor({ endpoint: '' }), null);
  assert.equal(requestFor({ endpoint: null, method: 'POST' }), null);
});

test('fieldErrorEntries keeps only non-empty text messages', () => {
  assert.deepEqual(fieldErrorEntries({ email: 'Adresse invalide.', name: '', age: 3, nested: { a: 1 } }), [['email', 'Adresse invalide.']]);
  assert.deepEqual(fieldErrorEntries(undefined), []);
  assert.deepEqual(fieldErrorEntries(null), []);
  assert.deepEqual(fieldErrorEntries('texte'), []);
});

test('the submit guard ignores a second submit while one is in flight and accepts again afterwards', () => {
  const guard = createSubmitGuard();

  assert.equal(guard.tryEnter(), true);
  assert.equal(guard.tryEnter(), false);
  assert.equal(guard.tryEnter(), false);
  guard.leave();
  assert.equal(guard.tryEnter(), true);
});

test('two guards are independent (one per form)', () => {
  const a = createSubmitGuard();
  const b = createSubmitGuard();

  assert.equal(a.tryEnter(), true);
  assert.equal(b.tryEnter(), true);
});
