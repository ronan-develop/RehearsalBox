import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createLongPress, LONG_PRESS_MS, MOVE_TOLERANCE_PX } from './longpress.js';

/** Faux minuteur : on déclenche à la main, sans attendre. */
function fakeTimers() {
  let next = 1;
  const pending = new Map();

  return {
    setTimeout: (fn) => { const id = next++; pending.set(id, fn); return id; },
    clearTimeout: (id) => { pending.delete(id); },
    fire: () => { const [id, fn] = [...pending][0] ?? []; if (fn) { pending.delete(id); fn(); } },
    count: () => pending.size,
  };
}

function press(timers, calls = []) {
  return createLongPress({ onLongPress: (info) => calls.push(info), timers });
}

test('a finger held still for the delay triggers the long press once', () => {
  const timers = fakeTimers();
  const calls = [];
  const lp = press(timers, calls);

  lp.start(100, 200, 'cible');
  timers.fire();

  assert.deepEqual(calls, [{ x: 100, y: 200, target: 'cible' }]);
  assert.equal(lp.consumed(), true, 'le relâchement qui suit ne doit pas déclencher un clic');
});

test('releasing before the delay is a normal tap: nothing happens', () => {
  const timers = fakeTimers();
  const calls = [];
  const lp = press(timers, calls);

  lp.start(100, 200, 'cible');
  lp.end();
  timers.fire();

  assert.deepEqual(calls, []);
  assert.equal(lp.consumed(), false);
  assert.equal(timers.count(), 0, 'le minuteur est annulé');
});

test('moving more than the tolerance (scrolling, swiping) cancels the long press', () => {
  const timers = fakeTimers();
  const calls = [];
  const lp = press(timers, calls);

  lp.start(100, 200, 'cible');
  lp.move(100 + MOVE_TOLERANCE_PX + 1, 200);
  timers.fire();

  assert.deepEqual(calls, []);
});

test('small finger tremor within the tolerance does not cancel it', () => {
  const timers = fakeTimers();
  const calls = [];
  const lp = press(timers, calls);

  lp.start(100, 200, 'cible');
  lp.move(100 + MOVE_TOLERANCE_PX - 1, 200 + 3);
  timers.fire();

  assert.equal(calls.length, 1);
});

test('a second touch or a cancel gesture stops it, and a new press starts clean', () => {
  const timers = fakeTimers();
  const calls = [];
  const lp = press(timers, calls);

  lp.start(1, 1, 'a');
  lp.cancel();
  timers.fire();
  assert.deepEqual(calls, []);

  lp.start(5, 5, 'b');
  timers.fire();
  assert.deepEqual(calls, [{ x: 5, y: 5, target: 'b' }]);
  assert.equal(lp.consumed(), true);

  lp.start(9, 9, 'c');
  assert.equal(lp.consumed(), false, 'un nouvel appui repart de zéro');
});

test('the delay is long enough to avoid accidental triggers', () => {
  assert.ok(LONG_PRESS_MS >= 400 && LONG_PRESS_MS <= 550, 'sous le délai où Safari iOS démarre sa sélection de texte (≈ 500 ms), sans déclencher par accident');
});
