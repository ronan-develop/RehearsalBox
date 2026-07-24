import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController, generateWrinkleStyle } from './planning-slider.js';

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

test('generateWrinkleStyle returns 3 angles and 3 positions', () => {
  const style = generateWrinkleStyle(() => 0.5);

  assert.equal(Object.keys(style).length, 6);
  assert.ok('--wrinkle-angle-1' in style);
  assert.ok('--wrinkle-angle-2' in style);
  assert.ok('--wrinkle-angle-3' in style);
  assert.ok('--wrinkle-pos-1' in style);
  assert.ok('--wrinkle-pos-2' in style);
  assert.ok('--wrinkle-pos-3' in style);
});

test('generateWrinkleStyle produces angles in degrees within 0-360', () => {
  const style = generateWrinkleStyle(() => 0.9);

  for (const key of ['--wrinkle-angle-1', '--wrinkle-angle-2', '--wrinkle-angle-3']) {
    const value = Number(style[key].replace('deg', ''));
    assert.ok(value >= 0 && value <= 360, `${key}=${style[key]} out of range`);
  }
});

test('generateWrinkleStyle produces percentage positions within 0-100', () => {
  const style = generateWrinkleStyle(() => 0.1);

  for (const key of ['--wrinkle-pos-1', '--wrinkle-pos-2', '--wrinkle-pos-3']) {
    const [x, y] = style[key].split(' ').map((v) => Number(v.replace('%', '')));
    assert.ok(x >= 0 && x <= 100, `${key} x=${x} out of range`);
    assert.ok(y >= 0 && y <= 100, `${key} y=${y} out of range`);
  }
});

test('generateWrinkleStyle differs between two different random sources', () => {
  let call = 0;
  const seqA = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6];
  const seqB = [0.9, 0.8, 0.7, 0.6, 0.5, 0.4];

  const styleA = generateWrinkleStyle(() => seqA[call++]);
  call = 0;
  const styleB = generateWrinkleStyle(() => seqB[call++]);

  assert.notDeepEqual(styleA, styleB);
});
