import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createScrollMemory } from './scroll-memory.js';

function fakeStorage() {
  const data = new Map();

  return {
    data,
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => { data.set(key, String(value)); },
  };
}

test('the scroll position of a list is saved and restored per list', () => {
  const storage = fakeStorage();
  const memory = createScrollMemory(storage);

  memory.save('active', 240);
  memory.save('archived', 12);

  assert.equal(memory.load('active'), 240);
  assert.equal(memory.load('archived'), 12);
  assert.equal(memory.load('autre'), null, 'jamais enregistrée : rien à restaurer');
});

test('nothing but a non-negative number is ever kept or returned', () => {
  const storage = fakeStorage();
  const memory = createScrollMemory(storage);

  memory.save('active', -5);
  memory.save('active', Number.NaN);
  memory.save('active', 'abc');
  assert.equal(memory.load('active'), null);

  storage.setItem('rb-list-scroll:active', '<script>');
  assert.equal(memory.load('active'), null, 'valeur altérée ignorée');
  storage.setItem('rb-list-scroll:active', '-40');
  assert.equal(memory.load('active'), null);
});

test('positions are rounded to whole pixels', () => {
  const memory = createScrollMemory(fakeStorage());

  memory.save('active', 240.7);

  assert.equal(memory.load('active'), 241);
});

test('a storage that throws, or no storage at all, never breaks anything', () => {
  const broken = { getItem() { throw new Error('refusé'); }, setItem() { throw new Error('quota'); } };

  for (const storage of [broken, null]) {
    const memory = createScrollMemory(storage);
    assert.doesNotThrow(() => memory.save('active', 10));
    assert.equal(memory.load('active'), null);
  }
});
