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

test('buildConfirmModalMarkup shows an optional escaped title and custom button labels', () => {
  const html = buildConfirmModalMarkup('Texte', { title: 'Supprimer <b>?</b>', confirmLabel: 'Mettre à la corbeille', cancelLabel: 'Garder' });

  assert.ok(html.includes('class="rb-modal-title"'));
  assert.ok(html.includes('Supprimer &lt;b&gt;?&lt;/b&gt;'));
  assert.ok(html.includes('>Mettre à la corbeille<'));
  assert.ok(html.includes('>Garder<'));
  assert.ok(html.includes('aria-labelledby="rb-modal-title"'), 'le titre nomme la boîte de dialogue');
});

test('buildConfirmModalMarkup keeps the default labels and no title when no option is given', () => {
  const html = buildConfirmModalMarkup('Texte');

  assert.ok(html.includes('>Confirmer<'));
  assert.ok(html.includes('>Annuler<'));
  assert.ok(!html.includes('rb-modal-title'));
  assert.ok(!html.includes('aria-labelledby'));
});

test('buildConfirmModalMarkup splits a multi-line message into paragraphs and describes the dialog with them', () => {
  const html = buildConfirmModalMarkup('Première ligne\nSeconde ligne');

  assert.equal((html.match(/<p /g) ?? []).length, 2);
  assert.ok(html.includes('aria-describedby="rb-modal-body"'));
});
