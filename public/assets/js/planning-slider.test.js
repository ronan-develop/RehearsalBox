import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController, initPlanningSlider, shouldAutoScroll, appendLoopCopy, buildExceptionalCardMarkup, refreshExceptionalPlanning } from './planning-slider.js';

function makeFakeTrack(offsetWidth = 1000) {
  const reads = { offsetWidth: 0 };
  const classes = new Set();
  const properties = {};
  return {
    get offsetWidth() {
      reads.offsetWidth += 1;
      return offsetWidth;
    },
    reads,
    classes,
    properties,
    classList: {
      add: (name) => { classes.add(name); },
      toggle: (name, force) => { force ? classes.add(name) : classes.delete(name); },
    },
    style: { setProperty: (name, value) => { properties[name] = value; } },
  };
}

// --- Contrôleur : raisons de pause et durée de l'animation CSS (compositeur, zéro JS par image) ---

test('createAutoScrollController starts running by default', () => {
  const controller = createAutoScrollController(makeFakeTrack());

  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController pause() is idempotent under concurrent mouseenter/manual pause', () => {
  const controller = createAutoScrollController(makeFakeTrack());

  controller.pause();
  controller.pause();

  assert.equal(controller.isRunning(), false);
});

test('createAutoScrollController resumes after resume() following a pause()', () => {
  const controller = createAutoScrollController(makeFakeTrack());

  controller.pause();
  controller.resume();

  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController stays paused until EVERY pause reason is lifted', () => {
  const controller = createAutoScrollController(makeFakeTrack());

  controller.pause('hover');
  controller.pause('touch');
  controller.resume('hover');
  assert.equal(controller.isRunning(), false, 'le toucher retient encore le défilement');

  controller.resume('touch');
  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController notifies only when the running state actually flips', () => {
  const states = [];
  const controller = createAutoScrollController(makeFakeTrack(), { onChange: (running) => states.push(running) });

  controller.pause('hover');
  controller.pause('touch');
  controller.resume('hover');
  controller.resume('touch');

  assert.deepEqual(states, [false, true]);
});

test('createAutoScrollController sets the animation duration to half the track width over the speed (loop on the duplicated content)', () => {
  // Largeur 1000 : on boucle à 500 px ; à 25 px/s, un tour dure 20 s.
  const track = makeFakeTrack(1000);
  const controller = createAutoScrollController(track, { speed: 25 });

  const duration = controller.measure();

  assert.equal(duration, 20);
  assert.equal(track.properties['--rb-planning-duration'], '20s');
});

test('createAutoScrollController does not start an animation on a track with no measurable width', () => {
  const track = makeFakeTrack(0);
  const controller = createAutoScrollController(track);

  assert.equal(controller.measure(), null);
  assert.equal(track.properties['--rb-planning-duration'], undefined);
});

// --- Pilotage : animation CSS, pauses (toucher, survol, hors écran, onglet caché), aucun JS par image ---

function makeDriver({ reducedMotion = false, offsetWidth = 1000, width = 1280 } = {}) {
  const winListeners = {};
  const sliderListeners = {};
  const rootListeners = {};
  let observerCallback = null;
  const track = makeFakeTrack(offsetWidth);
  const slider = { addEventListener: (event, cb) => { sliderListeners[event] = cb; } };
  const root = {
    visibilityState: 'visible',
    addEventListener: (event, cb) => { rootListeners[event] = cb; },
    querySelector: (selector) => (selector === '[data-planning-slider]' ? slider : selector === '[data-planning-track]' ? track : null),
  };
  const win = {
    innerWidth: width,
    matchMedia: () => ({ matches: reducedMotion }),
    requestAnimationFrame: () => { throw new Error('aucune boucle JS par image attendue'); },
    addEventListener: (event, cb) => { winListeners[event] = cb; },
    IntersectionObserver: class {
      constructor(cb) { observerCallback = cb; }
      observe() {}
    },
  };

  return {
    track, win, root, winListeners, sliderListeners, rootListeners,
    intersect: (isIntersecting) => observerCallback([{ isIntersecting }]),
    isPaused: () => track.classes.has('rb-planning-track--paused'),
  };
}

test('initPlanningSlider starts the CSS animation: no JS per frame, no scroll listener', () => {
  const d = makeDriver();

  initPlanningSlider(d.root, d.win);

  assert.ok(d.track.classes.has('rb-planning-track--auto'), 'animation CSS activée');
  assert.equal(d.track.properties['--rb-planning-duration'], '20s');
  assert.equal(d.isPaused(), false);
  assert.equal(d.winListeners.scroll, undefined, 'plus de pause au scroll : le compositeur n\'utilise pas le thread principal');
});

test('initPlanningSlider pauses the animation on touch and resumes on release', () => {
  const d = makeDriver();
  initPlanningSlider(d.root, d.win);

  d.sliderListeners.touchstart();
  assert.equal(d.isPaused(), true);

  d.sliderListeners.touchend();
  assert.equal(d.isPaused(), false);
});

test('initPlanningSlider pauses the animation on hover and resumes on leave', () => {
  const d = makeDriver();
  initPlanningSlider(d.root, d.win);

  d.sliderListeners.mouseenter();
  assert.equal(d.isPaused(), true);

  d.sliderListeners.mouseleave();
  assert.equal(d.isPaused(), false);
});

test('initPlanningSlider pauses when the timeline is out of view and resumes when it comes back', () => {
  const d = makeDriver();
  initPlanningSlider(d.root, d.win);

  d.intersect(false);
  assert.equal(d.isPaused(), true);

  d.intersect(true);
  assert.equal(d.isPaused(), false);
});

test('initPlanningSlider pauses when the tab is hidden and resumes when it is visible again', () => {
  const d = makeDriver();
  initPlanningSlider(d.root, d.win);

  d.root.visibilityState = 'hidden';
  d.rootListeners.visibilitychange();
  assert.equal(d.isPaused(), true);

  d.root.visibilityState = 'visible';
  d.rootListeners.visibilitychange();
  assert.equal(d.isPaused(), false);
});

test('initPlanningSlider measures the track width once, then again only on resize', () => {
  const d = makeDriver();
  initPlanningSlider(d.root, d.win);
  assert.equal(d.track.reads.offsetWidth, 1);

  d.winListeners.resize();

  assert.equal(d.track.reads.offsetWidth, 2);
});

test('initPlanningSlider does not auto-scroll with prefers-reduced-motion (native swipe stays available)', () => {
  const d = makeDriver({ reducedMotion: true });

  initPlanningSlider(d.root, d.win);

  assert.equal(d.track.classes.has('rb-planning-track--auto'), false);
});

test('shouldAutoScroll is only true from the desktop breakpoint: the mobile planning is a list, not a carousel (#201)', () => {
  assert.equal(shouldAutoScroll(767), false);
  assert.equal(shouldAutoScroll(768), true);
});

test('initPlanningSlider never animates the list on a phone-width viewport', () => {
  const d = makeDriver({ width: 390 });

  initPlanningSlider(d.root, d.win);

  assert.equal(d.track.classes.has('rb-planning-track--auto'), false);
});

test('appendLoopCopy adds ONE inert, hidden-from-assistive-tech copy of the cards, created only when the loop runs', () => {
  const created = [];
  const doc = {
    createElement: (tag) => {
      const el = { tag, attrs: {}, children: [], className: '', setAttribute(n, v) { this.attrs[n] = v; }, append(...nodes) { this.children.push(...nodes); } };
      created.push(el);
      return el;
    },
  };
  const cards = [{ cloneNode: () => ({ clone: 'a' }) }, { cloneNode: () => ({ clone: 'b' }) }];
  const appended = [];
  const track = { ownerDocument: doc, children: cards, append: (node) => appended.push(node) };

  appendLoopCopy(track);

  assert.equal(appended.length, 1);
  assert.equal(appended[0].attrs['aria-hidden'], 'true');
  assert.ok('inert' in appended[0].attrs, 'la copie ne reçoit ni focus ni clic');
  assert.equal(appended[0].className, 'rb-planning-loop-copy');
  assert.deepEqual(appended[0].children, [{ clone: 'a' }, { clone: 'b' }]);
});

test('buildExceptionalCardMarkup renders group name, weekday, occurrence date and time range', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 3,
    groupName: 'Rust Prophet',
    isRecurring: false,
    weekday: 1,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.match(markup, /Rust Prophet/);
  assert.match(markup, /Mardi/);
  assert.match(markup, /04\/08\/2026/);
  assert.match(markup, /18:00.*20:00/);
  assert.match(markup, /rb-badge/);
  assert.match(markup, /rb-planning-card--exceptional/);
});

test('buildExceptionalCardMarkup escapes the group name to prevent XSS', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 1,
    groupName: '<img src=x onerror=alert(1)>',
    isRecurring: false,
    weekday: 0,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.ok(!markup.includes('<img'));
});

test('buildExceptionalCardMarkup does not render role/tabindex/data-contact attributes (non clickable, cf. #81)', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 3,
    groupName: 'Rust Prophet',
    isRecurring: false,
    weekday: 1,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.ok(!markup.includes('role="button"'));
  assert.ok(!markup.includes('tabindex'));
  assert.ok(!markup.includes('data-contact-group'));
});

test('refreshExceptionalPlanning fetches /api/planning and replaces the exceptional track content', async () => {
  globalThis.fetch = async (url) => {
    assert.equal(url, '/api/planning');
    return {
      ok: true,
      json: async () => ({
        fixedSlots: [{ groupId: 1, groupName: 'Fixed Group', isRecurring: true, weekday: 0, startTime: '18:00:00', endTime: '20:00:00', occurrenceDate: null }],
        occasionalSlots: [
          { groupId: 3, groupName: 'Rust Prophet', isRecurring: false, weekday: 1, startTime: '18:00:00', endTime: '20:00:00', occurrenceDate: '2026-08-04' },
        ],
      }),
    };
  };

  let assignedHtml = null;
  const track = {
    set innerHTML(value) {
      assignedHtml = value;
    },
    get innerHTML() {
      return assignedHtml;
    },
    querySelectorAll: () => [],
  };
  const section = fakeSection(true);
  const count = { textContent: '0' };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      if (selector === '[data-planning-tab-count]') return count;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.match(assignedHtml, /Rust Prophet/);
  assert.ok(!assignedHtml.includes('Fixed Group'));
  assert.equal(section.isEmpty(), false, 'the section must be shown when occasional slots are present');
  assert.equal(count.textContent, '1', 'le compteur de l\'onglet suit le nombre de créneaux');
});

function fakeSection(empty) {
  const classes = new Set(empty ? ['rb-planning-section--empty'] : []);
  return {
    classList: { toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)) },
    isEmpty: () => classes.has('rb-planning-section--empty'),
  };
}

test('refreshExceptionalPlanning marks the section empty again when there are no more occasional slots', async () => {
  globalThis.fetch = async () => ({
    ok: true,
    json: async () => ({ fixedSlots: [], occasionalSlots: [] }),
  });

  const track = { innerHTML: '', querySelectorAll: () => [] };
  const section = fakeSection(false);
  const count = { textContent: '2' };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      if (selector === '[data-planning-tab-count]') return count;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.equal(section.isEmpty(), true);
  assert.equal(count.textContent, '0');
});
