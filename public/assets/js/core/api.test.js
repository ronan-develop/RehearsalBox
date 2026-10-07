import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeError, apiFetch } from './api.js';

test('normalizeError extracts message and fields from a JSON error body', () => {
  const result = normalizeError(422, { error: 'Validation échouée', fields: { email: 'invalide' } });

  assert.equal(result.status, 422);
  assert.equal(result.message, 'Validation échouée');
  assert.deepEqual(result.fields, { email: 'invalide' });
});

test('normalizeError falls back to a generic message when body has no error key', () => {
  const result = normalizeError(500, {});

  assert.equal(result.status, 500);
  assert.equal(result.message, 'Une erreur est survenue.');
  assert.deepEqual(result.fields, {});
});

test('normalizeError handles a non-object body without throwing', () => {
  const result = normalizeError(404, null);

  assert.equal(result.status, 404);
  assert.equal(result.message, 'Une erreur est survenue.');
});

test('apiFetch does not set a JSON Content-Type when the body is FormData', async () => {
  globalThis.document = { querySelector: () => ({ content: 'csrf-token-value' }) };
  let capturedHeaders = null;
  globalThis.fetch = async (url, options) => {
    capturedHeaders = options.headers;
    return { ok: true, json: async () => ({}) };
  };

  const formData = new FormData();

  await apiFetch('/api/groups/1/documents', { method: 'POST', body: formData });

  assert.equal(capturedHeaders['Content-Type'], undefined);
  assert.equal(capturedHeaders['X-CSRF-Token'], 'csrf-token-value');

  delete globalThis.document;
  delete globalThis.fetch;
});
