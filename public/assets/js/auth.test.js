import { test } from 'node:test';
import assert from 'node:assert/strict';
import { revealConfirmation, isPasswordResetAnnouncement } from './auth.js';

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
