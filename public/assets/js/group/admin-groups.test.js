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
