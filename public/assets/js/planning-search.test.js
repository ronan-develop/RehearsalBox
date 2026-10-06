import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchesPlanningSearch, initPlanningSearch, syncDayHeadings } from './planning-search.js';

test('matchesPlanningSearch matches on group name, case-insensitive', () => {
  const card = { weekday: '2', groupName: 'Dead Kennedys Cover' };

  assert.equal(matchesPlanningSearch(card, 'kennedys'), true);
  assert.equal(matchesPlanningSearch(card, 'KENNEDYS'), true);
  assert.equal(matchesPlanningSearch(card, 'nirvana'), false);
});

test('matchesPlanningSearch matches on weekday label, case-insensitive', () => {
  const card = { weekday: '2', groupName: 'Dead Kennedys Cover' };

  assert.equal(matchesPlanningSearch(card, 'mercredi'), true);
  assert.equal(matchesPlanningSearch(card, 'lundi'), false);
});

test('matchesPlanningSearch matches everything when the query is empty', () => {
  const card = { weekday: '2', groupName: 'Dead Kennedys Cover' };

  assert.equal(matchesPlanningSearch(card, ''), true);
  assert.equal(matchesPlanningSearch(card, '   '), true);
});

function fakeCard({ weekday, groupName }) {
  const classes = new Set();
  return {
    dataset: { weekday, contactGroupName: groupName },
    classList: {
      add: (c) => classes.add(c),
      remove: (c) => classes.delete(c),
      contains: (c) => classes.has(c),
      toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)),
    },
  };
}

test('initPlanningSearch hides cards that do not match the typed query', () => {
  const cardMonday = fakeCard({ weekday: '0', groupName: 'Nebula Sprawl' });
  const cardWednesday = fakeCard({ weekday: '2', groupName: 'Dead Kennedys Cover' });
  let changeHandler;
  const input = {
    value: '',
    addEventListener: (event, cb) => {
      if (event === 'input') changeHandler = cb;
    },
  };
  const doc = {
    querySelector: (selector) => (selector === '[data-planning-search]' ? input : null),
    querySelectorAll: (selector) => (selector === '.rb-planning-card' ? [cardMonday, cardWednesday] : []),
  };

  initPlanningSearch(doc);
  input.value = 'kennedys';
  changeHandler();

  assert.equal(cardMonday.classList.contains('rb-planning-card--hidden'), true);
  assert.equal(cardWednesday.classList.contains('rb-planning-card--hidden'), false);
});

test('initPlanningSearch shows every card again when the query is cleared', () => {
  const card = fakeCard({ weekday: '0', groupName: 'Nebula Sprawl' });
  let changeHandler;
  const input = {
    value: '',
    addEventListener: (event, cb) => {
      if (event === 'input') changeHandler = cb;
    },
  };
  const doc = {
    querySelector: (selector) => (selector === '[data-planning-search]' ? input : null),
    querySelectorAll: (selector) => (selector === '.rb-planning-card' ? [card] : []),
  };

  initPlanningSearch(doc);
  input.value = 'kennedys';
  changeHandler();
  assert.equal(card.classList.contains('rb-planning-card--hidden'), true);

  input.value = '';
  changeHandler();
  assert.equal(card.classList.contains('rb-planning-card--hidden'), false);
});

test('initPlanningSearch does nothing when the search input is absent from the page', () => {
  const doc = {
    querySelector: () => null,
    querySelectorAll: () => [],
  };

  assert.doesNotThrow(() => initPlanningSearch(doc));
});

function fakeTrack(children) {
  return { children, querySelectorAll: () => children.filter((c) => c.isCard) };
}

function fakeDayHeading() {
  const classes = new Set();
  return { isDay: true, classList: { toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)), contains: (c) => classes.has(c) }, matches: (s) => s === '.rb-planning-day' };
}

function fakeHideableCard(hidden = false) {
  return { isCard: true, classList: { contains: (c) => c === 'rb-planning-card--hidden' && hidden }, matches: (s) => s === '.rb-planning-card' };
}

test('syncDayHeadings hides a day heading when none of its cards is visible', () => {
  const monday = fakeDayHeading();
  const tuesday = fakeDayHeading();
  const track = fakeTrack([monday, fakeHideableCard(true), tuesday, fakeHideableCard(false)]);

  syncDayHeadings(track);

  assert.equal(monday.classList.contains('rb-planning-day--hidden'), true);
  assert.equal(tuesday.classList.contains('rb-planning-day--hidden'), false);
});

test('syncDayHeadings keeps a heading when at least one of its cards is visible', () => {
  const day = fakeDayHeading();
  const track = fakeTrack([day, fakeHideableCard(true), fakeHideableCard(false)]);

  syncDayHeadings(track);

  assert.equal(day.classList.contains('rb-planning-day--hidden'), false);
});
