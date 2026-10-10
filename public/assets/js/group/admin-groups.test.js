import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderGroupCard, groupDeletionWarning } from './admin-groups.js';

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

  assert.ok(html.includes('<rb-async-form endpoint="/api/admin/groups/7" method="PATCH">'));
  assert.ok(html.includes('<rb-async-form endpoint="/api/admin/groups/7/members" method="POST">'), 'et le formulaire d\'ajout d\'un membre');
  assert.ok(!html.includes('data-async'), 'plus de contrat par attributs data-*');
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

test('renderGroupCard renders action buttons as icons with accessible labels', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });

  assert.ok(html.includes('aria-label="Modifier"'));
  assert.ok(html.includes('aria-label="Supprimer"'));
  assert.ok(html.includes('aria-label="Ajouter"'));
  assert.match(html, /<svg[^>]*aria-hidden="true"/);
});

test('renderGroupCard puts the id in the card, the endpoints and the field ids', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null, contactEmail: 'g@example.test' });

  assert.ok(html.includes('<article class="rb-group-card rb-card" data-group-id="7">'));
  assert.ok(html.includes('endpoint="/api/admin/groups/7"'));
  assert.ok(html.includes('endpoint="/api/admin/groups/7/members"'));
  assert.ok(html.includes('id="group-7-name"'));
  assert.ok(html.includes('id="group-7-member-email"'));
});

test('each field of the edit form has a label bound to its input', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: 'metal', colorHex: '#e63946', contactEmail: 'g@example.test' });

  for (const field of ['name', 'genre', 'colorHex', 'contactEmail']) {
    assert.ok(html.includes(`<label for="group-7-${field}">`), `label pour ${field}`);
    assert.ok(html.includes(`id="group-7-${field}" name="${field}"`), `champ ${field} avec son id`);
  }
});

test('the colour field is a text input wrapped by rb-color-picker, never a native colour input', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: '#e63946' });

  assert.match(html, /<rb-color-picker><input type="text" id="group-7-colorHex" name="colorHex"[^>]*value="#e63946"/);
  assert.ok(!html.includes('type="color"'));
});

test('a missing colour falls back to the default brick colour', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });

  assert.match(html, /name="colorHex"[^>]*value="#b5654a"/);
});

test('the action buttons sit in the rb-group-card-head header', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });
  const header = html.match(/<header class="rb-group-card-head">([\s\S]*?)<\/header>/);

  assert.ok(header, 'un en-tête rb-group-card-head existe');
  assert.ok(header[1].includes('<div class="rb-group-actions">'));
  assert.ok(header[1].includes('data-edit-group-button'));
  assert.ok(header[1].includes('data-delete-group-button'));
});

test('the add-member form labels its email field', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });

  assert.ok(html.includes('<label for="group-7-member-email">Ajouter un membre</label>'));
});

test('a null genre writes no genre paragraph', () => {
  const html = renderGroupCard({ id: 7, name: 'Groupe Test', genre: null, colorHex: null });

  assert.ok(!html.includes('rb-group-genre'));
});

test('deleting an empty group only asks for a simple confirmation', () => {
  assert.equal(groupDeletionWarning({ members: 0, conversations: 0, documents: 0, requests: 0 }), 'Supprimer ce groupe ?');
});

test('the confirmation says exactly what the deletion takes with it', () => {
  const message = groupDeletionWarning({ members: 5, conversations: 3, documents: 2, requests: 1 });

  assert.match(message, /^Supprimer ce groupe \?\n/);
  assert.match(message, /3 conversations, avec tous leurs messages, pour les deux groupes concernés/);
  assert.match(message, /2 documents/);
  assert.match(message, /1 demande de créneau/);
  assert.match(message, /5 membres seront retirés du groupe/);
  assert.match(message, /ne peut pas être annulée/);
});

test('the wording agrees in the singular and omits what is zero', () => {
  const message = groupDeletionWarning({ members: 1, conversations: 1, documents: 0, requests: 0 });

  assert.match(message, /1 conversation, avec tous ses messages, pour les deux groupes concernés/);
  assert.match(message, /1 membre sera retiré du groupe/);
  assert.doesNotMatch(message, /document/);
  assert.doesNotMatch(message, /demande/);
});

test('missing or invalid counts are read as zero rather than breaking the confirmation', () => {
  assert.equal(groupDeletionWarning({}), 'Supprimer ce groupe ?');
  assert.equal(groupDeletionWarning({ members: 'abc', conversations: undefined, documents: null, requests: -3 }), 'Supprimer ce groupe ?');
});

test('a newly created card starts with zero counts so its confirmation stays simple', () => {
  const html = renderGroupCard({ id: 9, name: 'Neuf', genre: null, colorHex: null, contactEmail: 'n@example.test' });

  assert.match(html, /data-members-count="0" data-conversations-count="0" data-documents-count="0" data-requests-count="0"/);
});
