import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PALETTE, DEFAULT_COLOR, normalizeHex, paletteIndex, nextColorIndex } from './color-palette.js';

test('PALETTE holds 8 distinct valid colors', () => {
  assert.equal(PALETTE.length, 8);
  for (const color of PALETTE) {
    assert.equal(normalizeHex(color), color);
  }
  assert.equal(new Set(PALETTE).size, 8);
});

test('DEFAULT_COLOR is the first palette color', () => {
  assert.equal(DEFAULT_COLOR, '#b5654a');
});

test('normalizeHex accepts 6 hex digits and lowercases them', () => {
  assert.equal(normalizeHex('#B5654A'), '#b5654a');
  assert.equal(normalizeHex('#b5654a'), '#b5654a');
  assert.equal(normalizeHex('#AbCdEf'), '#abcdef');
});

test('normalizeHex rejects anything that is not exactly # plus 6 hex digits', () => {
  assert.equal(normalizeHex('#abc'), null);
  assert.equal(normalizeHex('b5654a'), null);
  assert.equal(normalizeHex(' #b5654a'), null);
  assert.equal(normalizeHex('#gggggg'), null);
  assert.equal(normalizeHex('#b5654a;x'), null);
  assert.equal(normalizeHex(''), null);
  assert.equal(normalizeHex(null), null);
  assert.equal(normalizeHex(0xb5654a), null);
});

test('paletteIndex finds palette colors, in any letter case', () => {
  assert.equal(paletteIndex('#b5654a'), 0);
  assert.equal(paletteIndex('#8E6BB0'), 7);
});

test('paletteIndex returns -1 for valid colors outside the palette and for invalid values', () => {
  assert.equal(paletteIndex('#123456'), -1);
  assert.equal(paletteIndex('pas une couleur'), -1);
  assert.equal(paletteIndex(undefined), -1);
});

test('nextColorIndex moves with the arrows and wraps at both ends', () => {
  assert.equal(nextColorIndex(0, 'ArrowRight', 8), 1);
  assert.equal(nextColorIndex(7, 'ArrowRight', 8), 0);
  assert.equal(nextColorIndex(0, 'ArrowDown', 8), 1);
  assert.equal(nextColorIndex(0, 'ArrowLeft', 8), 7);
  assert.equal(nextColorIndex(3, 'ArrowLeft', 8), 2);
  assert.equal(nextColorIndex(7, 'ArrowUp', 8), 6);
});

test('nextColorIndex jumps to the first and last color with Home and End', () => {
  assert.equal(nextColorIndex(5, 'Home', 8), 0);
  assert.equal(nextColorIndex(2, 'End', 8), 7);
});

test('nextColorIndex returns null for any other key and for an empty palette', () => {
  assert.equal(nextColorIndex(0, 'Enter', 8), null);
  assert.equal(nextColorIndex(0, 'a', 8), null);
  assert.equal(nextColorIndex(0, 'ArrowRight', 0), null);
});
