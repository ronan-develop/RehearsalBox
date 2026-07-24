import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController } from './planning-slider.js';

function makeFakeTrack(offsetWidth = 1000) {
  let translateX = 0;
  return {
    offsetWidth,
    style: {
      set transform(value) {
        const match = /translateX\((-?\d+(?:\.\d+)?)px\)/.exec(value);
        translateX = match ? Number(match[1]) : 0;
      },
      get transform() {
        return `translateX(${translateX}px)`;
      },
    },
    get translateX() {
      return translateX;
    },
  };
}

test('createAutoScrollController translates the track further left on each tick while running', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.tick();
  controller.tick();

  assert.equal(track.translateX, -4);
});

test('createAutoScrollController does not move the track while paused', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.tick();

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController resumes advancing after resume() following a pause()', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.resume();
  controller.tick();

  assert.equal(track.translateX, -2);
});

test('createAutoScrollController wraps back to 0 once past half the track width (duplicated content)', () => {
  // Le track contient le contenu dupliqué une fois (2x offsetWidth réel de la
  // liste source) pour boucler sans saut visible ; on doit donc revenir à 0
  // dès qu'on a défilé la moitié de offsetWidth, pas la totalité.
  const track = makeFakeTrack(1000);
  const controller = createAutoScrollController(track, { step: 2 });

  for (let i = 0; i < 250; i += 1) {
    controller.tick();
  }

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController starts running by default', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController pause() is idempotent under concurrent mouseenter/manual pause', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.pause();
  controller.tick();

  assert.equal(track.translateX, 0);
  assert.equal(controller.isRunning(), false);
});
