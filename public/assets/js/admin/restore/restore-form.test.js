import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  RESTORE_BUSY_MESSAGE,
  RESTORE_DONE_MESSAGE,
  RESTORE_GENERIC_ERROR,
  buildRestoreRequest,
  restoreFailureMessage,
  validateRestoreForm,
} from './restore-form.js';

const WORD = 'RESTAURER';

test('validateRestoreForm accepts a password and the exact confirmation word', () => {
  assert.deepEqual(validateRestoreForm({ password: 'secret', confirmation: 'RESTAURER' }, WORD), {});
});

test('validateRestoreForm refuses an empty password', () => {
  const errors = validateRestoreForm({ password: '', confirmation: 'RESTAURER' }, WORD);

  assert.equal(typeof errors.password, 'string');
  assert.equal(errors.confirmation, undefined);
});

test('validateRestoreForm refuses a confirmation that is empty', () => {
  const errors = validateRestoreForm({ password: 'secret', confirmation: '' }, WORD);

  assert.equal(typeof errors.confirmation, 'string');
  assert.equal(errors.password, undefined);
});

test('validateRestoreForm refuses a confirmation that differs in case', () => {
  assert.equal(typeof validateRestoreForm({ password: 'secret', confirmation: 'restaurer' }, WORD).confirmation, 'string');
});

test('validateRestoreForm refuses a confirmation with extra spaces (no trimming)', () => {
  assert.equal(typeof validateRestoreForm({ password: 'secret', confirmation: ' RESTAURER' }, WORD).confirmation, 'string');
});

test('validateRestoreForm refuses a wrong confirmation and names the expected word', () => {
  const errors = validateRestoreForm({ password: 'secret', confirmation: 'oui' }, WORD);

  assert.ok(errors.confirmation.includes(WORD));
});

test('validateRestoreForm reports both fields when both are wrong', () => {
  const errors = validateRestoreForm({ password: '', confirmation: '' }, WORD);

  assert.deepEqual(Object.keys(errors), ['password', 'confirmation']);
});

test('buildRestoreRequest builds the JSON body expected by the endpoint', () => {
  assert.deepEqual(
    buildRestoreRequest({ file: 'db-20261019030000.sql.gz', password: 'secret', confirmation: 'RESTAURER' }),
    { file: 'db-20261019030000.sql.gz', password: 'secret', confirmation: 'RESTAURER' },
  );
});

test('restoreFailureMessage: 409 means a restoration is already running', () => {
  assert.equal(restoreFailureMessage({ status: 409, message: 'Une restauration est déjà en cours.', fields: {} }), RESTORE_BUSY_MESSAGE);
  assert.equal(RESTORE_BUSY_MESSAGE, 'Une restauration est déjà en cours.');
});

test('restoreFailureMessage: 422 shows the password field error (password or currentPassword)', () => {
  assert.equal(restoreFailureMessage({ status: 422, message: 'Validation échouée', fields: { password: 'Mot de passe requis.' } }), 'Mot de passe requis.');
  assert.equal(restoreFailureMessage({ status: 422, message: 'Validation échouée', fields: { currentPassword: 'Mot de passe incorrect.' } }), 'Mot de passe incorrect.');
});

test('restoreFailureMessage: 422 shows the confirmation field error', () => {
  assert.equal(restoreFailureMessage({ status: 422, message: 'Validation échouée', fields: { confirmation: 'Saisir RESTAURER.' } }), 'Saisir RESTAURER.');
});

test('restoreFailureMessage: 422 shows the file field error', () => {
  assert.equal(restoreFailureMessage({ status: 422, message: 'Validation échouée', fields: { file: 'Sauvegarde introuvable.' } }), 'Sauvegarde introuvable.');
});

test('restoreFailureMessage: 422 without a known field falls back to the error text', () => {
  assert.equal(restoreFailureMessage({ status: 422, message: 'Compte verrouillé.', fields: {} }), 'Compte verrouillé.');
});

test('restoreFailureMessage: any other status (500) gives the generic message, without technical detail', () => {
  assert.equal(restoreFailureMessage({ status: 500, message: 'PDOException: SQLSTATE[HY000] secret', fields: {} }), RESTORE_GENERIC_ERROR);
});

test('restoreFailureMessage: a network error (no status) gives the generic message', () => {
  assert.equal(restoreFailureMessage(new TypeError('Failed to fetch')), RESTORE_GENERIC_ERROR);
  assert.equal(restoreFailureMessage(undefined), RESTORE_GENERIC_ERROR);
});

test('the done message tells the owner to reconnect and reload', () => {
  assert.equal(
    RESTORE_DONE_MESSAGE,
    "Restauration lancée. L'application peut être indisponible quelques instants : reconnectez-vous puis rechargez cette page pour voir le résultat.",
  );
});
