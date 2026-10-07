import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextMuted, labelFor } from './mute.js';

test('pressing an active bell mutes the conversation, pressing a muted bell restores it', () => {
  assert.equal(nextMuted('false'), true);
  assert.equal(nextMuted('true'), false);
});

test('an unknown or missing state is treated as active, so the safe action is to mute', () => {
  assert.equal(nextMuted(undefined), true);
  assert.equal(nextMuted(null), true);
  assert.equal(nextMuted('peut-être'), true);
});

test('the accessible name comes from the labels rendered by the server, never rebuilt from user text', () => {
  const labels = { labelMute: 'Mettre en sourdine : Fil', labelUnmute: 'Réactiver les notifications : Fil' };
  assert.equal(labelFor(labels, false), 'Mettre en sourdine : Fil');
  assert.equal(labelFor(labels, true), 'Réactiver les notifications : Fil');
});

test('without labels the name is empty rather than invented', () => {
  assert.equal(labelFor({}, true), '');
  assert.equal(labelFor({}, false), '');
});
