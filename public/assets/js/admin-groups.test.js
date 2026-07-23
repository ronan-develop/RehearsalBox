import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderGroupCard } from './admin-groups.js';

test('renderGroupCard escapes the group name to prevent XSS', () => {
  const html = renderGroupCard({ id: 1, name: '<script>alert(1)</script>', genre: null, colorHex: null });

  assert.ok(!html.includes('<script>'));
  assert.ok(html.includes('&lt;script&gt;'));
});

test('renderGroupCard includes the group id for later DOM targeting', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: 'metal', colorHex: '#e63946' });

  assert.ok(html.includes('data-group-id="7"'));
});

test('renderGroupCard uses the shared rb-card class for visual consistency', () => {
  const html = renderGroupCard({ id: 1, name: 'Groupe Test', genre: null, colorHex: null });

  assert.ok(html.includes('rb-card'));
});
