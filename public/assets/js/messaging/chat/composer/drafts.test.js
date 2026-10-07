import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clearAllDrafts, createDraftStore, DRAFT_PREFIX, DRAFT_TTL_MS } from './drafts.js';

/** Faux stockage : mêmes méthodes que localStorage. */
function fakeStorage(initial = {}) {
  const data = new Map(Object.entries(initial));

  return {
    data,
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => { data.set(key, String(value)); },
    removeItem: (key) => { data.delete(key); },
    key: (index) => [...data.keys()][index] ?? null,
    get length() { return data.size; },
  };
}

const NOW = 1_700_000_000_000;

test('a draft is kept per user and per conversation and restored', () => {
  const store = createDraftStore(fakeStorage(), { userId: 7, now: () => NOW });

  store.save('12', 'Bonjour, je');

  assert.equal(store.load('12'), 'Bonjour, je');
  assert.equal(store.load('13'), '', 'une autre conversation n\'a pas ce brouillon');
});

test('another user of the same browser never sees my draft', () => {
  const storage = fakeStorage();
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('12', 'privé');

  assert.equal(createDraftStore(storage, { userId: 8, now: () => NOW }).load('12'), '');
});

test('an empty or blank text removes the draft', () => {
  const storage = fakeStorage();
  const store = createDraftStore(storage, { userId: 7, now: () => NOW });
  store.save('12', 'texte');

  store.save('12', '   ');

  assert.equal(store.load('12'), '');
  assert.equal(storage.length, 0, 'plus aucune trace');
});

test('a draft older than the lifetime is dropped on load', () => {
  const storage = fakeStorage();
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('12', 'vieux');

  const later = createDraftStore(storage, { userId: 7, now: () => NOW + DRAFT_TTL_MS + 1 });

  assert.equal(later.load('12'), '');
  assert.equal(storage.length, 0, 'et supprimé du stockage');
});

test('a recent draft survives just inside the lifetime', () => {
  const storage = fakeStorage();
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('12', 'récent');

  assert.equal(createDraftStore(storage, { userId: 7, now: () => NOW + DRAFT_TTL_MS - 1 }).load('12'), 'récent');
});

test('clearAll removes every draft of every user but nothing else', () => {
  const storage = fakeStorage({ 'autre-cle': 'garde-moi' });
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('12', 'a');
  createDraftStore(storage, { userId: 8, now: () => NOW }).save('13', 'b');

  createDraftStore(storage, { userId: 7, now: () => NOW }).clearAll();

  assert.deepEqual([...storage.data.keys()], ['autre-cle'], 'poste partagé : aucun brouillon ne reste lisible');
});

test('clearExpired purges only the old drafts', () => {
  const storage = fakeStorage();
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('old', 'vieux');
  createDraftStore(storage, { userId: 7, now: () => NOW + DRAFT_TTL_MS }).save('new', 'neuf');

  createDraftStore(storage, { userId: 7, now: () => NOW + DRAFT_TTL_MS + 5 }).clearExpired();

  assert.equal([...storage.data.keys()].filter((k) => k.startsWith(DRAFT_PREFIX)).length, 1);
});

test('corrupted values are ignored and removed instead of breaking the page', () => {
  const storage = fakeStorage();
  const store = createDraftStore(storage, { userId: 7, now: () => NOW });
  const key = [...(() => { store.save('12', 'x'); return storage.data.keys(); })()][0];
  storage.data.set(key, '{pas du json');

  assert.equal(store.load('12'), '');
  assert.equal(storage.length, 0);

  storage.data.set(key, JSON.stringify({ text: 42, savedAt: 'hier' }));
  assert.equal(store.load('12'), '');
});

test('a storage that throws never breaks anything (private browsing, quota, blocked)', () => {
  const broken = {
    getItem() { throw new Error('refusé'); },
    setItem() { throw new Error('quota'); },
    removeItem() { throw new Error('refusé'); },
    key() { throw new Error('refusé'); },
    get length() { throw new Error('refusé'); },
  };
  const store = createDraftStore(broken, { userId: 7, now: () => NOW });

  assert.doesNotThrow(() => store.save('12', 'texte'));
  assert.equal(store.load('12'), '');
  assert.doesNotThrow(() => store.clearAll());
  assert.doesNotThrow(() => store.clearExpired());
});

test('without any storage at all, the store is a harmless no-op', () => {
  const store = createDraftStore(null, { userId: 7, now: () => NOW });

  assert.doesNotThrow(() => store.save('12', 'texte'));
  assert.equal(store.load('12'), '');
});

test('the draft is never anything but text: markup stays inert data', () => {
  const store = createDraftStore(fakeStorage(), { userId: 7, now: () => NOW });

  store.save('12', '<img src=x onerror=alert(1)>');

  assert.equal(store.load('12'), '<img src=x onerror=alert(1)>', 'restitué tel quel, inséré via .value (jamais en HTML)');
});

test('clearAllDrafts empties the browser drafts and survives a blocked storage', () => {
  const storage = fakeStorage({ 'autre-cle': 'garde-moi' });
  createDraftStore(storage, { userId: 7, now: () => NOW }).save('12', 'a');
  globalThis.window = { localStorage: storage };

  clearAllDrafts();

  assert.deepEqual([...storage.data.keys()], ['autre-cle']);

  Object.defineProperty(globalThis.window, 'localStorage', { get() { throw new Error('bloqué'); } });
  assert.doesNotThrow(() => clearAllDrafts());
  delete globalThis.window;
});
