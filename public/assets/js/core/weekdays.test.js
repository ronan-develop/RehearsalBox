import { test } from 'node:test';
import assert from 'node:assert/strict';
import { WEEKDAY_LABELS } from './weekdays.js';

test('weekday labels follow the server convention: 0 = Monday … 6 = Sunday', () => {
  assert.equal(WEEKDAY_LABELS.length, 7);
  assert.equal(WEEKDAY_LABELS[0], 'Lundi');
  assert.equal(WEEKDAY_LABELS[2], 'Mercredi');
  assert.equal(WEEKDAY_LABELS[6], 'Dimanche');
});
