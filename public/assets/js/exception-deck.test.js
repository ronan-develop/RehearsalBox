import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDeckSwipeController, computeCardState } from './exception-deck.js';

test('createDeckSwipeController starts at index 0', () => {
  const controller = createDeckSwipeController({ count: 3 });

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController.next() advances the index by one', () => {
  const controller = createDeckSwipeController({ count: 3 });

  controller.next();

  assert.equal(controller.currentIndex(), 1);
});

test('createDeckSwipeController.next() is bounded at the last card — no loop', () => {
  const controller = createDeckSwipeController({ count: 3 });

  controller.next();
  controller.next();
  controller.next();
  controller.next();

  assert.equal(controller.currentIndex(), 2);
});

test('createDeckSwipeController.previous() is bounded at the first card — no loop', () => {
  const controller = createDeckSwipeController({ count: 3 });

  controller.previous();

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController.previous() moves back after next()', () => {
  const controller = createDeckSwipeController({ count: 3 });

  controller.next();
  controller.next();
  controller.previous();

  assert.equal(controller.currentIndex(), 1);
});

test('createDeckSwipeController with a single card stays at index 0 in both directions', () => {
  const controller = createDeckSwipeController({ count: 1 });

  controller.next();
  controller.previous();

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController with zero cards stays at index 0', () => {
  const controller = createDeckSwipeController({ count: 0 });

  controller.next();

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController.handleDragEnd() advances to next card when dragged past the threshold leftward', () => {
  const controller = createDeckSwipeController({ count: 3, threshold: 80 });

  controller.handleDragStart(200);
  controller.handleDragEnd(200 - 100);

  assert.equal(controller.currentIndex(), 1);
});

test('createDeckSwipeController.handleDragEnd() goes to previous card when dragged past the threshold rightward', () => {
  const controller = createDeckSwipeController({ count: 3, threshold: 80 });
  controller.next();

  controller.handleDragStart(200);
  controller.handleDragEnd(200 + 100);

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController.handleDragEnd() snaps back without changing index when under the threshold', () => {
  const controller = createDeckSwipeController({ count: 3, threshold: 80 });

  controller.handleDragStart(200);
  controller.handleDragEnd(200 - 30);

  assert.equal(controller.currentIndex(), 0);
});

test('createDeckSwipeController.handleDragEnd() does not advance past the last card even past threshold', () => {
  const controller = createDeckSwipeController({ count: 2, threshold: 80 });
  controller.next();

  controller.handleDragStart(200);
  controller.handleDragEnd(200 - 100);

  assert.equal(controller.currentIndex(), 1);
});

test('createDeckSwipeController.dragOffset() reflects live drag distance while dragging', () => {
  const controller = createDeckSwipeController({ count: 3 });

  controller.handleDragStart(200);
  controller.handleDragMove(150);

  assert.equal(controller.dragOffset(), -50);
});

test('createDeckSwipeController.dragOffset() resets to 0 after drag ends', () => {
  const controller = createDeckSwipeController({ count: 3, threshold: 80 });

  controller.handleDragStart(200);
  controller.handleDragMove(150);
  controller.handleDragEnd(150);

  assert.equal(controller.dragOffset(), 0);
});

test('computeCardState() marks the active card (relativeIndex 0) as interactive', () => {
  const state = computeCardState(0);

  assert.equal(state.pointerEvents, 'auto');
  assert.equal(state.hidden, false);
});

test('computeCardState() disables pointer-events on cards behind the active one', () => {
  const state = computeCardState(1);

  assert.equal(state.pointerEvents, 'none');
  assert.equal(state.hidden, false);
});

test('computeCardState() disables pointer-events on cards already swiped away (negative relativeIndex)', () => {
  const state = computeCardState(-1);

  assert.equal(state.pointerEvents, 'none');
  assert.equal(state.hidden, true);
});
