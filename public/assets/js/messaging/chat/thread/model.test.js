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

test('isNearBottom works with the inverted scroll origin: 0 is the bottom and the sign does not matter', async () => {
  const { isNearBottom } = await import('./model.js');

  assert.equal(isNearBottom(0), true, 'ancré en bas');
  assert.equal(isNearBottom(-40), true, 'à moins de 80 px du bas (origine inversée, négatif vers le haut)');
  assert.equal(isNearBottom(40), true, 'même convention positive sur les navigateurs qui l\'inversent');
  assert.equal(isNearBottom(-80), false, 'remonté dans l\'historique');
  assert.equal(isNearBottom(-1200), false);
});
