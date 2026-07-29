import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shouldShowScrollHint, initScrollHint, isOverlappingExceptionalSlider } from './scroll-hint.js';

test('shouldShowScrollHint returns true when the page content overflows the viewport height', () => {
  const result = shouldShowScrollHint({ scrollHeight: 1200, innerHeight: 800 });

  assert.equal(result, true);
});

test('shouldShowScrollHint returns false when the page content fits within the viewport height', () => {
  const result = shouldShowScrollHint({ scrollHeight: 600, innerHeight: 800 });

  assert.equal(result, false);
});

function fakeHintElement() {
  const classes = new Set();
  return {
    classList: {
      add: (c) => classes.add(c),
      remove: (c) => classes.delete(c),
      contains: (c) => classes.has(c),
    },
    addEventListener: () => {},
  };
}

function fakeDocumentWithHint(hint, { scrollHeight = 1200 } = {}) {
  return {
    querySelector: (selector) => (selector === '[data-scroll-hint]' ? hint : null),
    documentElement: { scrollHeight },
    addEventListener: () => {},
  };
}

test('initScrollHint shows the hint when content overflows the viewport', () => {
  const hint = fakeHintElement();
  const doc = fakeDocumentWithHint(hint, { scrollHeight: 1200 });

  initScrollHint(doc, { innerHeight: 800, addEventListener: () => {}, scrollY: 0 });

  assert.equal(hint.classList.contains('rb-scroll-hint--visible'), true);
});

test('initScrollHint does not show the hint when content fits the viewport', () => {
  const hint = fakeHintElement();
  const doc = fakeDocumentWithHint(hint, { scrollHeight: 600 });

  initScrollHint(doc, { innerHeight: 800, addEventListener: () => {}, scrollY: 0 });

  assert.equal(hint.classList.contains('rb-scroll-hint--visible'), false);
});

test('initScrollHint hides the hint once the user has scrolled', () => {
  const hint = fakeHintElement();
  let scrollCallback;
  const window_ = {
    innerHeight: 800,
    scrollY: 0,
    addEventListener: (event, cb) => {
      if (event === 'scroll') scrollCallback = cb;
    },
  };
  const doc = fakeDocumentWithHint(hint, { scrollHeight: 1200 });

  initScrollHint(doc, window_);
  assert.equal(hint.classList.contains('rb-scroll-hint--visible'), true);

  window_.scrollY = 50;
  scrollCallback();

  assert.equal(hint.classList.contains('rb-scroll-hint--visible'), false);
});

test('initScrollHint does nothing when the hint element is absent from the page', () => {
  const doc = {
    querySelector: () => null,
    documentElement: { scrollHeight: 1200 },
    addEventListener: () => {},
  };

  assert.doesNotThrow(() => initScrollHint(doc, { innerHeight: 800, addEventListener: () => {}, scrollY: 0 }));
});

test('initScrollHint scrolls to the bottom of the page when the hint is clicked', () => {
  let clickHandler;
  const hint = {
    ...fakeHintElement(),
    addEventListener: (event, cb) => {
      if (event === 'click') clickHandler = cb;
    },
  };
  const doc = fakeDocumentWithHint(hint, { scrollHeight: 1200 });
  let scrolledTo = null;
  const win = {
    innerHeight: 800,
    scrollY: 0,
    addEventListener: () => {},
    scrollTo: (options) => { scrolledTo = options; },
  };

  initScrollHint(doc, win);
  clickHandler();

  assert.deepEqual(scrolledTo, { top: 1200, behavior: 'smooth' });
});

test('isOverlappingExceptionalSlider returns true when the exceptional slider rect intersects the hint rect', () => {
  const hintRect = { top: 700, bottom: 736, left: 300, right: 336 };
  const sliderRect = { top: 650, bottom: 780, left: 0, right: 400 };

  assert.equal(isOverlappingExceptionalSlider(hintRect, sliderRect), true);
});

test('isOverlappingExceptionalSlider returns false when the exceptional slider is above the hint', () => {
  const hintRect = { top: 700, bottom: 736, left: 300, right: 336 };
  const sliderRect = { top: 200, bottom: 400, left: 0, right: 400 };

  assert.equal(isOverlappingExceptionalSlider(hintRect, sliderRect), false);
});

test('initScrollHint hides the hint when it overlaps the visible exceptional slider', () => {
  const hint = {
    ...fakeHintElement(),
    addEventListener: () => {},
    getBoundingClientRect: () => ({ top: 700, bottom: 736, left: 300, right: 336 }),
  };
  const slider = {
    getBoundingClientRect: () => ({ top: 650, bottom: 780, left: 0, right: 400 }),
  };
  const doc = {
    querySelector: (selector) => {
      if (selector === '[data-scroll-hint]') return hint;
      if (selector === '[data-planning-slider-exceptional]') return slider;
      return null;
    },
    documentElement: { scrollHeight: 1200 },
    addEventListener: () => {},
  };

  initScrollHint(doc, { innerHeight: 800, addEventListener: () => {}, scrollY: 0 });

  assert.equal(hint.classList.contains('rb-scroll-hint--visible'), false);
});
