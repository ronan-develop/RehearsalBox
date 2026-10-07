import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classifyGesture, offsetFor, shouldOpen, ACTION_WIDTH } from './swipe.js';

test('a small movement is undecided: neither a swipe nor a scroll yet', () => {
  assert.equal(classifyGesture(4, 3), 'undecided');
  assert.equal(classifyGesture(-9, 2), 'undecided');
});

test('a mostly horizontal movement past the threshold is a swipe', () => {
  assert.equal(classifyGesture(-30, 6), 'horizontal');
  assert.equal(classifyGesture(24, -8), 'horizontal');
});

test('a mostly vertical movement is a scroll and must never be hijacked', () => {
  assert.equal(classifyGesture(-8, 40), 'vertical');
  assert.equal(classifyGesture(12, 14), 'vertical', 'à peu près diagonal : le défilement gagne');
});

test('the row follows the finger to the left but never past the action width nor to the right of its rest position', () => {
  assert.equal(offsetFor(-30, false), -30);
  assert.equal(offsetFor(-500, false), -ACTION_WIDTH);
  assert.equal(offsetFor(40, false), 0, 'pas de glissement vers la droite depuis le repos');
});

test('starting from an open row, dragging right closes it progressively', () => {
  assert.equal(offsetFor(0, true), -ACTION_WIDTH);
  assert.equal(offsetFor(30, true), -ACTION_WIDTH + 30);
  assert.equal(offsetFor(500, true), 0);
  assert.equal(offsetFor(-40, true), -ACTION_WIDTH, 'déjà ouverte : reste ouverte');
});

test('the action opens past half of its width and closes otherwise', () => {
  assert.equal(shouldOpen(-ACTION_WIDTH), true);
  assert.equal(shouldOpen(-ACTION_WIDTH / 2), true);
  assert.equal(shouldOpen(-ACTION_WIDTH / 2 + 1), false);
  assert.equal(shouldOpen(0), false);
});
