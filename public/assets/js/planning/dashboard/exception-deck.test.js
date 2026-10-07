import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDeckSwipeController, computeCardState, renumberDeck, initExceptionTabs } from './exception-deck.js';

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

test('computeCardState() caps the visual stack depth so a long history (e.g. 12 cards) never overflows the deck', () => {
  const shallow = computeCardState(2);
  const deep = computeCardState(11);

  assert.equal(shallow.visualIndex, 2);
  assert.equal(deep.visualIndex, 2);
});

test('computeCardState() keeps the visual index equal to relativeIndex within the visible depth', () => {
  assert.equal(computeCardState(0).visualIndex, 0);
  assert.equal(computeCardState(1).visualIndex, 1);
});

test('computeCardState() clamps the visual index to 0 for cards already swiped away', () => {
  const state = computeCardState(-3);

  assert.equal(state.visualIndex, 0);
});

function fakeCard() {
  const properties = {};
  const classes = new Set();
  return {
    style: { setProperty: (name, value) => { properties[name] = value; } },
    classList: { toggle: (name, force) => { force ? classes.add(name) : classes.delete(name); } },
    properties,
    classes,
  };
}

function fakeDeck(cards, emptyState = null) {
  return {
    querySelectorAll: () => cards,
    querySelector: (selector) => (selector === '.rb-exception-empty' ? emptyState : null),
  };
}

test('renumberDeck sets --deck-index sequentially on the remaining cards', () => {
  const cards = [fakeCard(), fakeCard(), fakeCard()];
  const deck = fakeDeck(cards);

  renumberDeck(deck);

  assert.equal(cards[0].properties['--deck-index'], '0');
  assert.equal(cards[1].properties['--deck-index'], '1');
  assert.equal(cards[2].properties['--deck-index'], '2');
});

test('renumberDeck marks only the top card as active (in-flow, dictates the deck height)', () => {
  const cards = [fakeCard(), fakeCard(), fakeCard()];
  const deck = fakeDeck(cards);

  renumberDeck(deck);

  assert.equal(cards[0].classes.has('rb-exception-card--active'), true);
  assert.equal(cards[1].classes.has('rb-exception-card--active'), false);
  assert.equal(cards[2].classes.has('rb-exception-card--active'), false);
});

test('renumberDeck reveals the empty state when no card remains', () => {
  let revealed = false;
  const emptyState = { removeAttribute: (attr) => { if (attr === 'hidden') revealed = true; } };
  const deck = fakeDeck([], emptyState);

  renumberDeck(deck);

  assert.equal(revealed, true);
});

test('renumberDeck does not touch the empty state while cards remain', () => {
  let revealed = false;
  const emptyState = { removeAttribute: () => { revealed = true; } };
  const deck = fakeDeck([fakeCard()], emptyState);

  renumberDeck(deck);

  assert.equal(revealed, false);
});

function fakeTab(target) {
  const listeners = {};
  return {
    dataset: { tabTarget: target },
    ariaSelected: 'false',
    setAttribute(name, value) { if (name === 'aria-selected') this.ariaSelected = value; },
    addEventListener(event, cb) { listeners[event] = cb; },
    click() { listeners.click?.(); },
  };
}

function fakeTabDeck(target) {
  return { dataset: { deck: target }, hidden: target !== 'received' };
}

test('initExceptionTabs does nothing when there are no tabs or decks', () => {
  const root = { querySelectorAll: () => [] };

  assert.doesNotThrow(() => initExceptionTabs(root));
});

test('initExceptionTabs switches the visible deck and aria-selected state on tab click', () => {
  const receivedTab = fakeTab('received');
  const sentTab = fakeTab('sent');
  const receivedDeck = fakeTabDeck('received');
  const sentDeck = fakeTabDeck('sent');

  const root = {
    querySelectorAll: (selector) => {
      if (selector === '.rb-exceptions-tab') return [receivedTab, sentTab];
      if (selector === '[data-exception-deck]') return [receivedDeck, sentDeck];
      return [];
    },
  };

  initExceptionTabs(root);
  sentTab.click();

  assert.equal(receivedTab.ariaSelected, 'false');
  assert.equal(sentTab.ariaSelected, 'true');
  assert.equal(receivedDeck.hidden, true);
  assert.equal(sentDeck.hidden, false);
});
