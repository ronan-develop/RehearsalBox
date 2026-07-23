import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildConfirmModalMarkup } from './rb-confirm-modal.js';

test('buildConfirmModalMarkup escapes the message to prevent XSS', () => {
  const html = buildConfirmModalMarkup('<script>alert(1)</script>');

  assert.ok(!html.includes('<script>'));
  assert.ok(html.includes('&lt;script&gt;'));
});

test('buildConfirmModalMarkup includes confirm and cancel buttons', () => {
  const html = buildConfirmModalMarkup('Supprimer ce créneau ?');

  assert.ok(html.includes('data-confirm-modal-confirm'));
  assert.ok(html.includes('data-confirm-modal-cancel'));
});

test('buildConfirmModalMarkup uses the shared rb-card class for visual consistency', () => {
  const html = buildConfirmModalMarkup('Confirmer ?');

  assert.ok(html.includes('rb-card'));
});
