import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  groupByDay, formatTime, formatListDate, typingText, seenText, safeColor, previewText,
  nextPollDelay, shouldSendTyping, parseRoute, routeFor, mergeMessages, lastMessageId, TYPING_MIN_INTERVAL_MS,
} from './chat-model.js';

const NOW = new Date(2026, 9, 4, 18, 0, 0);
const iso = (y, m, d, h = 9, min = 5) => new Date(y, m - 1, d, h, min, 0).toISOString();
const msg = (id, extra = {}) => ({ id, authorName: 'Alice', body: `m${id}`, createdAt: iso(2026, 10, 4), mine: false, system: false, ...extra });

test('groupByDay groups consecutive messages by calendar day with Aujourd\'hui / Hier labels', () => {
  const groups = groupByDay([
    msg(1, { createdAt: iso(2026, 9, 30) }),
    msg(2, { createdAt: iso(2026, 10, 3) }),
    msg(3, { createdAt: iso(2026, 10, 3, 20) }),
    msg(4, { createdAt: iso(2026, 10, 4) }),
  ], NOW);

  assert.deepEqual(groups.map((g) => g.label), ['30/09/2026', 'Hier', 'Aujourd\'hui']);
  assert.deepEqual(groups.map((g) => g.messages.map((m) => m.id)), [[1], [2, 3], [4]]);
});

test('groupByDay of nothing is empty and tolerates an invalid date', () => {
  assert.deepEqual(groupByDay([], NOW), []);
  assert.equal(groupByDay([msg(1, { createdAt: 'nope' })], NOW)[0].label, '');
});

test('formatTime gives HH:MM and an empty string for an invalid date', () => {
  assert.equal(formatTime(iso(2026, 10, 4, 9, 5)), '09:05');
  assert.equal(formatTime('nope'), '');
});

test('formatListDate shows the time today, Hier yesterday, then day/month', () => {
  assert.equal(formatListDate(iso(2026, 10, 4, 9, 5), NOW), '09:05');
  assert.equal(formatListDate(iso(2026, 10, 3, 9, 5), NOW), 'Hier');
  assert.equal(formatListDate(iso(2026, 9, 30, 9, 5), NOW), '30/09');
});

test('typingText names who is writing', () => {
  assert.equal(typingText([]), '');
  assert.equal(typingText(['Bob']), 'Bob écrit…');
  assert.equal(typingText(['Bob', 'Zoé']), 'Bob et Zoé écrivent…');
  assert.equal(typingText(['Bob', 'Zoé', 'Léa']), 'Bob, Zoé et 1 autre écrivent…');
  assert.equal(typingText(['A', 'B', 'C', 'D']), 'A, B et 2 autres écrivent…');
});

test('seenText: Envoyé when nobody read yet, names when few, a count when many, nothing if alone', () => {
  assert.equal(seenText(null), '');
  assert.equal(seenText({ messageId: 1, names: [], total: 0 }), '');
  assert.equal(seenText({ messageId: 1, names: [], total: 3 }), 'Envoyé');
  assert.equal(seenText({ messageId: 1, names: ['Bob'], total: 3 }), 'Vu par Bob');
  assert.equal(seenText({ messageId: 1, names: ['Bob', 'Zoé'], total: 3 }), 'Vu par Bob et Zoé');
  assert.equal(seenText({ messageId: 1, names: ['Bob', 'Zoé', 'Léa'], total: 8 }), 'Vu par 3 sur 8');
});

test('safeColor only accepts #rrggbb so a stored colour can never inject CSS', () => {
  assert.equal(safeColor('#aa00FF'), '#aa00FF');
  assert.equal(safeColor('red'), null);
  assert.equal(safeColor('#fff'), null);
  assert.equal(safeColor('#aa0000; background:url(x)'), null);
  assert.equal(safeColor(null), null);
});

test('previewText: Vous for mine, the author otherwise, and the action for a system line', () => {
  assert.equal(previewText(msg(1, { mine: true, body: 'Salut' })), 'Vous : Salut');
  assert.equal(previewText(msg(1, { authorName: 'Bob', body: 'Hello' })), 'Bob : Hello');
  assert.equal(previewText(msg(1, { authorName: 'Bob', body: 'a renommé la conversation « X »', system: true })), 'Bob a renommé la conversation « X »');
  assert.equal(previewText(msg(1, { mine: true, body: 'a retiré le titre de la conversation', system: true })), 'Vous a retiré le titre de la conversation');
});

test('nextPollDelay slows down when nothing happens and caps', () => {
  assert.equal(nextPollDelay(0), 4000);
  assert.equal(nextPollDelay(4), 4000);
  assert.equal(nextPollDelay(5), 8000);
  assert.equal(nextPollDelay(14), 8000);
  assert.equal(nextPollDelay(15), 15000);
  assert.equal(nextPollDelay(1000), 15000);
});

test('shouldSendTyping throttles to one signal every 3 seconds', () => {
  assert.equal(TYPING_MIN_INTERVAL_MS, 3000);
  assert.equal(shouldSendTyping(null, 10_000), true);
  assert.equal(shouldSendTyping(10_000, 11_000), false);
  assert.equal(shouldSendTyping(10_000, 13_000), true);
});

test('parseRoute and routeFor map /messages/{id}', () => {
  assert.deepEqual(parseRoute('/messages/12'), { id: '12' });
  assert.deepEqual(parseRoute('/messages'), { id: null });
  assert.deepEqual(parseRoute('/messages/'), { id: null });
  assert.deepEqual(parseRoute('/messages/abc'), { id: null });
  assert.equal(routeFor('12'), '/messages/12');
  assert.equal(routeFor(null), '/messages');
});

test('mergeMessages dedupes by id and keeps the order', () => {
  const merged = mergeMessages([msg(1), msg(2)], [msg(2), msg(3)]);

  assert.deepEqual(merged.map((m) => m.id), [1, 2, 3]);
  assert.deepEqual(mergeMessages([], []), []);
  assert.equal(lastMessageId(merged), 3);
  assert.equal(lastMessageId([]), 0);
});
