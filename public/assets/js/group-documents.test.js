import { test } from 'node:test';
import assert from 'node:assert/strict';
import { handleDocumentUpload, handleDocumentDelete } from './group-documents.js';

function fakeForm(endpoint, fileInputValue) {
  const listeners = {};
  return {
    dataset: { endpoint },
    addEventListener: (type, handler) => { listeners[type] = handler; },
    dispatch: (type, event) => listeners[type]?.(event),
    reset: () => {},
    querySelector: () => (fileInputValue !== undefined ? { files: fileInputValue ? [fileInputValue] : [] } : null),
  };
}

test('handleDocumentUpload posts the file as FormData to the form endpoint', async () => {
  const fakeContainer = { appendChild: () => {} };
  globalThis.document = {
    querySelector: () => fakeContainer,
    createElement: () => ({ classList: { add: () => {} }, appendChild: () => {}, remove: () => {} }),
    body: { appendChild: () => {} },
  };
  globalThis.window = { location: { reload: () => {} } };

  let capturedUrl = null;
  let capturedOptions = null;
  const fakeApiFetch = async (url, options) => {
    capturedUrl = url;
    capturedOptions = options;
    return { id: 1, originalName: 'fiche.pdf' };
  };

  const fakeFile = { name: 'fiche.pdf' };
  const form = fakeForm('/api/groups/1/documents', fakeFile);
  let prevented = false;

  await handleDocumentUpload({ target: form, preventDefault: () => { prevented = true; } }, fakeApiFetch);

  assert.equal(prevented, true);
  assert.equal(capturedUrl, '/api/groups/1/documents');
  assert.equal(capturedOptions.method, 'POST');
  assert.ok(capturedOptions.body instanceof FormData);

  delete globalThis.document;
  delete globalThis.window;
});

test('handleDocumentDelete calls the API with DELETE on the document endpoint', async () => {
  let capturedUrl = null;
  let capturedOptions = null;
  const fakeApiFetch = async (url, options) => {
    capturedUrl = url;
    capturedOptions = options;
    return {};
  };

  await handleDocumentDelete('42', fakeApiFetch);

  assert.equal(capturedUrl, '/api/documents/42');
  assert.equal(capturedOptions.method, 'DELETE');
});
