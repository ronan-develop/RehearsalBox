import test from 'node:test';
import assert from 'node:assert/strict';
import { clearErrors, failureMessage, reportFailure, setMessage, submitForm } from './form-feedback.js';

function fakeForm(selectors) {
  const targets = Object.fromEntries(selectors.map((selector) => [selector, { textContent: 'ancien', hidden: false }]));
  const buttons = [{ disabled: false }, { disabled: false }];

  return { targets, buttons, querySelector: (selector) => targets[selector] ?? null, querySelectorAll: () => buttons };
}

test('un message vide masque l\'emplacement, un message le montre', () => {
  const form = fakeForm(['[data-form-error]']);
  setMessage(form, '[data-form-error]', 'Oups');
  assert.deepEqual(form.targets['[data-form-error]'], { textContent: 'Oups', hidden: false });
  setMessage(form, '[data-form-error]', '');
  assert.equal(form.targets['[data-form-error]'].hidden, true);
  setMessage(form, '[inconnu]', 'x');
});

test('clearErrors vide chaque champ et le message général', () => {
  const form = fakeForm(['[data-field-error="label"]', '[data-form-error]']);
  clearErrors(form, ['label']);
  assert.equal(form.targets['[data-field-error="label"]'].hidden, true);
  assert.equal(form.targets['[data-form-error]'].hidden, true);
});

test('les erreurs par champ remplacent le message général ; sans elles, le message général', () => {
  const form = fakeForm(['[data-field-error="label"]', '[data-field-error="amount"]', '[data-form-error]']);
  reportFailure(form, ['label', 'amount'], { fields: { label: 'Obligatoire' } }, () => 'Général');
  assert.equal(form.targets['[data-field-error="label"]'].textContent, 'Obligatoire');
  assert.equal(form.targets['[data-field-error="amount"]'].hidden, true);
  assert.equal(form.targets['[data-form-error]'].hidden, true);
  reportFailure(form, ['label'], { status: 500 }, () => 'Général');
  assert.equal(form.targets['[data-form-error]'].textContent, 'Général');
  assert.equal(form.targets['[data-form-error]'].hidden, false);
});

test('un envoi réussi désactive les boutons, appelle la réussite avec la réponse et ne les réactive pas (la page change)', async () => {
  const form = fakeForm(['[data-form-error]']);
  let seen = null;
  await submitForm(form, [], () => ({ path: '/x', options: { method: 'POST' } }), (done) => { seen = done; }, async (path, options) => ({ path, options, ok: true }));
  assert.deepEqual(seen, { path: '/x', options: { method: 'POST' }, ok: true });
  assert.deepEqual(form.buttons.map((b) => b.disabled), [true, true]);
});

test('un échec réactive les boutons et affiche l\'erreur sans appeler la réussite', async () => {
  const form = fakeForm(['[data-field-error="label"]', '[data-form-error]']);
  let called = false;
  await submitForm(form, ['label'], () => ({ path: '/x', options: {} }), () => { called = true; }, async () => { throw { fields: { label: 'Trop long' } }; });
  assert.equal(called, false);
  assert.deepEqual(form.buttons.map((b) => b.disabled), [false, false]);
  assert.equal(form.targets['[data-field-error="label"]'].textContent, 'Trop long');
});

test('une construction de requête qui lève (identifiant invalide) est affichée comme un échec, sans appel réseau', async () => {
  const form = fakeForm(['[data-form-error]']);
  let network = false;
  await submitForm(form, [], () => { throw new Error('Identifiant invalide.'); }, () => {}, async () => { network = true; });
  assert.equal(network, false);
  assert.equal(form.targets['[data-form-error]'].textContent, 'Identifiant invalide.');
  assert.deepEqual(form.buttons.map((b) => b.disabled), [false, false]);
});

test('le message général est celui du serveur, sinon un texte neutre', () => {
  assert.equal(failureMessage({ message: 'Exercice clos.' }), 'Exercice clos.');
  assert.equal(failureMessage({ message: '' }), 'Une erreur est survenue.');
  assert.equal(failureMessage(null), 'Une erreur est survenue.');
});
