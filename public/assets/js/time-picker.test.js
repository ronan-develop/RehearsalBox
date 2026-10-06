import { test } from 'node:test';
import assert from 'node:assert/strict';
import { combine, minutesFor, STEP_MINUTES } from './time-picker.js';

test('a time needs both an hour and a minute', () => {
  assert.equal(combine('09', '15'), '09:15');
  assert.equal(combine('', '15'), '');
  assert.equal(combine('09', ''), '');
  assert.equal(combine('', ''), '');
});

test('the minutes are the quarter hours', () => {
  assert.equal(STEP_MINUTES, 15);
  assert.deepEqual(minutesFor('10', '00:00', '23:45'), ['00', '15', '30', '45']);
});

test('without an hour yet, every quarter hour is offered', () => {
  assert.deepEqual(minutesFor('', '00:15', '23:30'), ['00', '15', '30', '45']);
});

test('the first hour is trimmed by the earliest time and the last by the latest', () => {
  assert.deepEqual(minutesFor('00', '00:15', '23:30'), ['15', '30', '45'], 'une fin à 00:00 n\'a pas de sens');
  assert.deepEqual(minutesFor('23', '00:15', '23:30'), ['00', '15', '30'], 'pas de fin après 23:30');
  assert.deepEqual(minutesFor('23', '00:00', '23:45'), ['00', '15', '30', '45']);
});

test('an hour outside the bounds leaves nothing to choose', () => {
  assert.deepEqual(minutesFor('05', '08:00', '20:00'), []);
  assert.deepEqual(minutesFor('21', '08:00', '20:00'), []);
});
