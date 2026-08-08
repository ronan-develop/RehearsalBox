import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController, shouldAutoScroll, buildExceptionalCardMarkup, refreshExceptionalPlanning } from './planning-slider.js';

function makeFakeTrack(offsetWidth = 1000) {
  let translateX = 0;
  return {
    offsetWidth,
    style: {
      set transform(value) {
        const match = /translateX\((-?\d+(?:\.\d+)?)px\)/.exec(value);
        translateX = match ? Number(match[1]) : 0;
      },
      get transform() {
        return `translateX(${translateX}px)`;
      },
    },
    get translateX() {
      return translateX;
    },
  };
}

test('createAutoScrollController translates the track further left on each tick while running', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.tick();
  controller.tick();

  assert.equal(track.translateX, -4);
});

test('createAutoScrollController does not move the track while paused', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.tick();

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController resumes advancing after resume() following a pause()', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.resume();
  controller.tick();

  assert.equal(track.translateX, -2);
});

test('createAutoScrollController wraps back to 0 once past half the track width (duplicated content)', () => {
  // Le track contient le contenu dupliqué une fois (2x offsetWidth réel de la
  // liste source) pour boucler sans saut visible ; on doit donc revenir à 0
  // dès qu'on a défilé la moitié de offsetWidth, pas la totalité.
  const track = makeFakeTrack(1000);
  const controller = createAutoScrollController(track, { step: 2 });

  for (let i = 0; i < 250; i += 1) {
    controller.tick();
  }

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController starts running by default', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController pause() is idempotent under concurrent mouseenter/manual pause', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.pause();
  controller.tick();

  assert.equal(track.translateX, 0);
  assert.equal(controller.isRunning(), false);
});

test('shouldAutoScroll returns true when the viewport is narrower than the desktop breakpoint (mobile-first)', () => {
  assert.equal(shouldAutoScroll(767), true);
});

test('shouldAutoScroll returns false when the viewport is at least as wide as the desktop breakpoint', () => {
  assert.equal(shouldAutoScroll(768), false);
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
  const section = { hidden: true, removeAttribute: function (attr) { if (attr === 'hidden') this.hidden = false; } };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.match(assignedHtml, /Rust Prophet/);
  assert.ok(!assignedHtml.includes('Fixed Group'));
  assert.equal(section.hidden, false, 'the section must be revealed when occasional slots are present');
});

test('refreshExceptionalPlanning hides the section again when there are no more occasional slots', async () => {
  globalThis.fetch = async () => ({
    ok: true,
    json: async () => ({ fixedSlots: [], occasionalSlots: [] }),
  });

  const track = { innerHTML: '', querySelectorAll: () => [] };
  const section = { hidden: false, setAttribute: function (attr) { if (attr === 'hidden') this.hidden = true; } };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.equal(section.hidden, true);
});
