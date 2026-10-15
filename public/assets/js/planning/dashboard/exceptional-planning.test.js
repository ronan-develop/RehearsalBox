import { test } from 'node:test';
import assert from 'node:assert/strict';
import { refreshExceptionalPlanning } from './exceptional-planning.js';

function fakeSection(empty) {
  const classes = new Set(empty ? ['rb-planning-section--empty'] : []);
  return {
    classList: { toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)) },
    isEmpty: () => classes.has('rb-planning-section--empty'),
  };
}

function fakeRoot({ section, count, track, planningTrack = null, search = null }) {
  return {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-planning-track]') return planningTrack;
      if (selector === '[data-planning-search]') return search;
      if (selector === '[data-exceptional-planning-section]') return section;
      if (selector === '[data-planning-tab-count]') return count;
      return null;
    },
  };
}

test('refreshExceptionalPlanning fetches the server-rendered cards and inserts them as received', async () => {
  const html = '<article class="rb-planning-card rb-planning-card--exceptional"><h4>Rust Prophet</h4></article>';
  globalThis.fetch = async (url) => {
    assert.equal(url, '/api/planning/exceptional');

    return { ok: true, json: async () => ({ html, count: 1 }) };
  };
  const track = { innerHTML: '', querySelectorAll: () => [] };
  const section = fakeSection(true);
  const count = { textContent: '0' };

  await refreshExceptionalPlanning(fakeRoot({ section, count, track }));

  assert.equal(track.innerHTML, html, 'le HTML du serveur est inséré tel quel : plus de copie du balisage côté navigateur');
  assert.equal(section.isEmpty(), false, 'the section must be shown when occasional slots are present');
  assert.equal(count.textContent, '1', 'le compteur de l\'onglet suit le nombre de créneaux');
});

test('refreshExceptionalPlanning marks the section empty again when there are no more occasional slots', async () => {
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ html: '', count: 0 }) });
  const track = { innerHTML: '<article></article>', querySelectorAll: () => [] };
  const section = fakeSection(false);
  const count = { textContent: '2' };

  await refreshExceptionalPlanning(fakeRoot({ section, count, track }));

  assert.equal(section.isEmpty(), true);
  assert.equal(count.textContent, '0');
  assert.equal(track.innerHTML, '');
});

test('without the exceptional track on the page nothing is fetched', async () => {
  let fetched = false;
  globalThis.fetch = async () => {
    fetched = true;

    return { ok: true, json: async () => ({ html: '', count: 0 }) };
  };

  await refreshExceptionalPlanning({ querySelector: () => null });

  assert.equal(fetched, false);
});

test('the fixed planning is replaced by the server-rendered track too, and the search filter is applied again', async () => {
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ html: '<article></article>', count: 1, planningHtml: '<h3>Mercredi</h3>' }) });
  const planningTrack = { innerHTML: '<h3>Ancien</h3>' };
  const events = [];
  const search = { dispatchEvent: (event) => events.push(event.type) };

  await refreshExceptionalPlanning(fakeRoot({ section: fakeSection(true), count: { textContent: '0' }, track: { innerHTML: '' }, planningTrack, search }));

  assert.equal(planningTrack.innerHTML, '<h3>Mercredi</h3>', 'le planning montre aussitôt le créneau accepté (#461)');
  assert.deepEqual(events, ['input'], 'le filtre de recherche déjà saisi est réappliqué sur les nouvelles cartes');
});

test('a response without the planning, or a page without a planning track, leaves the planning untouched', async () => {
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ html: '', count: 0 }) });
  const planningTrack = { innerHTML: '<h3>Intact</h3>' };

  await refreshExceptionalPlanning(fakeRoot({ section: fakeSection(true), count: { textContent: '0' }, track: { innerHTML: '' }, planningTrack }));
  assert.equal(planningTrack.innerHTML, '<h3>Intact</h3>');

  globalThis.fetch = async () => ({ ok: true, json: async () => ({ html: '', count: 0, planningHtml: '<h3>X</h3>' }) });
  await refreshExceptionalPlanning(fakeRoot({ section: fakeSection(true), count: { textContent: '0' }, track: { innerHTML: '' } }));
});
