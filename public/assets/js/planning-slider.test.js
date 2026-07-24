import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController } from './planning-slider.js';

function makeFakeTrack(scrollLeftStart = 0, scrollWidth = 1000, clientWidth = 300) {
  return {
    scrollLeft: scrollLeftStart,
    scrollWidth,
    clientWidth,
  };
}

test('createAutoScrollController advances scrollLeft on each tick while running', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.tick();
  controller.tick();

  assert.equal(track.scrollLeft, 4);
});

test('createAutoScrollController does not advance scrollLeft while paused', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.tick();

  assert.equal(track.scrollLeft, 0);
});

test('createAutoScrollController resumes advancing after resume() following a pause()', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.resume();
  controller.tick();

  assert.equal(track.scrollLeft, 2);
});

test('createAutoScrollController wraps scrollLeft back to 0 once past the scrollable end', () => {
  const track = makeFakeTrack(998, 1000, 300);
  const controller = createAutoScrollController(track, { step: 2 });

  controller.tick();

  assert.equal(track.scrollLeft, 0);
});

test('createAutoScrollController starts running by default', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  assert.equal(controller.isRunning(), true);
});
