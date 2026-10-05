import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fetchList, fetchUpdates, fetchListFragment, sendMessage, renameConversation, sendTyping, startConversation, trashConversation, restoreConversation, purgeConversation, dismissAlert, searchMembers, removeGuest, editMessage } from './api.js';

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

test('the trash calls use the right verbs and routes', async () => {
  const calls = mockFetch({ status: 'ok' });

  await trashConversation('12');
  await restoreConversation('12');
  await purgeConversation('12');
  await dismissAlert('7');

  assert.deepEqual(calls.map((call) => [call.method, call.url]), [
    ['DELETE', '/api/conversations/12'],
    ['POST', '/api/conversations/12/restore'],
    ['DELETE', '/api/conversations/12/permanent'],
    ['POST', '/api/conversation-alerts/7/dismiss'],
  ]);
  assert.ok(calls.every((call) => call.csrf === 'csrf-token'));
});

test('mentions are sent only when someone is tagged', async () => {
  const calls = mockFetch({ id: 1 });

  await sendMessage('12', 'Salut @Denis', 30, [7]);
  await sendMessage('12', 'Salut', 30, []);
  await startConversation({ groupId: '3', targetGroupId: '7', message: 'Avec @Denis', mentions: [9] });

  assert.deepEqual(calls[0].body, { message: 'Salut @Denis', after: 30, mentions: [7] });
  assert.deepEqual(calls[1].body, { message: 'Salut', after: 30 });
  assert.deepEqual(calls[2].body, { groupId: '3', targetGroupId: '7', message: 'Avec @Denis', mentions: [9] });
});

test('searchMembers asks for the people matching the query in the right context', async () => {
  const calls = mockFetch({ members: [] });

  await searchMembers({ query: 'de n', conversation: '12' });
  await searchMembers({ query: 'bo', groupId: '3', targetGroupId: '7' });

  assert.deepEqual(calls.map((c) => c.url), ['/api/members?q=de+n&conversation=12', '/api/members?q=bo&groupId=3&targetGroupId=7']);
});

test('removeGuest deletes the guest of a conversation', async () => {
  const calls = mockFetch({ status: 'ok' });

  await removeGuest('12', '7');

  assert.deepEqual([calls[0].method, calls[0].url], ['DELETE', '/api/conversations/12/guests/7']);
});

test('fetchUpdates asks for the corrections made since the cursor when it has one', async () => {
  const calls = mockFetch({ html: '', edited: [], editedAt: 0 });

  await fetchUpdates('12', 5, { editedAfter: 1700000000 });
  await fetchUpdates('12', 5, { editedAfter: 0 });
  await fetchUpdates('12', 5);

  assert.deepEqual(calls.map((c) => c.url), [
    '/api/conversations/12/updates?after=5&editedAfter=1700000000',
    '/api/conversations/12/updates?after=5&editedAfter=0',
    '/api/conversations/12/updates?after=5',
  ]);
});

test('editMessage patches the message with its new text and only sends mentions when someone is tagged', async () => {
  const calls = mockFetch({ status: 'ok', edited: [] });

  await editMessage('12', '34', 'Texte corrigé');
  await editMessage('12', '34', 'Avec @Denis', [9]);

  assert.deepEqual(calls.map((c) => [c.method, c.url]), [
    ['PATCH', '/api/conversations/12/messages/34'],
    ['PATCH', '/api/conversations/12/messages/34'],
  ]);
  assert.deepEqual(calls[0].body, { message: 'Texte corrigé' });
  assert.deepEqual(calls[1].body, { message: 'Avec @Denis', mentions: [9] });
  assert.equal(calls[0].csrf, 'csrf-token');
});
