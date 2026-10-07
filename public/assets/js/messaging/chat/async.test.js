import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sleep, whenVisible, isAbort } from './async.js';

test('sleep resolves after the delay', async () => {
  const started = Date.now();

  await sleep(30);

  assert.ok(Date.now() - started >= 25);
});

test('sleep rejects at once with an AbortError when its signal is aborted', async () => {
  const controller = new AbortController();
  const waiting = sleep(10_000, controller.signal);

  controller.abort();

  await assert.rejects(waiting, (error) => isAbort(error));
});

test('sleep rejects immediately if the signal is already aborted', async () => {
  const controller = new AbortController();
  controller.abort();

  await assert.rejects(sleep(10_000, controller.signal), (error) => isAbort(error));
});

test('isAbort only recognises AbortError', () => {
  assert.equal(isAbort(new DOMException('x', 'AbortError')), true);
  assert.equal(isAbort(new Error('boom')), false);
  assert.equal(isAbort(null), false);
});

function fakeDocument(hidden) {
  const target = new EventTarget();
  target.hidden = hidden;

  return target;
}

test('whenVisible resolves at once when the page is visible', async () => {
  await whenVisible(fakeDocument(false));
});

test('whenVisible waits for the page to become visible again', async () => {
  const doc = fakeDocument(true);
  let resolved = false;
  const waiting = whenVisible(doc).then(() => { resolved = true; });

  await sleep(10);
  assert.equal(resolved, false);
  doc.hidden = false;
  doc.dispatchEvent(new Event('visibilitychange'));
  await waiting;

  assert.equal(resolved, true);
});

test('whenVisible stops waiting when aborted', async () => {
  const doc = fakeDocument(true);
  const controller = new AbortController();
  const waiting = whenVisible(doc, controller.signal);

  controller.abort();

  await assert.rejects(waiting, (error) => isAbort(error));
});
