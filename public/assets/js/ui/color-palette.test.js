import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  HUES,
  TONES,
  DEFAULT_TONE,
  DEFAULT_COLOR,
  colorAt,
  colorName,
  normalizeHex,
  hexToHsl,
  describeColor,
  nextColorIndex,
} from './color-palette.js';

test('HUES holds 13 hues in order, neutral gray last with saturation 0', () => {
  assert.equal(HUES.length, 13);
  assert.deepEqual(HUES.map((h) => h.name), [
    'Rouge', 'Orange', 'Ambre', 'Jaune', 'Citron vert', 'Vert', 'Sarcelle',
    'Cyan', 'Bleu', 'Indigo', 'Violet', 'Rose', 'Gris',
  ]);
  assert.equal(HUES[8].hue, 215);
  assert.equal(HUES[8].saturation, 55);
  assert.equal(HUES[12].saturation, 0);
});

test('TONES holds 7 tones from lightest to darkest', () => {
  assert.deepEqual(TONES, [
    { name: 'très clair', lightness: 85 },
    { name: 'clair', lightness: 75 },
    { name: 'assez clair', lightness: 65 },
    { name: 'moyen', lightness: 55 },
    { name: 'assez foncé', lightness: 45 },
    { name: 'foncé', lightness: 35 },
    { name: 'très foncé', lightness: 25 },
  ]);
  assert.equal(DEFAULT_TONE, 3);
  assert.equal(TONES[DEFAULT_TONE].name, 'moyen');
});

test('colorAt returns the expected color at the four corners of the grid', () => {
  assert.equal(colorAt(0, 0), '#eec4c4');
  assert.equal(colorAt(0, 6), '#631d1d');
  assert.equal(colorAt(12, 0), '#d9d9d9');
  assert.equal(colorAt(12, 6), '#404040');
});

test('colorAt matches hand-computed HSL conversions', () => {
  // Bleu (215°, 55 %) foncé (35 %) : C = 0.385, X = 0.160417, m = 0.1575
  assert.equal(colorAt(8, 5), '#28518a');
  // Orange (25°, 55 %) moyen (55 %) : C = 0.495, X = 0.20625, m = 0.3025
  assert.equal(colorAt(1, 3), '#cb824d');
  assert.equal(DEFAULT_COLOR, '#cb824d');
});

test('colorAt returns null for invalid indexes', () => {
  assert.equal(colorAt(-1, 0), null);
  assert.equal(colorAt(0, -1), null);
  assert.equal(colorAt(13, 0), null);
  assert.equal(colorAt(0, 7), null);
  assert.equal(colorAt(1.5, 0), null);
  assert.equal(colorAt(0, 2.5), null);
  assert.equal(colorAt(NaN, 0), null);
  assert.equal(colorAt(0, NaN), null);
  assert.equal(colorAt('1', 0), null);
  assert.equal(colorAt(0, undefined), null);
});

test('the 91 grid colors are valid hex and distinct within each hue', () => {
  for (let h = 0; h < HUES.length; h++) {
    const colors = TONES.map((_, t) => colorAt(h, t));
    for (const color of colors) {
      assert.equal(normalizeHex(color), color);
    }
    assert.equal(new Set(colors).size, TONES.length);
  }
});

test('colorName joins hue and tone names, keeping accents', () => {
  assert.equal(colorName(8, 5), 'Bleu foncé');
  assert.equal(colorName(4, 0), 'Citron vert très clair');
  assert.equal(colorName(12, 3), 'Gris moyen');
  assert.equal(colorName(1, 3), 'Orange moyen');
});

test('colorName returns null for invalid indexes', () => {
  assert.equal(colorName(13, 0), null);
  assert.equal(colorName(0, 7), null);
  assert.equal(colorName(0.5, 0), null);
});

test('normalizeHex accepts 6 hex digits and lowercases them', () => {
  assert.equal(normalizeHex('#B5654A'), '#b5654a');
  assert.equal(normalizeHex('#b5654a'), '#b5654a');
  assert.equal(normalizeHex('#AABBCC'), '#aabbcc');
});

test('normalizeHex rejects anything that is not exactly # plus 6 hex digits', () => {
  assert.equal(normalizeHex('red;background:url(//x)'), null);
  assert.equal(normalizeHex('#12345g'), null);
  assert.equal(normalizeHex('#fff'), null);
  assert.equal(normalizeHex('#1234567'), null);
  assert.equal(normalizeHex(' #aabbcc'), null);
  assert.equal(normalizeHex('#aabbcc\n'), null);
  assert.equal(normalizeHex('#١٢٣٤٥٦'), null);
  assert.equal(normalizeHex('#aabbcc\u0000'), null);
  assert.equal(normalizeHex(['#aabbcc']), null);
  assert.equal(normalizeHex({ value: '#aabbcc' }), null);
  assert.equal(normalizeHex(null), null);
  assert.equal(normalizeHex(0xaabbcc), null);
});

test('hexToHsl converts pure red, white and black', () => {
  assert.deepEqual(hexToHsl('#ff0000'), { h: 0, s: 100, l: 50 });
  assert.deepEqual(hexToHsl('#ffffff'), { h: 0, s: 0, l: 100 });
  assert.deepEqual(hexToHsl('#000000'), { h: 0, s: 0, l: 0 });
});

test('hexToHsl returns null for invalid values', () => {
  assert.equal(hexToHsl('#fff'), null);
  assert.equal(hexToHsl(null), null);
});

test('describeColor recognizes a grid color exactly', () => {
  assert.deepEqual(describeColor('#28518A'), {
    value: '#28518a',
    hueIndex: 8,
    toneIndex: 5,
    name: 'Bleu foncé',
    exact: true,
  });
});

test('describeColor finds the nearest grid color for an off-grid color', () => {
  const result = describeColor('#b5654a');
  assert.equal(result.value, '#b5654a');
  assert.equal(result.exact, false);
  // Teinte ≈ 15° : Orange (25°) est plus proche que Rouge (0°) ; luminosité 50 % : moyen (55) à égalité, plus clair
  assert.equal(result.hueIndex, 1);
  assert.equal(result.toneIndex, 3);
  assert.equal(result.name, 'proche de Orange moyen');
});

test('describeColor treats a pure gray as neutral', () => {
  const result = describeColor('#808080');
  assert.equal(result.exact, false);
  assert.equal(result.hueIndex, 12);
  assert.equal(result.toneIndex, 3);
  assert.equal(result.name, 'proche de Gris moyen');
});

test('describeColor returns null for an invalid value', () => {
  assert.equal(describeColor('red'), null);
  assert.equal(describeColor('#fff'), null);
  assert.equal(describeColor(null), null);
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

test('DEFAULT_COLOR is Orange moyen and matches SafeColor::DEFAULT on the server', () => {
  assert.equal(DEFAULT_COLOR, '#cb824d');
  assert.equal(describeColor(DEFAULT_COLOR).name, 'Orange moyen');
});
