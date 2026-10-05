import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fetchList, fetchUpdates, fetchListFragment, sendMessage, renameConversation, sendTyping, startConversation } from './api.js';

function mockFetch(payload = {}) {
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : null, csrf: options.headers?.['X-CSRF-Token'], signal: options.signal });
    return { ok: true, json: async () => payload };
  };
  globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };

  return calls;
}

test('fetchList reads the unread totals used by the dashboard badge', async () => {
  const calls = mockFetch({ conversations: [], unread: { total: 2, archived: 0 } });

  const result = await fetchList('active');

  assert.deepEqual([calls[0].method, calls[0].url], ['GET', '/api/conversations?box=active']);
  assert.equal(result.unread.total, 2);
});

test('fetchUpdates asks for the messages after the last one displayed', async () => {
  const calls = mockFetch({ html: '', lastId: 5 });

  await fetchUpdates('12', 5);

  assert.deepEqual([calls[0].method, calls[0].url], ['GET', '/api/conversations/12/updates?after=5']);
});

test('fetchListFragment asks for the server-rendered list, optionally with the open conversation', async () => {
  const calls = mockFetch({ html: '', empty: true, archivedUnread: 0 });

  await fetchListFragment('archived');
  await fetchListFragment('active', '12');

  assert.deepEqual(calls.map((call) => call.url), ['/api/conversation-list?box=archived', '/api/conversation-list?box=active&active=12']);
});

test('reads accept an AbortSignal so a stale request can be cancelled', async () => {
  const calls = mockFetch({});
  const controller = new AbortController();

  await fetchUpdates('12', 0, { signal: controller.signal });
  await fetchListFragment('active', null, { signal: controller.signal });

  assert.ok(calls.every((call) => call.signal === controller.signal));
});

test('writes use POST/PATCH with the CSRF token and the expected bodies', async () => {
  const calls = mockFetch({});

  await sendMessage('12', 'Salut', 30);
  await renameConversation('12', 'Concert');
  await renameConversation('12', null);
  await sendTyping('12');

  assert.deepEqual(calls.map((c) => [c.method, c.url]), [
    ['POST', '/api/conversations/12/messages'],
    ['PATCH', '/api/conversations/12'],
    ['PATCH', '/api/conversations/12'],
    ['POST', '/api/conversations/12/typing'],
  ]);
  assert.deepEqual(calls[0].body, { message: 'Salut', after: 30 });
  assert.deepEqual(calls[1].body, { title: 'Concert' });
  assert.deepEqual(calls[2].body, { title: null });
  assert.ok(calls.every((c) => c.csrf === 'csrf-token'));
});

test('startConversation creates the thread from the first message', async () => {
  const calls = mockFetch({ id: 42 });

  const result = await startConversation({ groupId: '3', targetGroupId: '7', message: 'Salut' });

  assert.deepEqual([calls[0].method, calls[0].url], ['POST', '/api/conversations']);
  assert.deepEqual(calls[0].body, { groupId: '3', targetGroupId: '7', message: 'Salut' });
  assert.equal(calls[0].csrf, 'csrf-token');
  assert.deepEqual(result, { id: 42 });
});
