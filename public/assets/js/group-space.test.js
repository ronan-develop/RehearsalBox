import { test } from 'node:test';
import assert from 'node:assert/strict';
import { collectLineup, collectUpcomingShows, addLineupRow, addShowRow, removeRow, saveProfile } from './group-space.js';

function fakeRow(values) {
  const inputs = {};
  for (const [name, value] of Object.entries(values)) {
    inputs[name] = { value };
  }
  return {
    querySelector: (selector) => {
      const match = selector.match(/name="(.+)"/);
      return match ? inputs[match[1]] ?? null : null;
    },
    remove: () => {},
  };
}

function fakeList(rows) {
  return {
    rows,
    children: rows,
    querySelectorAll: () => rows,
    appendChild: (row) => { rows.push(row); },
  };
}

test('collectLineup reads name/instrument from each row in the list', () => {
  const list = fakeList([
    fakeRow({ name: 'Alice', instrument: 'Guitare' }),
    fakeRow({ name: 'Bob', instrument: 'Batterie' }),
  ]);

  const result = collectLineup(list);

  assert.deepEqual(result, [
    { name: 'Alice', instrument: 'Guitare' },
    { name: 'Bob', instrument: 'Batterie' },
  ]);
});

test('collectLineup skips rows with an empty name', () => {
  const list = fakeList([
    fakeRow({ name: '', instrument: 'Guitare' }),
    fakeRow({ name: 'Bob', instrument: 'Batterie' }),
  ]);

  const result = collectLineup(list);

  assert.deepEqual(result, [{ name: 'Bob', instrument: 'Batterie' }]);
});

test('collectUpcomingShows reads date/venue from each row in the list', () => {
  const list = fakeList([
    fakeRow({ date: '2026-09-12', venue: 'Le Point Éphémère' }),
  ]);

  const result = collectUpcomingShows(list);

  assert.deepEqual(result, [{ date: '2026-09-12', venue: 'Le Point Éphémère' }]);
});

function fakeDomRoot() {
  return {
    createElement: () => ({
      dataset: {},
      classList: { add: () => {} },
      children: [],
      appendChild(child) { this.children.push(child); },
    }),
  };
}

test('addLineupRow appends a new row to the list', () => {
  const rows = [];
  const list = fakeList(rows);

  addLineupRow(list, fakeDomRoot());

  assert.equal(rows.length, 1);
});

test('addShowRow appends a new row to the list', () => {
  const rows = [];
  const list = fakeList(rows);

  addShowRow(list, fakeDomRoot());

  assert.equal(rows.length, 1);
});

test('removeRow removes the row from the DOM', () => {
  let removed = false;
  const row = { remove: () => { removed = true; } };

  removeRow(row);

  assert.equal(removed, true);
});

test('saveProfile sends lineup and upcomingShows as JSON via PATCH', async () => {
  let capturedUrl = null;
  let capturedOptions = null;
  const fakeApiFetch = async (url, options) => {
    capturedUrl = url;
    capturedOptions = options;
    return {};
  };

  await saveProfile('/api/groups/1/space', [{ name: 'Alice', instrument: 'Guitare' }], [{ date: '2026-09-12', venue: 'Le Point Éphémère' }], fakeApiFetch);

  assert.equal(capturedUrl, '/api/groups/1/space');
  assert.equal(capturedOptions.method, 'PATCH');
  const body = JSON.parse(capturedOptions.body);
  assert.deepEqual(body.lineup, [{ name: 'Alice', instrument: 'Guitare' }]);
  assert.deepEqual(body.upcomingShows, [{ date: '2026-09-12', venue: 'Le Point Éphémère' }]);
});
