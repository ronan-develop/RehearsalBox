import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BOXES, previewOf, formatMessageDate, archiveTargetFor, fetchBox, fetchThread, postReply, setArchived } from './messages.js';

function mockFetch(payload = {}) {
  const calls = [];
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url, method: options.method ?? 'GET', body: options.body ? JSON.parse(options.body) : null });
    return { ok: true, json: async () => payload };
  };
  globalThis.document = { querySelector: () => ({ content: 'csrf-token' }) };

  return calls;
}

const message = (body, mine, authorName = 'Alice') => ({ id: 1, authorName, body, createdAt: '2026-10-04T12:00:00+00:00', mine });

test('the three boxes are Reçues, Envoyées and Archivées in that order', () => {
  assert.deepEqual(BOXES, ['received', 'sent', 'archived']);
});

test('previewOf shows the last message in Reçues and Archivées', () => {
  const conversation = { lastMessage: message('Dernier', false, 'Bob'), myLastMessage: message('Le mien', true) };

  assert.deepEqual(previewOf('received', conversation), { author: 'Bob', body: 'Dernier' });
  assert.deepEqual(previewOf('archived', conversation), { author: 'Bob', body: 'Dernier' });
});

test('previewOf shows MY last message in Envoyées, labelled "Vous"', () => {
  const conversation = { lastMessage: message('Réponse de Bob', false, 'Bob'), myLastMessage: message('Le mien', true) };

  assert.deepEqual(previewOf('sent', conversation), { author: 'Vous', body: 'Le mien' });
});

test('previewOf falls back to the last message when I never wrote', () => {
  const conversation = { lastMessage: message('Salut', false, 'Bob'), myLastMessage: null };

  assert.deepEqual(previewOf('sent', conversation), { author: 'Bob', body: 'Salut' });
});

test('previewOf says "Vous" for my own last message in Reçues', () => {
  const conversation = { lastMessage: message('Moi', true), myLastMessage: message('Moi', true) };

  assert.equal(previewOf('received', conversation).author, 'Vous');
});

test('formatMessageDate shows the time for today and the day/month otherwise', () => {
  const now = new Date(2026, 9, 4, 18, 0, 0);

  assert.equal(formatMessageDate(new Date(2026, 9, 4, 9, 5, 0).toISOString(), now), '09:05');
  assert.equal(formatMessageDate(new Date(2026, 8, 30, 9, 5, 0).toISOString(), now), '30/09');
  assert.equal(formatMessageDate('pas une date', now), '');
});

test('archiveTargetFor archives from Reçues/Envoyées and restores from Archivées', () => {
  assert.equal(archiveTargetFor('received'), true);
  assert.equal(archiveTargetFor('sent'), true);
  assert.equal(archiveTargetFor('archived'), false);
});

test('fetchBox asks the API for the chosen box', async () => {
  const calls = mockFetch({ conversations: [], unread: 0 });

  const result = await fetchBox('sent');

  assert.equal(calls[0].url, '/api/conversations?box=sent');
  assert.equal(calls[0].method, 'GET');
  assert.deepEqual(result, { conversations: [], unread: 0 });
});

test('fetchThread, postReply and setArchived use the conversation routes with CSRF on writes', async () => {
  const calls = mockFetch({});

  await fetchThread(12);
  await postReply(12, 'Salut');
  await setArchived(12, true);

  assert.deepEqual(calls.map((c) => [c.method, c.url]), [
    ['GET', '/api/conversations/12'],
    ['POST', '/api/conversations/12/messages'],
    ['PATCH', '/api/conversations/12'],
  ]);
  assert.deepEqual(calls[1].body, { message: 'Salut' });
  assert.deepEqual(calls[2].body, { archived: true });
});
