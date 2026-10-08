import { test } from 'node:test';
import assert from 'node:assert/strict';
import { destinationFor, isActivationKey } from './planning-card.js';

test('a card of a group the user belongs to opens the group space', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'mon-groupe', isMember: true }), '/groups/mon-groupe/space');
});

test('a card of another group opens a new conversation page (no modal)', () => {
  assert.equal(destinationFor({ groupId: '5', slug: 'groupe-tiers', isMember: false }), '/messages/new/5');
});

test('Enter and Space activate a card, any other key does not', () => {
  assert.equal(isActivationKey('Enter'), true);
  assert.equal(isActivationKey(' '), true);
  assert.equal(isActivationKey('Tab'), false);
  assert.equal(isActivationKey('a'), false);
  assert.equal(isActivationKey('Escape'), false);
});
