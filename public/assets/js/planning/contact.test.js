import { test } from 'node:test';
import assert from 'node:assert/strict';
import { handlePlanningCardActivation, initContact } from './contact.js';

const card = (dataset) => ({ dataset, closest: (selector) => (selector === '[data-contact-group-id]' ? card2 : null) });
let card2;

function fakeRoot() {
  const listeners = {};

  return {
    addEventListener: (type, handler) => { listeners[type] = handler; },
    dispatch: (type, event) => listeners[type]?.(event),
  };
}

test('a card of a group the user belongs to opens the group space', () => {
  let navigated = null;

  handlePlanningCardActivation({ dataset: { contactGroupId: '5', contactGroupSlug: 'mon-groupe', currentUserGroupRole: 'membre' } }, (url) => { navigated = url; });

  assert.equal(navigated, '/groups/mon-groupe/space');
});

test('a card of another group opens a new conversation page (no modal)', () => {
  let navigated = null;

  handlePlanningCardActivation({ dataset: { contactGroupId: '5', contactGroupName: 'Groupe Tiers' } }, (url) => { navigated = url; });

  assert.equal(navigated, '/messages/new/5');
});

test('initContact navigates on click and on Enter / Space for a card', () => {
  const root = fakeRoot();
  const navigated = [];
  initContact(root, (url) => navigated.push(url));
  card2 = { dataset: { contactGroupId: '9', contactGroupName: 'Groupe Clavier' } };
  const target = card(card2.dataset);
  let prevented = 0;

  root.dispatch('click', { target });
  root.dispatch('keydown', { key: 'Enter', target, preventDefault: () => { prevented += 1; } });
  root.dispatch('keydown', { key: ' ', target, preventDefault: () => { prevented += 1; } });

  assert.deepEqual(navigated, ['/messages/new/9', '/messages/new/9', '/messages/new/9']);
  assert.equal(prevented, 2, 'Entrée et Espace ne font pas défiler la page');
});

test('initContact ignores clicks and keys elsewhere', () => {
  const root = fakeRoot();
  const navigated = [];
  initContact(root, (url) => navigated.push(url));

  root.dispatch('click', { target: { closest: () => null } });
  root.dispatch('keydown', { key: 'Enter', target: { closest: () => null }, preventDefault: () => {} });
  root.dispatch('keydown', { key: 'a', target: { closest: () => null }, preventDefault: () => {} });

  assert.deepEqual(navigated, []);
});
