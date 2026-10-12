import { test } from 'node:test';
import assert from 'node:assert/strict';
import { destinationFor, isActivationKey, nextOccurrence } from './planning-card.js';

test('a card of a group the user belongs to opens the group space', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'mon-groupe', isMember: true }), '/groups/mon-groupe/space');
});

test('a card of another group opens a new conversation page (no modal)', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'groupe-tiers', isMember: false }), '/messages/new/5');
});

// 2026-10-12 est un lundi. Lundi = 0 … dimanche = 6 (comme l'enum Weekday côté serveur).
const MONDAY_NOON = new Date(2026, 9, 12, 12, 0);

test('the next occurrence of a weekday is today when it falls today, else the next one', () => {
  assert.equal(nextOccurrence(0, MONDAY_NOON), '2026-10-12');
  assert.equal(nextOccurrence(2, MONDAY_NOON), '2026-10-14');
  assert.equal(nextOccurrence(6, MONDAY_NOON), '2026-10-18');
});

test('the next occurrence crosses a month end', () => {
  assert.equal(nextOccurrence(1, new Date(2026, 9, 31, 9, 0)), '2026-11-03');
});

test('a card of another group opens the booking page prefilled with the slot when the user has a group to book from', () => {
  const card = { groupId: '5', slug: 'groupe-tiers', isMember: false, canBook: true, weekday: '2', startTime: '18:30', endTime: '22:45' };
  assert.equal(destinationFor(card, MONDAY_NOON), '/bookings?date=2026-10-14&start=18%3A30&end=22%3A45');
});

test('a person without any group still opens the conversation page', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'g', isMember: false, canBook: false, weekday: '2', startTime: '18:30', endTime: '22:45' }, MONDAY_NOON), '/messages/new/5');
});

test('a card with an unreadable weekday or time falls back to the conversation page rather than a broken prefill', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'g', isMember: false, canBook: true, weekday: 'x', startTime: '18:30', endTime: '22:45' }, MONDAY_NOON), '/messages/new/5');
  assert.equal(destinationFor({ groupId: '5', slug: 'g', isMember: false, canBook: true, weekday: '2', startTime: '', endTime: '22:45' }, MONDAY_NOON), '/messages/new/5');
});

test('Enter and Space activate a card, any other key does not', () => {
  assert.equal(isActivationKey('Enter'), true);
  assert.equal(isActivationKey(' '), true);
  assert.equal(isActivationKey('Tab'), false);
  assert.equal(isActivationKey('a'), false);
  assert.equal(isActivationKey('Escape'), false);
});
