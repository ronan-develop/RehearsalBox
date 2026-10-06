import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initPlanningTabs } from './planning-tabs.js';

function fakeElement(dataset = {}) {
  const classes = new Set();
  const attributes = {};
  const listeners = {};
  return {
    dataset,
    classes,
    attributes,
    listeners,
    classList: {
      add: (c) => classes.add(c),
      remove: (c) => classes.delete(c),
      toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)),
      contains: (c) => classes.has(c),
    },
    setAttribute: (name, value) => { attributes[name] = String(value); },
    getAttribute: (name) => attributes[name] ?? null,
    addEventListener: (event, cb) => { listeners[event] = cb; },
  };
}

function makeDom() {
  const container = fakeElement();
  const tabRegular = fakeElement({ planningTab: 'regular' });
  const tabExceptional = fakeElement({ planningTab: 'exceptional' });
  const panelRegular = fakeElement({ planningPanel: 'regular' });
  const panelExceptional = fakeElement({ planningPanel: 'exceptional' });
  const root = {
    querySelector: (selector) => (selector === '[data-planning-tabs]' ? container : null),
  };
  container.querySelectorAll = (selector) => {
    if (selector === '[data-planning-tab]') return [tabRegular, tabExceptional];
    if (selector === '[data-planning-panel]') return [panelRegular, panelExceptional];
    return [];
  };
  return { root, container, tabRegular, tabExceptional, panelRegular, panelExceptional };
}

function memoryStorage(initial = {}) {
  const data = { ...initial };
  return {
    data,
    getItem: (key) => data[key] ?? null,
    setItem: (key, value) => { data[key] = value; },
  };
}

test('initPlanningTabs shows the planning panel first and marks the container ready (no JS: everything stays visible)', () => {
  const dom = makeDom();

  initPlanningTabs(dom.root, memoryStorage());

  assert.ok(dom.container.attributes['data-planning-tabs-ready'] !== undefined);
  assert.equal(dom.tabRegular.attributes['aria-selected'], 'true');
  assert.equal(dom.tabExceptional.attributes['aria-selected'], 'false');
  assert.equal(dom.panelRegular.classes.has('is-active'), true);
  assert.equal(dom.panelExceptional.classes.has('is-active'), false);
});

test('clicking a tab activates its panel only and remembers the choice', () => {
  const dom = makeDom();
  const storage = memoryStorage();
  initPlanningTabs(dom.root, storage);

  dom.tabExceptional.listeners.click();

  assert.equal(dom.tabExceptional.attributes['aria-selected'], 'true');
  assert.equal(dom.tabRegular.attributes['aria-selected'], 'false');
  assert.equal(dom.panelExceptional.classes.has('is-active'), true);
  assert.equal(dom.panelRegular.classes.has('is-active'), false);
  assert.equal(storage.data['rb-planning-tab'], 'exceptional');
});

test('the last chosen tab is restored on the next visit', () => {
  const dom = makeDom();

  initPlanningTabs(dom.root, memoryStorage({ 'rb-planning-tab': 'exceptional' }));

  assert.equal(dom.panelExceptional.classes.has('is-active'), true);
  assert.equal(dom.tabExceptional.attributes['aria-selected'], 'true');
});

test('an unknown stored value falls back to the planning panel', () => {
  const dom = makeDom();

  initPlanningTabs(dom.root, memoryStorage({ 'rb-planning-tab': '<script>' }));

  assert.equal(dom.panelRegular.classes.has('is-active'), true);
});

test('a storage that throws never breaks the tabs', () => {
  const dom = makeDom();
  const broken = { getItem: () => { throw new Error('bloqué'); }, setItem: () => { throw new Error('bloqué'); } };

  initPlanningTabs(dom.root, broken);
  dom.tabExceptional.listeners.click();

  assert.equal(dom.panelExceptional.classes.has('is-active'), true);
});

test('initPlanningTabs does nothing when the page has no planning tabs', () => {
  assert.doesNotThrow(() => initPlanningTabs({ querySelector: () => null }, memoryStorage()));
});
