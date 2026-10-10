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

const allGroups = [{ id: 1, name: 'Rock' }, { id: 2, name: '<script>alert(1)</script> L\'Orchestre' }];

/** Valeur d'un attribut HTML (échappé) décodée, pour lire le JSON tel que le navigateur le voit. */
function decodedAttr(html, name) {
  const match = html.match(new RegExp(` ${name}='([^']*)'`));
  assert.ok(match, `attribut ${name} absent`);
  return match[1]
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

test('renderUserCard adds an edit button and a folded edit panel on every card', () => {
  const html = renderUserCard(alice, { currentUserId: 1, allGroups });

  assert.ok(html.includes('data-edit-user-button data-user-id="5"'));
  assert.ok(html.includes('<div class="rb-user-edit" data-user-edit hidden>'));
  assert.ok(html.includes('<form data-user-identity-form data-user-id="5">'));
  assert.ok(html.includes('data-user-edit-error role="alert" hidden'));
  assert.ok(html.includes('maxlength="100"'));
});

test('renderUserCard disables the e-mail and hides the role selector on the current user card', () => {
  const html = renderUserCard(alice, { currentUserId: 5, allGroups });

  assert.ok(/name="email"[^>]*disabled/.test(html));
  assert.ok(html.includes('Pour modifier votre propre adresse, utilisez Mon compte.'));
  assert.ok(!html.includes('data-user-role-select'));
  assert.ok(!html.includes('data-user-role-save'));
});

test('renderUserCard keeps the e-mail editable and shows the current role on other cards', () => {
  const html = renderUserCard(alice, { currentUserId: 1, allGroups });

  assert.ok(!/name="email"[^>]*disabled/.test(html));
  assert.ok(html.includes('data-user-role-select data-user-id="5"'));
  assert.ok(html.includes('<option value="musicien" selected>'));
  assert.ok(!html.includes('<option value="admin" selected>'));
  assert.ok(html.includes('data-user-role-save'));
  assert.ok(renderUserCard({ ...alice, role: 'admin' }, { currentUserId: 1, allGroups }).includes('<option value="admin" selected>'));
});

test('renderUserCard passes the account groups and all groups as escaped JSON attributes', () => {
  const trapped = { ...alice, groups: [{ id: 2, name: allGroups[1].name, role: 'gestionnaire' }] };
  const html = renderUserCard(trapped, { currentUserId: 1, allGroups });

  assert.ok(!html.includes('<script>alert'));
  assert.deepEqual(JSON.parse(decodedAttr(html, 'groups')), trapped.groups);
  assert.deepEqual(JSON.parse(decodedAttr(html, 'all-groups')), allGroups);
  assert.ok(html.includes('user-id="5"'));
});

test('renderUserCard renders an empty group list without any group button', () => {
  const html = renderUserCard({ ...alice, groups: [] }, { currentUserId: 1, allGroups });

  assert.deepEqual(JSON.parse(decodedAttr(html, 'groups')), []);
  assert.ok(!html.includes('undefined'));
  assert.ok(!html.includes('null'));
});
