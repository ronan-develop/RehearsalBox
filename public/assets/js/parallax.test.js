import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initParallax } from './parallax.js';

function fakeBgElement() {
  return { style: {} };
}

function fakeDocumentWithBg(bg) {
  return {
    querySelector: (selector) => (selector === '[data-parallax="bg"]' ? bg : null),
  };
}

function fakeWindow({ reducedMotion = false } = {}) {
  return {
    matchMedia: () => ({ matches: reducedMotion }),
    scrollY: 0,
    addEventListener: () => {},
    requestAnimationFrame: (cb) => cb(),
  };
}

test('initParallax does nothing when the background element is absent from the page', () => {
  const doc = { querySelector: () => null };

  assert.doesNotThrow(() => initParallax(doc, fakeWindow()));
});

test('initParallax does nothing when prefers-reduced-motion is set', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);
  const win = fakeWindow({ reducedMotion: true });

  initParallax(doc, win);

  assert.equal(bg.style.transform, undefined);
});

test('initParallax sets an initial transform based on the current scroll position', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);
  const win = fakeWindow();
  win.scrollY = 100;

  initParallax(doc, win);

  assert.equal(bg.style.transform, 'translateY(35px)');
});

test('initParallax updates the transform on scroll', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);
  let scrollCallback;
  const win = fakeWindow();
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  win.scrollY = 200;
  scrollCallback();

  assert.equal(bg.style.transform, 'translateY(70px)');
});
