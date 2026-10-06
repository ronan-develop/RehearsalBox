import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  TOPBAR_LOGO_HEIGHT,
  computeProgress,
  computeDistance,
  computeStartPosition,
  computeEndPosition,
  computePose,
  initLogoMigration,
} from './logo-migration.js';

const rect = ({ top = 0, left = 0, width = 0, height = 0 } = {}) => ({ top, left, width, height, bottom: top + height });

test('computeProgress goes from 0 at the top of the page to 1 once the migration distance is scrolled, never beyond', () => {
  assert.equal(computeProgress(0, 200), 0);
  assert.equal(computeProgress(100, 200), 0.5);
  assert.equal(computeProgress(200, 200), 1);
  assert.equal(computeProgress(900, 200), 1);
  assert.equal(computeProgress(-30, 200), 0, 'rebond de scroll (iOS)');
});

test('computeProgress stays at 0 on a page that cannot scroll', () => {
  assert.equal(computeProgress(50, 0), 0);
});

test('computeDistance: the logo has reached the top bar when the header has left the screen, within what the page can scroll', () => {
  assert.equal(computeDistance(208, 56, 5000), 152);
  assert.equal(computeDistance(208, 56, 100), 100, 'jamais plus que le scroll réellement possible');
  assert.equal(computeDistance(40, 56, 5000), 1, 'distance minimale : pas de division par zéro');
});

test('on desktop the logo starts at the first third of the header, vertically centered in it', () => {
  const header = rect({ top: 32, left: 40, width: 900, height: 176 });

  const start = computeStartPosition(header, { width: 470, height: 150 }, false);

  assert.equal(start.left, 40 + 900 / 3);
  assert.equal(start.top, 32 + 176 / 2 - 150 / 2);
});

test('on a phone the logo starts at the left and middle of the header, where there is room (the group name sits bottom right)', () => {
  const header = rect({ top: 32, left: 16, width: 358, height: 132 });
  const size = { width: 150, height: 72 };

  const start = computeStartPosition(header, size, true);

  const center = { x: start.left + size.width / 2, y: start.top + size.height / 2 };
  assert.ok(Math.abs(center.x - (16 + 358 * 0.28)) < 1, 'centre à ~28 % de la largeur de l\'en-tête');
  assert.ok(Math.abs(center.y - (32 + 132 * 0.45)) < 1, 'centre à ~45 % de sa hauteur');
  assert.ok(start.left >= 16, 'ne déborde pas à gauche de l\'en-tête');
  assert.ok(start.left + size.width < 16 + 358 * 0.75, 'laisse la droite de l\'en-tête au nom du groupe');
});

test('the logo ends at the left of the top bar, vertically centered at its small size', () => {
  const bar = rect({ top: 0, left: 0, width: 390, height: 56 });

  const end = computeEndPosition(bar, 72);

  assert.equal(end.scale, TOPBAR_LOGO_HEIGHT / 72);
  assert.equal(end.left, 16);
  assert.equal(end.top, (56 - TOPBAR_LOGO_HEIGHT) / 2);
});

test('computePose follows the page at rest, then converges on the top bar while shrinking and straightening up its tilt', () => {
  const base = { start: { left: 300, top: 100 }, end: { left: 16, top: 11, scale: 0.25 }, startRotation: 0, endRotation: -3 };

  const rest = computePose({ ...base, progress: 0, scrollY: 0 });
  assert.deepEqual(rest, { x: 300, y: 100, scale: 1, rotate: 0 });

  const scrolledNoMigration = computePose({ ...base, progress: 0, scrollY: 40 });
  assert.equal(scrolledNoMigration.y, 60, 'au repos le logo défile avec la page');

  const done = computePose({ ...base, progress: 1, scrollY: 152 });
  assert.deepEqual(done, { x: 16, y: 11, scale: 0.25, rotate: -3 });

  const half = computePose({ ...base, progress: 0.5, scrollY: 76 });
  assert.equal(half.scale, 0.625);
  assert.equal(half.rotate, -1.5);
});

function fakeLogo() {
  const props = {};
  const classes = new Set();
  return {
    props,
    classes,
    offsetWidth: 470,
    offsetHeight: 150,
    style: { setProperty: (n, v) => { props[n] = v; } },
    classList: { toggle: (c, f) => (f ? classes.add(c) : classes.delete(c)), add: (c) => classes.add(c) },
  };
}

function makeDom({ width = 1280, reduced = false, maxScroll = 3000 } = {}) {
  const logo = fakeLogo();
  const topbar = { style: { setProperty: (n, v) => { topbar.props[n] = v; } }, props: {}, getBoundingClientRect: () => rect({ top: 0, left: 0, width, height: 56 }) };
  const header = { getBoundingClientRect: () => rect({ top: 32, left: 40, width: width - 80, height: 176 }) };
  const listeners = {};
  const win = {
    innerWidth: width,
    innerHeight: 800,
    scrollY: 0,
    matchMedia: () => ({ matches: reduced }),
    addEventListener: (event, cb) => { listeners[event] = cb; },
    requestAnimationFrame: (cb) => cb(),
  };
  const root = {
    documentElement: { scrollHeight: 800 + maxScroll },
    querySelector: (s) => (s === '[data-logo]' ? logo : s === '[data-topbar]' ? topbar : s === '.rb-dashboard-header' ? header : null),
  };
  return { logo, topbar, header, root, win, listeners };
}

test('initLogoMigration places the logo in the header and keeps the top bar invisible at the top of the page', () => {
  const d = makeDom();

  initLogoMigration(d.root, d.win);

  assert.equal(d.logo.props['--wm-s'], '1');
  assert.equal(d.logo.classes.has('rb-page-bg-text--placed'), true, 'visible seulement une fois placé');
  assert.equal(d.topbar.props['--topbar-opacity'], '0');
  assert.ok(d.listeners.scroll, 'migration pilotée par le scroll');
});

test('scrolling the migration distance docks the logo in the top bar, lit up, and shows the bar', () => {
  const d = makeDom();
  initLogoMigration(d.root, d.win);

  d.win.scrollY = 3000;
  d.listeners.scroll();

  assert.equal(d.topbar.props['--topbar-opacity'], '1');
  assert.equal(d.logo.props['--wm-glow'], '1');
  assert.equal(d.logo.classes.has('rb-page-bg-text--neon'), true);
  assert.ok(Number(d.logo.props['--wm-s']) < 0.3, 'taille de la barre');
  assert.equal(d.logo.props['--wm-x'], '16px');
});

test('on desktop the glow rises with the migration; on a phone the logo is lit up permanently', () => {
  const desktop = makeDom();
  initLogoMigration(desktop.root, desktop.win);
  assert.equal(desktop.logo.props['--wm-glow'], '0');
  assert.equal(desktop.logo.classes.has('rb-page-bg-text--neon'), false);

  const phone = makeDom({ width: 390 });
  initLogoMigration(phone.root, phone.win);
  assert.equal(phone.logo.props['--wm-glow'], '1');
  assert.equal(phone.logo.classes.has('rb-page-bg-text--neon'), true);
});

test('with prefers-reduced-motion the logo does not migrate: it stays in the header, scrolling with the page', () => {
  const d = makeDom({ reduced: true });

  initLogoMigration(d.root, d.win);

  assert.equal(d.listeners.scroll, undefined);
  assert.equal(d.logo.classes.has('rb-page-bg-text--static'), true);
});

test('initLogoMigration does nothing without a logo, a top bar or a header', () => {
  assert.doesNotThrow(() => initLogoMigration({ querySelector: () => null }, {}));
});
