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

test('renderGroupCard includes an edit form targeting the PATCH endpoint', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: 'metal', colorHex: '#e63946' });

  assert.ok(html.includes('data-endpoint="/api/admin/groups/7"'));
  assert.ok(html.includes('data-method="PATCH"'));
});

test('renderGroupCard includes a delete button carrying the group id', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });

  assert.ok(html.includes('data-delete-group-button'));
  assert.ok(html.includes('data-group-id="7"'));
});

test('renderGroupCard escapes the group name in the edit form value', () => {
  const html = renderGroupCard({ id: 1, name: '"><script>alert(1)</script>', genre: null, colorHex: null });

  assert.ok(!html.includes('<script>'));
});
