import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextPollDelay, shouldSendTyping, TYPING_MIN_INTERVAL_MS } from './model.js';

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
