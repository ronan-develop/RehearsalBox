import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CONFIRM_DELETE, CONFIRM_PURGE, initMessagesTrash } from './messages-trash.js';

function setup({ confirmed = true, failWith = null } = {}) {
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push(`${options.method ?? 'GET'} ${url}`);
    if (failWith) {
      return { ok: false, status: 403, json: async () => ({ error: failWith }) };
    }
    return { ok: true, json: async () => ({ status: 'ok' }) };
  };
  globalThis.document = { querySelector: () => ({ content: 'csrf' }) };

  const log = { removed: [], navigated: [], reloaded: 0, errors: [], confirms: [], emptyShown: false };
  const container = { remove: () => log.removed.push('container'), parentElement: null };
  let handler;
  const root = {
    addEventListener: (type, fn) => { handler = fn; },
    querySelectorAll: () => [],
    querySelector: (selector) => (selector === '[data-trash-empty]' ? { set hidden(value) { log.emptyShown = !value; } } : null),
  };
  initMessagesTrash(root, {
    confirm: async (message, options) => { log.confirms.push({ message, options }); return confirmed; },
    navigate: (url) => log.navigated.push(url),
    reload: () => { log.reloaded += 1; },
    notify: (message) => log.errors.push(message),
  });
  const click = (action, id = '12') => handler({
    target: { closest: (selector) => (selector === '[data-trash-action]' ? { dataset: { trashAction: action, id }, closest: () => container } : null) },
  });

  return { calls, log, click };
}

test('deleting asks for confirmation then goes back to the list', async () => {
  const { calls, log, click } = setup();

  await click('delete');

  assert.deepEqual(log.confirms, [{ message: CONFIRM_DELETE.message, options: CONFIRM_DELETE.options }]);
  assert.deepEqual(calls, ['DELETE /api/conversations/12']);
  assert.deepEqual(log.navigated, ['/messages']);
});

test('declining the confirmation does nothing', async () => {
  const { calls, log, click } = setup({ confirmed: false });

  await click('delete');
  await click('purge');

  assert.deepEqual(calls, []);
  assert.deepEqual(log.navigated, []);
});

test('restoring reloads the trash page without confirmation', async () => {
  const { calls, log, click } = setup();

  await click('restore');

  assert.deepEqual(calls, ['POST /api/conversations/12/restore']);
  assert.equal(log.confirms.length, 0);
  assert.equal(log.reloaded, 1);
});

test('purging for good removes the row after confirmation', async () => {
  const { calls, log, click } = setup();

  await click('purge');

  assert.deepEqual(calls, ['DELETE /api/conversations/12/permanent']);
  assert.deepEqual(log.removed, ['container']);
});

test('dismissing an alert removes it without confirmation', async () => {
  const { calls, log, click } = setup();

  await click('dismiss', '7');

  assert.deepEqual(calls, ['POST /api/conversation-alerts/7/dismiss']);
  assert.deepEqual(log.removed, ['container']);
  assert.equal(log.confirms.length, 0);
});

test('a failure is reported and nothing is removed or navigated', async () => {
  const { log, click } = setup({ failWith: 'Accès refusé.' });

  await click('delete');

  assert.deepEqual(log.errors, ['Accès refusé.']);
  assert.deepEqual(log.navigated, []);
});

test('the confirmation warns about the other group and the 30-day trash, with named buttons', () => {
  assert.match(CONFIRM_DELETE.message, /autre groupe/);
  assert.match(CONFIRM_DELETE.message, /30 jours/);
  assert.equal(CONFIRM_DELETE.options.confirmLabel, 'Mettre à la corbeille');
  assert.match(CONFIRM_PURGE.message, /irréversible/);
  assert.equal(CONFIRM_PURGE.options.confirmLabel, 'Supprimer définitivement');
});
