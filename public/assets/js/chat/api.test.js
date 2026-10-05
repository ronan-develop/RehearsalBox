import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fetchList, fetchThread, sendMessage, renameConversation, sendTyping } from './chat-api.js';

function mockFetch(payload = {}) {
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : null, csrf: options.headers?.['X-CSRF-Token'] });
    return { ok: true, json: async () => payload };
  };
  globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };

  return calls;
}

test('fetchList asks for the active or archived list', async () => {
  const calls = mockFetch({ conversations: [], unread: { total: 0, archived: 0 } });

  await fetchList('archived');

  assert.deepEqual([calls[0].method, calls[0].url], ['GET', '/api/conversations?box=archived']);
});

test('fetchThread opens the full thread without after and polls with after', async () => {
  const calls = mockFetch({});

  await fetchThread('12');
  await fetchThread('12', 30);
  await fetchThread('12', 0);

  assert.deepEqual(calls.map((c) => c.url), ['/api/conversations/12', '/api/conversations/12?after=30', '/api/conversations/12?after=0']);
});

test('writes use POST/PATCH with the CSRF token and the expected bodies', async () => {
  const calls = mockFetch({});

  await sendMessage('12', 'Salut');
  await renameConversation('12', 'Concert');
  await renameConversation('12', null);
  await sendTyping('12');

  assert.deepEqual(calls.map((c) => [c.method, c.url]), [
    ['POST', '/api/conversations/12/messages'],
    ['PATCH', '/api/conversations/12'],
    ['PATCH', '/api/conversations/12'],
    ['POST', '/api/conversations/12/typing'],
  ]);
  assert.deepEqual(calls[0].body, { message: 'Salut' });
  assert.deepEqual(calls[1].body, { title: 'Concert' });
  assert.deepEqual(calls[2].body, { title: null });
  assert.ok(calls.every((c) => c.csrf === 'csrf-token'));
});
