import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildExceptionalCardMarkup, refreshExceptionalPlanning } from './exceptional-planning.js';

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
