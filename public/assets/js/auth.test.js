import { test } from 'node:test';
import assert from 'node:assert/strict';
import { revealConfirmation, isPasswordResetAnnouncement, applyProfileResult } from './auth.js';

function fakeForm() {
  const confirmation = {
    hidden: true,
    removeAttribute(name) {
      if (name === 'hidden') this.hidden = false;
    },
  };
  const container = { querySelector: (selector) => (selector === '[data-confirmation]' ? confirmation : null) };
  return { form: { hidden: false, parentElement: container }, confirmation };
}

test('revealConfirmation hides the form and shows the confirmation message', () => {
  const { form, confirmation } = fakeForm();

  revealConfirmation(form);

  assert.equal(form.hidden, true);
  assert.equal(confirmation.hidden, false);
});

test('revealConfirmation tolerates a page without confirmation block', () => {
  const form = { hidden: false, parentElement: { querySelector: () => null } };

  revealConfirmation(form);

  assert.equal(form.hidden, true);
});

test('isPasswordResetAnnouncement is true only for ?reset=1', () => {
  assert.equal(isPasswordResetAnnouncement('?reset=1'), true);
  assert.equal(isPasswordResetAnnouncement('?foo=bar&reset=1'), true);
  assert.equal(isPasswordResetAnnouncement(''), false);
  assert.equal(isPasswordResetAnnouncement('?reset=0'), false);
  assert.equal(isPasswordResetAnnouncement('?reset=true'), false);
});

test('applyProfileResult puts the name normalized by the server back in the field and returns the confirmation message', () => {
  const input = { value: '  Alice Martin  ' };
  const form = { querySelector: (selector) => (selector === '[name="displayName"]' ? input : null) };

  const message = applyProfileResult(form, { displayName: 'Alice Martin' });

  assert.equal(input.value, 'Alice Martin');
  assert.equal(message, 'Nom mis à jour.');
});

test('applyProfileResult leaves the field alone when the response carries no usable name', () => {
  const input = { value: 'Alice' };
  const form = { querySelector: () => input };

  applyProfileResult(form, {});
  applyProfileResult(form, null);
  applyProfileResult(form, { displayName: 42 });

  assert.equal(input.value, 'Alice');
});

test('applyProfileResult tolerates a form without the name field', () => {
  assert.doesNotThrow(() => applyProfileResult({ querySelector: () => null }, { displayName: 'Alice' }));
});
