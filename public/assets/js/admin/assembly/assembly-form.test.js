import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildPayload, locateFieldError, needsSaveBeforeValidate } from './assembly-form.js';

const HEADER = {
  revision: '3',
  kind: 'ordinaire',
  heldOn: '2026-10-01',
  location: 'Local',
  openedAt: '19:00',
  closedAt: '21:30',
  quorum: '1',
  board: { president: '4', tresorier: '5', secretaire: '7' },
  moralReport: 'Bilan moral',
  financialReport: 'Bilan financier',
  otherBusiness: 'Divers',
};
const EMPTY_ROWS = { attendees: [], agenda: [], resolutions: [] };

test('text is trimmed, blank becomes null, revision and identifiers become numbers', () => {
  const payload = buildPayload({ ...HEADER, revision: ' 3 ', location: '   ', moralReport: ' Bilan ', otherBusiness: '' }, EMPTY_ROWS);

  assert.equal(payload.revision, 3);
  assert.equal(payload.location, null);
  assert.equal(payload.moralReport, 'Bilan');
  assert.equal(payload.otherBusiness, null);
  assert.equal(payload.board.president, 4);
});

test('votes become integers, an empty vote counts as zero, and the outcome stays text', () => {
  const payload = buildPayload(HEADER, {
    ...EMPTY_ROWS,
    resolutions: [{ text: 'Achat de micros', for: '5', against: '', abstain: '2', outcome: 'adoptée' }],
  });

  assert.deepEqual(payload.resolutions, [
    { text: 'Achat de micros', for: 5, against: 0, abstain: 2, outcome: 'adoptée' },
  ]);
});

test('attendee user identifier becomes a number, or null when missing', () => {
  const payload = buildPayload(HEADER, {
    ...EMPTY_ROWS,
    attendees: [
      { userId: '', name: 'Invité', attendance: 'présent', proxyHolder: '' },
      { userId: '2', name: 'Léa', attendance: 'procuration', proxyHolder: ' Tom ' },
    ],
  });

  assert.deepEqual(payload.attendees, [
    { userId: null, name: 'Invité', attendance: 'présent', proxyHolder: null },
    { userId: 2, name: 'Léa', attendance: 'procuration', proxyHolder: 'Tom' },
  ]);
});

test('rows where every value is blank are ignored in every list', () => {
  const payload = buildPayload(HEADER, {
    attendees: [
      { userId: '', name: ' ', attendance: '', proxyHolder: '' },
      { userId: '2', name: 'Léa', attendance: '', proxyHolder: '' },
    ],
    agenda: [
      { title: '', discussion: '  ' },
      { title: 'Budget', discussion: '' },
    ],
    resolutions: [{ text: '', for: '', against: '', abstain: '', outcome: '' }],
  });

  assert.equal(payload.attendees.length, 1);
  assert.equal(payload.attendees[0].userId, 2);
  assert.deepEqual(payload.agenda, [{ title: 'Budget', discussion: null }]);
  assert.deepEqual(payload.resolutions, []);
});

test('quorum is true for 1, false for 0 and null when blank', () => {
  assert.equal(buildPayload({ ...HEADER, quorum: '1' }, EMPTY_ROWS).quorumReached, true);
  assert.equal(buildPayload({ ...HEADER, quorum: '0' }, EMPTY_ROWS).quorumReached, false);
  assert.equal(buildPayload({ ...HEADER, quorum: '' }, EMPTY_ROWS).quorumReached, null);
});

test('only the filled board posts are sent', () => {
  const partial = buildPayload({ ...HEADER, board: { president: '', tresorier: '9', secretaire: '  ' } }, EMPTY_ROWS);
  assert.deepEqual(partial.board, { tresorier: 9 });

  const none = buildPayload({ ...HEADER, board: { president: '', tresorier: '', secretaire: '' } }, EMPTY_ROWS);
  assert.deepEqual(none.board, {});
});

test('the payload has exactly the expected keys', () => {
  const payload = buildPayload(HEADER, EMPTY_ROWS);

  assert.deepEqual(Object.keys(payload).sort(), [
    'agenda', 'attendees', 'board', 'closedAt', 'financialReport', 'heldOn', 'kind', 'location',
    'moralReport', 'openedAt', 'otherBusiness', 'quorumReached', 'resolutions', 'revision',
  ]);
});

test('a plain key points at the header, a dotted key at a list row or a board post', () => {
  assert.deepEqual(locateFieldError('heldOn'), { section: 'header', index: null, field: 'heldOn' });
  assert.deepEqual(locateFieldError('attendees.2.name'), { section: 'attendees', index: 2, field: 'name' });
  assert.deepEqual(locateFieldError('board.tresorier'), { section: 'board', index: null, field: 'tresorier' });
});

test('a list name alone is a list-level error, and blank or non-string keys locate nothing', () => {
  assert.deepEqual(locateFieldError('attendees'), { section: 'attendees', index: null, field: null });
  assert.equal(locateFieldError(''), null);
  assert.equal(locateFieldError(undefined), null);
  assert.equal(locateFieldError(42), null);
});

test('a save is needed before validating only when the form is dirty', () => {
  assert.equal(needsSaveBeforeValidate(true), true);
  assert.equal(needsSaveBeforeValidate(false), false);
});

test('un texte non numérique n\'est jamais converti en silence : il part tel quel et le serveur le refuse', () => {
  const payload = buildPayload(
    { revision: '1', board: { president: 'abc' } },
    { attendees: [{ userId: 'x1', name: '', attendance: 'present', proxyHolder: '' }], agenda: [], resolutions: [{ text: 'T', for: 'trois', against: '', abstain: '', outcome: '' }] },
  );
  assert.equal(payload.board.president, 'abc');
  assert.equal(payload.attendees[0].userId, 'x1');
  assert.equal(payload.resolutions[0].for, 'trois');
  assert.equal(payload.resolutions[0].against, 0);
});
