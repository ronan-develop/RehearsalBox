import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextTabIndex, initialTabIndex, readStoredTab, writeStoredTab } from './tabs.js';

test('nextTabIndex moves with the arrows and wraps at both ends', () => {
  assert.equal(nextTabIndex(0, 'ArrowRight', 3), 1);
  assert.equal(nextTabIndex(2, 'ArrowRight', 3), 0);
  assert.equal(nextTabIndex(0, 'ArrowLeft', 3), 2);
  assert.equal(nextTabIndex(1, 'ArrowLeft', 3), 0);
});

test('nextTabIndex jumps to the first and last tab with Home and End', () => {
  assert.equal(nextTabIndex(1, 'Home', 4), 0);
  assert.equal(nextTabIndex(1, 'End', 4), 3);
});

test('nextTabIndex ignores any other key and an empty list', () => {
  assert.equal(nextTabIndex(0, 'Enter', 3), null);
  assert.equal(nextTabIndex(0, 'a', 3), null);
  assert.equal(nextTabIndex(0, 'ArrowRight', 0), null);
});

test('initialTabIndex prefers the remembered tab, then the one marked by the server, then the first', () => {
  const tabs = [{ name: 'a', selected: false }, { name: 'b', selected: true }, { name: 'c', selected: false }];

  assert.equal(initialTabIndex(tabs, 'c'), 2);
  assert.equal(initialTabIndex(tabs, null), 1);
  assert.equal(initialTabIndex(tabs, 'disparu'), 1, 'un onglet mémorisé qui n\'existe plus est ignoré');
  assert.equal(initialTabIndex([{ name: 'a', selected: false }, { name: 'b', selected: false }], null), 0);
});

test('readStoredTab and writeStoredTab round-trip through a storage', () => {
  const data = new Map();
  const storage = { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) };

  assert.equal(readStoredTab(storage, 'k'), null);
  writeStoredTab(storage, 'k', 'exceptional');
  assert.equal(readStoredTab(storage, 'k'), 'exceptional');
});

test('storage failures and a missing key never break the tabs', () => {
  const broken = { getItem() { throw new Error('refusé'); }, setItem() { throw new Error('plein'); } };

  assert.equal(readStoredTab(broken, 'k'), null);
  assert.doesNotThrow(() => writeStoredTab(broken, 'k', 'x'));
  assert.equal(readStoredTab(undefined, 'k'), null);
  assert.doesNotThrow(() => writeStoredTab(undefined, 'k', 'x'));
  assert.equal(readStoredTab({ getItem: () => 'x' }, ''), null, 'sans clé, rien n\'est mémorisé');
  assert.doesNotThrow(() => writeStoredTab(broken, '', 'x'));
});
