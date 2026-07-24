import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildSlotPayload, renderSlotRow, WEEKDAY_LABELS } from './admin-slots.js';

test('buildSlotPayload converts form entries into a typed payload', () => {
  const payload = buildSlotPayload({
    groupId: '3',
    weekday: '1',
    startTime: '18:00:00',
    endTime: '20:00:00',
  });

  assert.deepEqual(payload, { groupId: 3, weekday: 1, startTime: '18:00:00', endTime: '20:00:00' });
});

test('renderSlotRow escapes nothing user-controlled but includes the weekday label', () => {
  const html = renderSlotRow({ id: 5, groupId: 1, weekday: 1, startTime: '18:00:00', endTime: '20:00:00' });

  assert.ok(html.includes(WEEKDAY_LABELS[1]));
  assert.ok(html.includes('data-slot-id="5"'));
});

test('renderSlotRow displays times as HH:MM, stripping seconds', () => {
  const html = renderSlotRow({ id: 5, groupId: 1, weekday: 1, startTime: '18:00:00', endTime: '20:00:00' });

  assert.ok(html.includes('18:00 – 20:00'));
  assert.ok(!html.includes('18:00:00'));
});

test('WEEKDAY_LABELS has exactly 7 entries matching the Weekday PHP enum', () => {
  assert.equal(WEEKDAY_LABELS.length, 7);
});
