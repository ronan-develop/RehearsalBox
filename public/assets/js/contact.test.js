import { test } from 'node:test';
import assert from 'node:assert/strict';
import { openContactModal, closeContactModal, handleContactSubmit, initContact, handlePlanningCardActivation } from './contact.js';

function fakeOverlay() {
  const state = { hidden: true, titleText: '', groupIdInputValue: '', formReset: false };
  const elements = {
    '[data-contact-group-id-input]': { set value(v) { state.groupIdInputValue = v; }, get value() { return state.groupIdInputValue; } },
    '[data-contact-modal-title]': { set textContent(v) { state.titleText = v; }, get textContent() { return state.titleText; } },
    '[data-contact-form]': { reset: () => { state.formReset = true; } },
  };

  return {
    state,
    overlay: {
      get hidden() { return state.hidden; },
      set hidden(v) { state.hidden = v; },
      querySelector: (selector) => elements[selector] ?? null,
    },
  };
}

function fakeRoot(overlay) {
  return { querySelector: (selector) => (selector === '[data-contact-modal-overlay]' ? overlay : null) };
}

function fakeButton(groupId, groupName) {
  return { dataset: { contactGroupId: groupId, contactGroupName: groupName } };
}

test('openContactModal fills the group id/name and shows the overlay', () => {
  const { state, overlay } = fakeOverlay();
  const root = fakeRoot(overlay);

  openContactModal(fakeButton('7', 'Groupe Test'), root);

  assert.equal(state.hidden, false);
  assert.equal(state.groupIdInputValue, '7');
  assert.equal(state.titleText, 'Contacter Groupe Test');
});

test('closeContactModal hides the overlay and resets the form', () => {
  const { state, overlay } = fakeOverlay();
  overlay.hidden = false;
  const root = fakeRoot(overlay);

  closeContactModal(root);

  assert.equal(state.hidden, true);
  assert.equal(state.formReset, true);
});

test('handleContactSubmit posts the message and closes the modal on success', async () => {
  let calledUrl;
  let calledBody;
  globalThis.fetch = async (url, options) => {
    calledUrl = url;
    calledBody = JSON.parse(options.body);
    return { ok: true, json: async () => ({ status: 'ok' }) };
  };
  globalThis.document = {
    querySelector: () => null,
    createElement: () => ({ classList: { add() {} }, style: {}, appendChild() {}, remove() {} }),
    body: { appendChild() {} },
  };

  const { overlay } = fakeOverlay();
  const root = fakeRoot(overlay);

  let prevented = false;
  const RealFormData = globalThis.FormData;
  const formData = new RealFormData();
  formData.append('groupId', '7');
  formData.append('message', 'Bonjour, un échange possible ?');

  const event = {
    preventDefault: () => { prevented = true; },
    target: {},
  };
  globalThis.FormData = function FakeFormData() {
    return formData;
  };

  await handleContactSubmit(event, root);
  globalThis.FormData = RealFormData;

  assert.equal(prevented, true);
  assert.equal(calledUrl, '/api/groups/7/contact');
  assert.deepEqual(calledBody, { message: 'Bonjour, un échange possible ?' });
});

function fakeInitRoot(overlay) {
  const listeners = {};
  return {
    overlay,
    addEventListener: (type, handler) => {
      listeners[type] = handler;
    },
    dispatch: (type, event) => listeners[type]?.(event),
    querySelector: (selector) => (selector === '[data-contact-modal-overlay]' ? overlay : selector === '[data-contact-form]' ? null : null),
  };
}

test('initContact closes the modal when clicking the cancel button', () => {
  const { state, overlay } = fakeOverlay();
  overlay.hidden = false;
  const root = fakeInitRoot(overlay);
  initContact(root);

  const cancelButton = { closest: (selector) => (selector === '[data-contact-modal-cancel]' ? cancelButton : null), matches: () => false };
  root.dispatch('click', { target: cancelButton });

  assert.equal(state.hidden, true);
});

test('initContact closes the modal when clicking the overlay backdrop itself', () => {
  const { state, overlay } = fakeOverlay();
  overlay.hidden = false;
  const root = fakeInitRoot(overlay);
  initContact(root);

  const backdropTarget = { closest: () => null, matches: (selector) => selector === '[data-contact-modal-overlay]' };
  root.dispatch('click', { target: backdropTarget });

  assert.equal(state.hidden, true);
});

test('initContact closes the modal on Escape when it is open', () => {
  const { state, overlay } = fakeOverlay();
  overlay.hidden = false;
  const root = fakeInitRoot(overlay);
  initContact(root);

  root.dispatch('keydown', { key: 'Escape' });

  assert.equal(state.hidden, true);
});

test('initContact ignores Escape when the modal is already closed', () => {
  const { state, overlay } = fakeOverlay();
  overlay.hidden = true;
  const root = fakeInitRoot(overlay);
  initContact(root);

  root.dispatch('keydown', { key: 'Escape' });

  assert.equal(state.hidden, true);
});

test('initContact opens the modal when pressing Enter on a planning card', () => {
  const { state, overlay } = fakeOverlay();
  const root = fakeInitRoot(overlay);
  initContact(root);

  const card = { closest: (selector) => (selector === '[data-contact-group-id]' ? card : null), dataset: { contactGroupId: '9', contactGroupName: 'Groupe Clavier' } };
  let prevented = false;
  root.dispatch('keydown', { key: 'Enter', target: card, preventDefault: () => { prevented = true; } });

  assert.equal(state.hidden, false);
  assert.equal(state.groupIdInputValue, '9');
  assert.equal(prevented, true);
});

test('initContact opens the modal when pressing Space on a planning card', () => {
  const { state, overlay } = fakeOverlay();
  const root = fakeInitRoot(overlay);
  initContact(root);

  const card = { closest: (selector) => (selector === '[data-contact-group-id]' ? card : null), dataset: { contactGroupId: '9', contactGroupName: 'Groupe Clavier' } };
  root.dispatch('keydown', { key: ' ', target: card, preventDefault: () => {} });

  assert.equal(state.hidden, false);
});

test('handlePlanningCardActivation opens the contact modal when the user has no role on the group', () => {
  const { state, overlay } = fakeOverlay();
  const root = fakeRoot(overlay);
  const card = { dataset: { contactGroupId: '5', contactGroupName: 'Groupe Tiers' } };
  let navigated = null;

  handlePlanningCardActivation(card, root, (url) => { navigated = url; });

  assert.equal(state.hidden, false);
  assert.equal(navigated, null);
});

test('handlePlanningCardActivation navigates to the group space by slug when the user has a role on the group', () => {
  const { state, overlay } = fakeOverlay();
  const root = fakeRoot(overlay);
  const card = { dataset: { contactGroupId: '5', contactGroupName: 'Mon Groupe', contactGroupSlug: 'mon-groupe', currentUserGroupRole: 'membre' } };
  let navigated = null;

  handlePlanningCardActivation(card, root, (url) => { navigated = url; });

  assert.equal(navigated, '/groups/mon-groupe/space');
  assert.equal(state.hidden, true);
});

