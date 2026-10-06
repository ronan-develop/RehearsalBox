import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initMiniLogo } from './mini-logo.js';

function fakeClassList() {
  const classes = new Set();
  return { toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)), contains: (c) => classes.has(c) };
}

function makeDom() {
  const mini = { classList: fakeClassList() };
  const header = {};
  let callback = null;
  const root = {
    querySelector: (selector) => (selector === '[data-mini-logo]' ? mini : selector === '.rb-dashboard-header' ? header : null),
  };
  const win = {
    IntersectionObserver: class {
      constructor(cb) { callback = cb; }
      observe(target) { this.target = target; }
    },
  };
  return { mini, header, root, win, scroll: (isIntersecting) => callback([{ isIntersecting }]) };
}

test('the mini logo appears once the header has left the screen and hides again when it comes back', () => {
  const d = makeDom();

  initMiniLogo(d.root, d.win);

  d.scroll(false);
  assert.equal(d.mini.classList.contains('rb-mini-logo--visible'), true);
  d.scroll(true);
  assert.equal(d.mini.classList.contains('rb-mini-logo--visible'), false);
});

test('without IntersectionObserver the mini logo simply stays hidden', () => {
  const d = makeDom();

  assert.doesNotThrow(() => initMiniLogo(d.root, {}));
  assert.equal(d.mini.classList.contains('rb-mini-logo--visible'), false);
});

test('initMiniLogo does nothing on a page without the mini logo or the header', () => {
  assert.doesNotThrow(() => initMiniLogo({ querySelector: () => null }, {}));
});
