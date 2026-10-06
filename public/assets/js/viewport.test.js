import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DESKTOP_MIN_WIDTH, isDesktopWidth } from './viewport.js';

test('the desktop starts at 768 px, like the CSS media queries', () => {
  assert.equal(DESKTOP_MIN_WIDTH, 768);
  assert.equal(isDesktopWidth(767), false);
  assert.equal(isDesktopWidth(768), true);
});

test('an unknown width counts as desktop (tests, server-side rendering)', () => {
  assert.equal(isDesktopWidth(undefined), true);
});
