import { test } from 'node:test';
import assert from 'node:assert/strict';
import { planQuery, planLines, primaryAction, stepRequest, summarize } from './booking-plan.js';

const OFFICE = { kind: 'fixed', slotId: 7, groupName: 'The Office', own: false, startTime: '18:30', endTime: '19:00' };
const planWith = (freeParts, conflicts) => ({ fullyFree: conflicts.length === 0, freeParts, conflicts });

test('the plan query needs a group, a date and a start before an end, all in the right format', () => {
  assert.equal(planQuery({ groupId: '3', date: '2026-10-07', start: '09:00', end: '19:00' }), 'groupId=3&bookingDate=2026-10-07&startTime=09%3A00&endTime=19%3A00');
  for (const bad of [
    { groupId: '', date: '2026-10-07', start: '09:00', end: '19:00' },
    { groupId: '3', date: '', start: '09:00', end: '19:00' },
    { groupId: '3', date: '2026-10-07', start: '', end: '19:00' },
    { groupId: '3', date: '2026-10-07', start: '19:00', end: '09:00' },
    { groupId: '3', date: '2026-10-07', start: '09:00', end: '09:00' },
    { groupId: 'abc', date: '2026-10-07', start: '09:00', end: '19:00' },
    { groupId: '3', date: '07/10/2026', start: '09:00', end: '19:00' },
    {},
  ]) {
    assert.equal(planQuery(bad), null, JSON.stringify(bad));
  }
});

test('the lines tell, in the order of the day, what is free and what overlaps whom', () => {
  const lines = planLines(planWith([{ startTime: '09:00', endTime: '18:30' }], [OFFICE]));

  assert.deepEqual(lines, [
    { tone: 'free', text: 'Libre : 09:00 – 18:30' },
    { tone: 'request', text: 'Chevauche le créneau de The Office : 18:30 – 19:00 (une demande est possible)' },
  ]);
});

test('the groups own slot and an existing booking are reported without offering a request', () => {
  const lines = planLines(planWith([], [
    { kind: 'fixed', slotId: 1, groupName: 'Alpha', own: true, startTime: '14:00', endTime: '16:00' },
    { kind: 'booking', slotId: null, groupName: 'Beta', own: false, startTime: '10:00', endTime: '12:00' },
    { kind: 'booking', slotId: null, groupName: 'Alpha', own: true, startTime: '17:00', endTime: '18:00' },
  ]));

  assert.deepEqual(lines.map((l) => l.text), [
    'Déjà réservé par Beta : 10:00 – 12:00',
    'Votre groupe a déjà ce créneau fixe : 14:00 – 16:00',
    'Déjà réservé par votre groupe : 17:00 – 18:00',
  ]);
  assert.ok(lines.every((l) => l.tone === 'info'));
});

test('the main action matches the plan: all free, free plus request, request only, nothing to do', () => {
  const free = primaryAction(planWith([{ startTime: '09:00', endTime: '19:00' }], []));
  assert.equal(free.label, 'Réserver 09:00 – 19:00');
  assert.deepEqual(free.steps, [{ type: 'booking', startTime: '09:00', endTime: '19:00' }]);

  const both = primaryAction(planWith([{ startTime: '09:00', endTime: '18:30' }], [OFFICE]));
  assert.equal(both.label, 'Réserver la partie libre et demander le reste');
  assert.deepEqual(both.steps, [
    { type: 'booking', startTime: '09:00', endTime: '18:30' },
    { type: 'request', slotId: 7, groupName: 'The Office', startTime: '18:30', endTime: '19:00' },
  ]);

  const onlyRequest = primaryAction(planWith([], [{ ...OFFICE, startTime: '19:00', endTime: '21:00' }]));
  assert.equal(onlyRequest.label, 'Demander à The Office');
  assert.deepEqual(onlyRequest.steps, [{ type: 'request', slotId: 7, groupName: 'The Office', startTime: '19:00', endTime: '21:00' }]);

  assert.equal(primaryAction(planWith([], [{ kind: 'booking', slotId: null, groupName: 'Beta', own: false, startTime: '10:00', endTime: '12:00' }])), null, 'rien de possible');
  assert.equal(primaryAction(null), null);
  assert.equal(primaryAction({}), null);
});

test('several free parts or several requests are named in the plural and keep the order of the day', () => {
  const many = primaryAction(planWith(
    [{ startTime: '09:00', endTime: '12:00' }, { startTime: '14:00', endTime: '18:30' }],
    [{ ...OFFICE, slotId: 5, startTime: '12:00', endTime: '14:00' }, OFFICE],
  ));

  assert.equal(many.label, 'Réserver les parties libres et demander le reste');
  assert.deepEqual(many.steps.map((s) => s.type), ['booking', 'booking', 'request', 'request'], 'les réservations d\'abord, puis les demandes');
  assert.equal(primaryAction(planWith([{ startTime: '09:00', endTime: '12:00' }, { startTime: '14:00', endTime: '18:00' }], [])).label, 'Réserver les parties libres');
  assert.equal(primaryAction(planWith([], [OFFICE, { ...OFFICE, slotId: 9, groupName: 'Beta' }])).label, 'Envoyer les demandes');
});

test('each step becomes the right API call with the date and the optional reason', () => {
  const ctx = { groupId: '3', date: '2026-10-07', reason: ' Enregistrement ' };

  assert.deepEqual(stepRequest({ type: 'booking', startTime: '09:00', endTime: '18:30' }, ctx), {
    path: '/api/bookings',
    options: { method: 'POST', body: JSON.stringify({ groupId: 3, bookingDate: '2026-10-07', startTime: '09:00', endTime: '18:30', reason: 'Enregistrement' }) },
  });
  assert.deepEqual(stepRequest({ type: 'request', slotId: 7, groupName: 'The Office', startTime: '18:30', endTime: '19:00' }, { ...ctx, reason: '' }), {
    path: '/api/availability',
    options: { method: 'POST', body: JSON.stringify({ recurringSlotId: 7, groupId: 3, occurrenceDate: '2026-10-07', startTime: '18:30', endTime: '19:00' }) },
  });
  assert.throws(() => stepRequest({ type: 'booking', startTime: '09:00', endTime: '10:00' }, { groupId: 'abc', date: '2026-10-07' }), /identifiant/i);
  assert.throws(() => stepRequest({ type: 'delete' }, ctx), /étape/i);
});

test('the summary says exactly what was sent and what failed, step by step', () => {
  const booking = { type: 'booking', startTime: '09:00', endTime: '18:30' };
  const request = { type: 'request', slotId: 7, groupName: 'The Office', startTime: '18:30', endTime: '19:00' };

  const allOk = summarize([{ step: booking, ok: true }, { step: request, ok: true }]);
  assert.equal(allOk.ok, true);
  assert.deepEqual(allOk.lines, [
    'Réservation 09:00 – 18:30 : envoyée, elle attend la validation d’un administrateur.',
    'Demande à The Office 18:30 – 19:00 : envoyée, le groupe titulaire doit répondre.',
  ]);

  const partial = summarize([{ step: booking, ok: true }, { step: request, ok: false, error: { message: 'Cette date est déjà demandée.' } }]);
  assert.equal(partial.ok, false);
  assert.equal(partial.anySent, true);
  assert.equal(partial.lines[1], 'Demande à The Office 18:30 – 19:00 : non envoyée — Cette date est déjà demandée.');

  const none = summarize([{ step: booking, ok: false, error: null }]);
  assert.equal(none.anySent, false);
  assert.equal(none.lines[0], 'Réservation 09:00 – 18:30 : non envoyée — Une erreur est survenue.');
  assert.deepEqual(summarize([]), { ok: true, anySent: false, lines: [] });
});
