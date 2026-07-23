import { test } from 'node:test';
import assert from 'node:assert/strict';
import { serializeFormEntries } from './forms.js';

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
