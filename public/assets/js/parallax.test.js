import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initParallax, computeStartOffset, computeEndOffset, computeScrollProgress } from './parallax.js';

test('computeStartOffset positions the watermark in the first third of the header width', () => {
  const headerRect = { top: 0, height: 132, width: 300 };
  const bgTextRect = { top: 0, height: 92 };

  const offset = computeStartOffset(headerRect, bgTextRect);

  assert.equal(offset.x, 100);
});

test('computeStartOffset centers the watermark vertically within the header height', () => {
  const headerRect = { top: 0, height: 132, width: 300 };
  const bgTextRect = { top: 0, height: 92 };

  const offset = computeStartOffset(headerRect, bgTextRect);

  assert.equal(offset.y, 0 - 0 + 66 - 46);
});

test('computeEndOffset returns {0, 0} when there is no target (e.g. empty deck section)', () => {
  const bgTextRect = { top: 0, left: 0, width: 300 };

  const offset = computeEndOffset(null, bgTextRect);

  assert.deepEqual(offset, { x: 0, y: 0 });
});

test('computeEndOffset centers the watermark horizontally under the target', () => {
  const targetRect = { left: 100, width: 200, bottom: 500 };
  const bgTextRect = { top: 0, left: 0, width: 300 };

  const offset = computeEndOffset(targetRect, bgTextRect);

  // Centre du target (100 + 200/2 = 200) moins la moitié de la largeur du texte (150).
  assert.equal(offset.x, 50);
});

test('computeEndOffset positions the watermark below the target with a margin (past the tabs)', () => {
  const targetRect = { left: 100, width: 200, bottom: 500 };
  const bgTextRect = { top: 20, left: 0, width: 300 };

  const offset = computeEndOffset(targetRect, bgTextRect);

  assert.equal(offset.y, 500 - 20 + 140);
});

test('computeScrollProgress returns 0 at the top of the page', () => {
  assert.equal(computeScrollProgress(0, 800), 0);
});

test('computeScrollProgress returns 1 once past the end marker', () => {
  assert.equal(computeScrollProgress(800, 800), 1);
  assert.equal(computeScrollProgress(1000, 800), 1);
});

test('computeScrollProgress interpolates linearly between 0 and the marker', () => {
  assert.equal(computeScrollProgress(400, 800), 0.5);
});

test('computeScrollProgress returns 1 when no end marker is present (nothing to interpolate)', () => {
  assert.equal(computeScrollProgress(0, null), 1);
});

function fakeRect(overrides = {}) {
  return { top: 0, left: 0, width: 0, height: 0, bottom: 0, ...overrides };
}

function fakeBgElement() {
  const properties = {};
  const classes = new Set();
  return {
    style: { setProperty: (name, value) => { properties[name] = value; } },
    classList: { toggle: (name, force) => { force ? classes.add(name) : classes.delete(name); } },
    getBoundingClientRect: () => fakeRect({ height: 92 }),
    properties,
    classes,
  };
}

function fakeDocumentWithBg(bg, { header = null, scrollEndMarker = null, target = null } = {}) {
  return {
    querySelector: (selector) => {
      if (selector === '[data-parallax="bg"]') return bg;
      if (selector === '.rb-dashboard-header') return header;
      if (selector === '[data-parallax-scroll-end]') return scrollEndMarker;
      if (selector === '[data-parallax-target]') return target;
      return null;
    },
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

  assert.equal(bg.properties['--wm-scroll-y'], undefined);
});

test('initParallax sets the scroll-driven vertical offset based on the current scroll position', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);
  const win = fakeWindow();
  win.scrollY = 100;

  initParallax(doc, win);

  assert.equal(bg.properties['--wm-scroll-y'], '35px');
});

test('initParallax updates the scroll-driven offset on scroll', () => {
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

  assert.equal(bg.properties['--wm-scroll-y'], '70px');
});

test('initParallax sets no start offset when there is no header (--wm-x/--wm-y at 0)', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);

  initParallax(doc, fakeWindow());

  assert.equal(bg.properties['--wm-x'], '0px');
  assert.equal(bg.properties['--wm-y'], '0px');
});

test('initParallax applies the full start offset at scroll 0 when a header and end marker are present', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const scrollEndMarker = { getBoundingClientRect: () => fakeRect({ top: 800 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollEndMarker });
  const win = fakeWindow();
  win.scrollY = 0;

  initParallax(doc, win);

  assert.equal(bg.properties['--wm-x'], '100px');
});

test('initParallax reaches the end offset (centered under the target) once scrolled past the end marker', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const scrollEndMarker = { getBoundingClientRect: () => fakeRect({ top: 800 - 900 }) };
  const target = { getBoundingClientRect: () => fakeRect({ left: 0, width: 200, bottom: 400 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollEndMarker, target });
  const win = fakeWindow();
  win.scrollY = 900;

  initParallax(doc, win);

  assert.equal(bg.properties['--wm-x'], '100px');
});

test('initParallax adds the neon class only once fully scrolled to the end position', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const scrollEndMarker = { getBoundingClientRect: () => fakeRect({ top: 800 - 400 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollEndMarker });
  const win = fakeWindow();

  win.scrollY = 200;
  initParallax(doc, win);
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), false);
});

test('initParallax falls back to {0,0} at the end when there is no target (empty deck section)', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const scrollEndMarker = { getBoundingClientRect: () => fakeRect({ top: 800 - 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollEndMarker });
  const win = fakeWindow();
  win.scrollY = 900;

  initParallax(doc, win);

  assert.equal(bg.properties['--wm-x'], '0px');
});
