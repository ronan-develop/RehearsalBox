import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderUserCard, renderUserList } from './admin-users.js';

const alice = { id: 5, email: 'alice@rehearsalbox.test', displayName: 'Alice', role: 'musicien', isActive: true, isLocked: false, groups: [{ id: 1, name: 'Rock' }] };

test('renderUserCard shows name, e-mail, role label and groups', () => {
  const html = renderUserCard(alice, { currentUserId: 1 });

  assert.ok(html.includes('Alice'));
  assert.ok(html.includes('alice@rehearsalbox.test'));
  assert.ok(html.includes('Musicien'));
  assert.ok(html.includes('Rock'));
  assert.ok(html.includes('data-user-id="5"'));
});

test('renderUserCard escapes every user-supplied text to prevent XSS', () => {
  const html = renderUserCard(
    { ...alice, displayName: '<script>alert(1)</script>', email: '"><img src=x onerror=alert(2)>', groups: [{ id: 2, name: '<b onmouseover=alert(3)>' }] },
    { currentUserId: 1 },
  );

  assert.ok(!html.includes('<script>'));
  assert.ok(!html.includes('<img src=x'));
  assert.ok(!html.includes('<b onmouseover'));
  assert.ok(html.includes('&lt;script&gt;'));
});

test('renderUserCard proposes deactivation for other accounts but never for the current user', () => {
  assert.ok(renderUserCard(alice, { currentUserId: 1 }).includes('data-deactivate-user-button data-user-id="5"'));
  assert.ok(!renderUserCard(alice, { currentUserId: 5 }).includes('data-deactivate-user-button'));
});

test('renderUserCard shows the Désactivé badge and a reactivation button for an inactive account', () => {
  const html = renderUserCard({ ...alice, isActive: false }, { currentUserId: 1 });

  assert.ok(html.includes('Désactivé'));
  assert.ok(html.includes('data-activate-user-button data-user-id="5"'));
  assert.ok(!html.includes('data-deactivate-user-button'));
});

test('renderUserCard shows the Verrouillé badge and an unlock button only for a locked account', () => {
  const locked = renderUserCard({ ...alice, isLocked: true }, { currentUserId: 1 });

  assert.ok(locked.includes('Verrouillé'));
  assert.ok(locked.includes('data-unlock-user-button data-user-id="5"'));
  assert.ok(!renderUserCard(alice, { currentUserId: 1 }).includes('data-unlock-user-button'));
});

test('renderUserCard labels an administrator', () => {
  assert.ok(renderUserCard({ ...alice, role: 'admin' }, { currentUserId: 1 }).includes('Administrateur'));
});

test('renderUserList renders one card per account and an empty string for no account', () => {
  const html = renderUserList([alice, { ...alice, id: 6, displayName: 'Bob' }], 1);

  assert.equal((html.match(/data-user-card/g) || []).length, 2);
  assert.equal(renderUserList([], 1), '');
});
