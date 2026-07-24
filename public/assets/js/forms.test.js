import { test } from 'node:test';
import assert from 'node:assert/strict';
import { serializeFormEntries, initAsyncForms } from './forms.js';

test('serializeFormEntries converts FormData-like entries into a plain object', () => {
  const entries = [
    ['email', 'alice@rehearsalbox.test'],
    ['password', 'secret'],
  ];

  const result = serializeFormEntries(entries);

  assert.deepEqual(result, { email: 'alice@rehearsalbox.test', password: 'secret' });
});

test('serializeFormEntries handles an empty entries list', () => {
  assert.deepEqual(serializeFormEntries([]), {});
});

function fakeForm({ endpoint, method = 'POST' }) {
  const form = Object.assign(new EventTarget(), {
    dataset: { async: 'true', endpoint, method },
    querySelectorAll: () => [],
    querySelector: () => null,
  });
  return form;
}

function fakeRoot(forms) {
  return { querySelectorAll: () => forms };
}

test('initAsyncForms ignores a submit fired while one is already in flight', async () => {
  let fetchCallCount = 0;
  let resolveFetch;
  globalThis.fetch = () => {
    fetchCallCount += 1;
    return new Promise((resolve) => {
      resolveFetch = () => resolve({ ok: true, json: async () => ({ id: 1 }) });
    });
  };
  globalThis.document = { querySelector: () => null };
  globalThis.FormData = function FakeFormData() {
    return { entries: () => [] };
  };

  const form = fakeForm({ endpoint: '/api/auth/login' });
  initAsyncForms(fakeRoot([form]));

  const submitEvent = () => Object.assign(new Event('submit'), { preventDefault() {} });

  form.dispatchEvent(submitEvent());
  form.dispatchEvent(submitEvent());

  assert.equal(fetchCallCount, 1);

  resolveFetch();
  await new Promise((resolve) => setTimeout(resolve, 0));

  form.dispatchEvent(submitEvent());
  await new Promise((resolve) => setTimeout(resolve, 0));

  assert.equal(fetchCallCount, 2);
});
