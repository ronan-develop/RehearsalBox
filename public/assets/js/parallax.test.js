import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initParallax, computePhoneStartOffset, computeStartOffset, computeMaxScrollY, computeScrollProgress, computeAnchorEndOffset, isLogoBelowSearchBar, computeGlowLevel } from './parallax.js';

test('computeStartOffset puts the watermark in the first third of the header, relative to its centered resting position', () => {
  const headerRect = { top: 0, left: 0, height: 132, width: 300 };
  // Au repos, le calque est centré par le CSS : son bord gauche est à 400.
  const bgTextRect = { top: 0, left: 400, height: 92 };

  const offset = computeStartOffset(headerRect, bgTextRect);

  // Bord gauche visé : 0 + 300/3 = 100 ; décalage à appliquer : 100 - 400.
  assert.equal(offset.x, -300);
});

test('computeStartOffset takes the header left edge into account', () => {
  const headerRect = { top: 0, left: 50, height: 132, width: 300 };
  const bgTextRect = { top: 0, left: 150, height: 92 };

  assert.equal(computeStartOffset(headerRect, bgTextRect).x, 0);
});

test('computeStartOffset centers the watermark vertically within the header height', () => {
  const headerRect = { top: 0, left: 0, height: 132, width: 300 };
  const bgTextRect = { top: 0, left: 0, height: 92 };

  const offset = computeStartOffset(headerRect, bgTextRect);

  assert.equal(offset.y, 0 - 0 + 66 - 46);
});

test('computeMaxScrollY returns scrollHeight minus innerHeight', () => {
  assert.equal(computeMaxScrollY(2000, 800), 1200);
});

test('computeMaxScrollY never returns a negative value (page shorter than viewport)', () => {
  assert.equal(computeMaxScrollY(500, 800), 0);
});

test('computeScrollProgress returns 0 at the top of the page', () => {
  assert.equal(computeScrollProgress(0, 1000), 0);
});

test('computeScrollProgress returns 1 once the ratio threshold of maxScrollY is reached', () => {
  // Seuil interne = 60% de maxScrollY : 1000 * 0.6 = 600.
  assert.equal(computeScrollProgress(600, 1000), 1);
  assert.equal(computeScrollProgress(1000, 1000), 1);
});

test('computeScrollProgress interpolates linearly up to the threshold', () => {
  // 300 / (1000 * 0.6) = 0.5.
  assert.equal(computeScrollProgress(300, 1000), 0.5);
});

test('computeScrollProgress stays at 0 when the page has no scroll available (maxScrollY = 0)', () => {
  // Sans scroll possible, le watermark reste dans le cadre du header (position de départ).
  assert.equal(computeScrollProgress(0, 0), 0);
});

test('computeAnchorEndOffset adds no horizontal offset: the CSS keeps the watermark centered, whatever the screen', () => {
  const bgTextRect = { top: 40, left: 400, width: 100, height: 92 };

  for (const anchorRect of [
    { top: 900, left: 20, width: 300 },
    { top: 900, left: 57, width: 1666 },
    { top: 900, left: 0, width: 0 },
  ]) {
    assert.equal(computeAnchorEndOffset(anchorRect, bgTextRect).x, 0);
  }
});

test('computeAnchorEndOffset returns the delta needed for a fixed element to stop at the anchor current screen position', () => {
  const anchorRect = { top: 900, left: 0, width: 300 };
  const bgTextRect = { top: 40, left: 0, width: 100, height: 92 };

  const end = computeAnchorEndOffset(anchorRect, bgTextRect);

  // 900 - 40 - 92 = 768.
  assert.equal(end.y, 768);
});

/**
 * Simule un DOMRect natif : ses propriétés sont des accesseurs du
 * prototype (non énumérables sur l'instance), donc { ...rect } spreade un
 * objet vide — un piège réel qui a fait passer un bug en prod (--wm-y
 * calculé à NaN) sans qu'aucun test ne le voie, tant que le fake utilisait
 * un plain object spreadable. On reproduit ce comportement ici via
 * Object.create + defineProperties pour que ce genre de régression soit
 * détecté par les tests.
 */
function fakeRect(overrides = {}) {
  const values = { top: 0, left: 0, width: 0, height: 0, bottom: 0, ...overrides };
  const proto = {};
  for (const [key, value] of Object.entries(values)) {
    Object.defineProperty(proto, key, { get: () => value, enumerable: false });
  }
  return Object.create(proto);
}

function fakeBgElement() {
  const properties = {};
  const classes = new Set();
  return {
    style: { setProperty: (name, value) => { properties[name] = value; } },
    classList: {
      toggle: (name, force) => { force ? classes.add(name) : classes.delete(name); },
      add: (name) => { classes.add(name); },
    },
    getBoundingClientRect: () => fakeRect({ height: 92 }),
    properties,
    classes,
  };
}

function fakeDocumentWithBg(bg, { header = null, anchor = null, search = null, scrollHeight = 0 } = {}) {
  return {
    querySelector: (selector) => {
      if (selector === '[data-parallax="bg"]') return bg;
      if (selector === '.rb-dashboard-header') return header;
      if (selector === '[data-parallax-anchor]') return anchor;
      if (selector === '[data-planning-search]') return search;
      return null;
    },
    documentElement: { scrollHeight },
  };
}

function fakeWindow({ reducedMotion = false, innerHeight = 800 } = {}) {
  return {
    matchMedia: (query) => {
      if (query === '(prefers-reduced-motion: reduce)') return { matches: reducedMotion };
      return { matches: false };
    },
    scrollY: 0,
    innerHeight,
    addEventListener: () => {},
    requestAnimationFrame: (cb) => cb(),
  };
}

test('initParallax does nothing when the background element is absent from the page', () => {
  const doc = { querySelector: () => null };

  assert.doesNotThrow(() => initParallax(doc, fakeWindow()));
});

test('initParallax with prefers-reduced-motion still places the watermark inside the header frame, without any parallax', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ top: 32, left: 0, width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow({ reducedMotion: true });
  const listeners = [];
  win.addEventListener = (event) => listeners.push(event);

  initParallax(doc, win);

  const start = computeStartOffset(fakeRect({ top: 32, left: 0, width: 300, height: 132 }), bg.getBoundingClientRect());
  assert.equal(bg.properties['--wm-x'], `${start.x}px`);
  assert.equal(bg.properties['--wm-y'], `${start.y}px`, 'dans le cadre du header au démarrage');
  assert.equal(bg.properties['--wm-scroll-y'], '0px', 'pas de parallax de scroll');
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), false);
  assert.ok(!listeners.includes('scroll'), 'aucun écouteur de scroll : pas de mouvement');
});

test('initParallax with prefers-reduced-motion does nothing without a header to place the watermark in', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);
  const win = fakeWindow({ reducedMotion: true });

  assert.doesNotThrow(() => initParallax(doc, win));
});

test('initParallax applies the full start offset at scroll 0 when the page has scroll room', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });
  win.scrollY = 0;

  initParallax(doc, win);

  assert.equal(bg.properties['--wm-x'], '100px');
});

test('initParallax recomputes the anchor-based end offset from its current screen position on every frame, before stabilization', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  // bgTextRectAtRest.top = 0, height = 92 (fakeBgElement) ; anchorRect.top - 0 - 92 = --wm-y attendu.
  let anchorTop = 900;
  const anchor = { getBoundingClientRect: () => fakeRect({ top: anchorTop }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 4000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  // maxScrollY = 4000 - 800 = 3200 ; seuil = 3200 * 0.6 = 1920 -> progress < 1 ici.
  win.scrollY = 500;
  scrollCallback();
  const firstY = bg.properties['--wm-y'];

  anchorTop = 600;
  win.scrollY = 1000;
  scrollCallback();
  // Toujours recalculé tant que progress < 1 : suit la nouvelle position de l'ancre.
  assert.notEqual(bg.properties['--wm-y'], firstY);
});

test('initParallax stops exactly at the anchor current screen position once the scroll ratio threshold is reached', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  // maxScrollY = 2000 - 800 = 1200 ; seuil = 1200 * 0.6 = 720.
  win.scrollY = 720;
  scrollCallback();

  // Ancre à 900 dans le document (scroll 0) : à l'écran, à scroll 720, elle est à 180.
  // 180 - bgTextRect.top(0) - bgTextRect.height(92) = 88.
  assert.equal(bg.properties['--wm-y'], '88px');
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), true);
});

test('initParallax ends at --wm-x 0 (centered by the CSS) whatever the anchor geometry', () => {
  const bg = fakeBgElement();
  bg.getBoundingClientRect = () => fakeRect({ top: 0, left: 400, width: 100, height: 92 });
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900, left: 57, width: 1666 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  win.scrollY = 720;
  scrollCallback();

  assert.equal(bg.properties['--wm-x'], '0px');
});

test('initParallax freezes the end offset once progress reaches 1, ignoring further anchor movement', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  let anchorTop = 900;
  const anchor = { getBoundingClientRect: () => fakeRect({ top: anchorTop }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  win.scrollY = 720;
  scrollCallback();
  const frozenY = bg.properties['--wm-y'];

  // L'ancre continue de remonter au fil du scroll : ignoré une fois figé.
  anchorTop = 50;
  win.scrollY = 1200;
  scrollCallback();

  assert.equal(bg.properties['--wm-y'], frozenY);
});

test('initParallax keeps recomputing update() correctly across many successive scroll events', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 5000 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 20000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);

  // Régression : un bug d'ordre (requestAnimationFrame appelé avant que
  // `ticking` soit marqué true) bloquait `ticking` à true dès le second
  // scroll avec un requestAnimationFrame synchrone (comme ici), empêchant
  // tout recalcul ultérieur. On vérifie que plusieurs scrolls successifs
  // produisent bien des valeurs différentes.
  const values = [];
  for (let i = 1; i <= 5; i++) {
    win.scrollY = i * 500;
    scrollCallback();
    values.push(bg.properties['--wm-y']);
  }

  assert.equal(new Set(values).size, values.length);
});

test('initParallax sets no start offset when there is no header (falls back to 0,0)', () => {
  const bg = fakeBgElement();
  const doc = fakeDocumentWithBg(bg);

  assert.doesNotThrow(() => initParallax(doc, fakeWindow()));
});

test('initParallax freezes the scroll-driven translateY once fully stabilized (progress = 1)', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  let scrollCallback;
  const win = fakeWindow({ innerHeight: 800 });
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };

  initParallax(doc, win);
  win.scrollY = 1200;
  scrollCallback();

  assert.equal(bg.properties['--wm-scroll-y'], '0px');
});

test('initParallax keeps the watermark in the header, without neon, when the page cannot scroll', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ top: 47, left: 0, width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 600 }) };
  // scrollHeight = innerHeight : aucun scroll possible.
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 800 });
  const win = fakeWindow({ innerHeight: 800 });

  initParallax(doc, win);

  assert.equal(bg.classes.has('rb-page-bg-text--neon'), false);
  // Décalage de départ (dans le header), pas la position finale calée sur l'ancre.
  const start = computeStartOffset(fakeRect({ top: 47, left: 0, width: 300, height: 132 }), bg.getBoundingClientRect());
  assert.equal(bg.properties['--wm-y'], `${start.y}px`);
});

test('initParallax adds the neon class only once the scroll ratio threshold is reached', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });

  win.scrollY = 300;
  initParallax(doc, win);
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), false);
});

test('initParallax adds the neon class once the scroll ratio threshold is reached', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });

  win.scrollY = 720;
  initParallax(doc, win);
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), true);
});

// --- Bascule rouge -> jaune néon une fois sous la barre de recherche (#121) ---

test('isLogoBelowSearchBar is false while the logo still overlaps or sits above the search bar', () => {
  // barre : 300 -> 348. Logo au-dessus, puis à cheval sur la barre.
  assert.equal(isLogoBelowSearchBar({ top: 100, height: 92 }, { top: 300, bottom: 348 }), false);
  assert.equal(isLogoBelowSearchBar({ top: 320, height: 92 }, { top: 300, bottom: 348 }), false);
});

test('isLogoBelowSearchBar is true once the top of the logo is at or below the bottom of the search bar', () => {
  assert.equal(isLogoBelowSearchBar({ top: 348, height: 92 }, { top: 300, bottom: 348 }), true);
  assert.equal(isLogoBelowSearchBar({ top: 500, height: 92 }, { top: 300, bottom: 348 }), true);
});

/**
 * Page réaliste : l'ancre (900) et le bas de la barre de recherche
 * (searchDocBottom) sont des positions du DOCUMENT ; leur position à l'écran
 * suit le scroll (rect.top = positionDocument - scrollY).
 */
function neonScenario({ searchDocBottom }) {
  const bg = fakeBgElement();
  const win = fakeWindow({ innerHeight: 800 });
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 - win.scrollY }) };
  const search = {
    getBoundingClientRect: () => fakeRect({ top: searchDocBottom - 48 - win.scrollY, bottom: searchDocBottom - win.scrollY }),
  };
  const doc = fakeDocumentWithBg(bg, { header, anchor, search, scrollHeight: 2000 });
  let scrollCallback;
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };
  initParallax(doc, win);
  const scrollTo = (y) => {
    win.scrollY = y;
    scrollCallback();
  };

  return { bg, scrollTo };
}

test('initParallax switches to neon once the logo has passed below the search bar, not at the progress threshold', () => {
  // scrollY 360 : progression 0.5 (seuil 720) -> haut du logo à 297 à l'écran.
  // Barre de recherche : bas à 700 - 360 = 340 (logo encore au-dessus) ou 600 - 360 = 240 (logo dessous).
  const above = neonScenario({ searchDocBottom: 700 });
  above.scrollTo(360);
  assert.equal(above.bg.classes.has('rb-page-bg-text--neon'), false, 'logo encore au-dessus ou à cheval sur la barre');

  const below = neonScenario({ searchDocBottom: 600 });
  below.scrollTo(360);
  assert.equal(below.bg.classes.has('rb-page-bg-text--neon'), true, 'logo passé sous la barre, avant même le seuil de progression');
});

test('initParallax switches back to red when scrolling up above the search bar again', () => {
  const { bg, scrollTo } = neonScenario({ searchDocBottom: 600 });

  scrollTo(360);
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), true);

  scrollTo(0);
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), false);
});

test('initParallax keeps the former progress-based switch when the search bar is absent', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const anchor = { getBoundingClientRect: () => fakeRect({ top: 900 }) };
  const doc = fakeDocumentWithBg(bg, { header, anchor, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });

  win.scrollY = 720;
  initParallax(doc, win);

  assert.equal(bg.classes.has('rb-page-bg-text--neon'), true);
});

// --- Plus le logo descend, plus le jaune est brillant (--wm-glow, 0 -> 1) ---

test('computeGlowLevel is 0 at the bottom of the search bar and 1 at the final position', () => {
  assert.equal(computeGlowLevel(100, 100, 808), 0);
  assert.equal(computeGlowLevel(808, 100, 808), 1);
});

test('computeGlowLevel grows linearly with the logo position and is clamped to [0, 1]', () => {
  assert.equal(computeGlowLevel(454, 100, 808), 0.5);
  assert.equal(computeGlowLevel(20, 100, 808), 0);
  assert.equal(computeGlowLevel(1200, 100, 808), 1);
});

test('computeGlowLevel is 1 when the final position is not below the search bar (nothing left to descend)', () => {
  assert.equal(computeGlowLevel(300, 100, 100), 1);
  assert.equal(computeGlowLevel(300, 100, 50), 1);
});

test('initParallax: --wm-glow is 0 outside the neon zone, then grows as the logo descends, up to 1 at the end', () => {
  const { bg, scrollTo } = neonScenario({ searchDocBottom: 100 });
  const glow = () => Number(bg.properties['--wm-glow']);

  scrollTo(0);
  assert.equal(glow(), 0, 'logo dans le header : pas de brillance');

  scrollTo(180);
  const early = glow();
  scrollTo(360);
  const middle = glow();
  scrollTo(720);
  const end = glow();

  assert.ok(early > 0 && early < middle && middle < end, `brillance croissante : ${early} < ${middle} < ${end}`);
  assert.equal(end, 1);
});

test('initParallax: --wm-glow falls back to 0 when scrolling back up to the header', () => {
  const { bg, scrollTo } = neonScenario({ searchDocBottom: 100 });

  scrollTo(720);
  assert.equal(Number(bg.properties['--wm-glow']), 1);

  scrollTo(0);
  assert.equal(Number(bg.properties['--wm-glow']), 0);
});

// --- Défilement fluide (#143) : aucune lecture de layout par image ---

function countingScenario() {
  const reads = { anchor: 0, search: 0, header: 0, scrollHeight: 0 };
  const bg = fakeBgElement();
  const sets = { count: 0 };
  const originalSet = bg.style.setProperty;
  bg.style.setProperty = (name, value) => {
    sets.count += 1;
    originalSet(name, value);
  };
  const header = { getBoundingClientRect: () => { reads.header += 1; return fakeRect({ width: 300, height: 132 }); } };
  const anchor = { getBoundingClientRect: () => { reads.anchor += 1; return fakeRect({ top: 900 }); } };
  const search = { getBoundingClientRect: () => { reads.search += 1; return fakeRect({ top: 300, bottom: 348 }); } };
  const doc = fakeDocumentWithBg(bg, { header, anchor, search, scrollHeight: 2000 });
  Object.defineProperty(doc.documentElement, 'scrollHeight', { get: () => { reads.scrollHeight += 1; return 2000; } });
  const win = fakeWindow({ innerHeight: 800 });
  let scrollCallback;
  win.addEventListener = (event, cb) => {
    if (event === 'scroll') scrollCallback = cb;
  };
  initParallax(doc, win);

  return { reads, sets, bg, scrollTo: (y) => { win.scrollY = y; scrollCallback(); } };
}

test('initParallax does no layout read while scrolling: positions are measured once, then computed from scrollY', () => {
  const { reads, scrollTo } = countingScenario();
  const afterInit = { ...reads };

  for (let y = 10; y <= 700; y += 10) scrollTo(y);

  assert.deepEqual(reads, afterInit, 'aucune lecture de rect ni de scrollHeight pendant le scroll');
});

test('initParallax writes a CSS property only when its value changes', () => {
  const { sets, scrollTo } = countingScenario();

  scrollTo(1000);
  const afterFirst = sets.count;
  scrollTo(1000);
  scrollTo(1000);

  assert.equal(sets.count, afterFirst, 'même position : aucune écriture');
});

test('initParallax measures again when the window is resized', () => {
  const reads = { anchor: 0 };
  const bg = fakeBgElement();
  const anchor = { getBoundingClientRect: () => { reads.anchor += 1; return fakeRect({ top: 900 }); } };
  const doc = fakeDocumentWithBg(bg, { header: { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) }, anchor, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });
  const handlers = {};
  win.addEventListener = (event, cb) => { handlers[event] = cb; };
  initParallax(doc, win);
  const before = reads.anchor;

  handlers.resize();

  assert.ok(reads.anchor > before, 'la mesure est refaite au redimensionnement');
});

// --- Lissage du déplacement (#147) : le logo glisse au lieu de sauter ---

test('initParallax enables the smoothing transition after the first render, not before (no slide-in at load)', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ top: 32, left: 0, width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });
  const frames = [];
  win.requestAnimationFrame = (cb) => frames.push(cb);

  initParallax(doc, win);
  assert.equal(bg.classes.has('rb-page-bg-text--smooth'), false, 'pas de transition pendant le placement initial');

  while (frames.length) frames.shift()();
  assert.equal(bg.classes.has('rb-page-bg-text--smooth'), true, 'lissage actif une fois le logo en place');
});

test('initParallax never enables the smoothing transition with prefers-reduced-motion', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ top: 32, left: 0, width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow({ reducedMotion: true });

  initParallax(doc, win);

  assert.equal(bg.classes.has('rb-page-bg-text--smooth'), false);
});

test('initParallax on a phone keeps the logo in the header, lit up, without any scroll movement (#201)', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ top: 32, left: 0, width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow();
  win.innerWidth = 390;
  const listeners = [];
  win.addEventListener = (event) => listeners.push(event);

  initParallax(doc, win);

  const start = computePhoneStartOffset(fakeRect({ top: 32, left: 0, width: 300, height: 132 }), bg.getBoundingClientRect());
  assert.equal(bg.properties['--wm-x'], `${start.x}px`, 'centré dans l\'en-tête');
  assert.equal(bg.properties['--wm-y'], `${start.y}px`, 'dans le cadre de l\'en-tête');
  assert.equal(bg.properties['--wm-scroll-y'], '0px');
  assert.equal(bg.classes.has('rb-page-bg-text--neon'), true, 'néon permanent, jamais atténué');
  assert.equal(bg.properties['--wm-glow'], '1');
  assert.ok(!listeners.includes('scroll'), 'aucun écouteur de scroll : le logo défile avec la page');
});

test('initParallax keeps the full travelling effect from the desktop breakpoint', () => {
  const bg = fakeBgElement();
  const header = { getBoundingClientRect: () => fakeRect({ width: 300, height: 132 }) };
  const doc = fakeDocumentWithBg(bg, { header, scrollHeight: 2000 });
  const win = fakeWindow({ innerHeight: 800 });
  win.innerWidth = 768;
  const listeners = [];
  win.addEventListener = (event) => listeners.push(event);

  initParallax(doc, win);

  assert.ok(listeners.includes('scroll'));
});

test('computePhoneStartOffset centers the logo horizontally in the header and keeps it in its upper part (the group name sits below)', () => {
  const header = fakeRect({ top: 32, left: 16, width: 358, height: 132 });
  const text = fakeRect({ top: 0, left: 80, width: 230, height: 56 });

  const offset = computePhoneStartOffset(header, text);

  assert.equal(offset.x, 16 + 358 / 2 - (80 + 230 / 2));
  assert.ok(offset.y > 32 - 56 / 2 && offset.y + 56 <= 32 + 132, 'le logo reste dans le cadre vertical de l\'en-tête');
});
