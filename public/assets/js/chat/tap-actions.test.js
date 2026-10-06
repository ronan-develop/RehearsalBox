import { test } from 'node:test';
import assert from 'node:assert/strict';
import { wireTapActions, ACTIVE_CLASS } from './tap-actions.js';

/** Mini DOM : un élément connaît son parent ; closest/contains suivent la chaîne. */
function el(selectors = [], parent = null) {
  const classes = new Set();
  const node = {
    parent,
    selectors,
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c) },
    closest(sel) {
      for (let n = this; n; n = n.parent) {
        if (n.selectors.includes(sel)) return n;
      }
      return null;
    },
    contains(other) {
      for (let n = other; n; n = n.parent) {
        if (n === this) return true;
      }
      return false;
    },
  };
  return node;
}

function makeDom({ selection = '' } = {}) {
  const listeners = { root: {}, doc: {} };
  const list = { addEventListener: (e, cb) => { listeners.root[e] = cb; } };
  const doc = { addEventListener: (e, cb) => { listeners.doc[e] = cb; } };
  const win = { getSelection: () => ({ toString: () => selection }) };
  const row1 = el(['.rb-chat-message[data-message-id]']);
  const bubble1 = el(['.rb-chat-bubble'], row1);
  const text1 = el([], bubble1);
  const link1 = el(['a, button, .rb-chat-quote, .rb-chat-mention'], bubble1);
  const action1 = el(['[data-quote-message], [data-edit-message]'], row1);
  const row2 = el(['.rb-chat-message[data-message-id]']);
  const bubble2 = el(['.rb-chat-bubble'], row2);
  const text2 = el([], bubble2);
  wireTapActions(list, { doc, win });
  return { listeners, row1, row2, text1, text2, link1, action1, win, doc, list };
}

const tap = (d, target) => d.listeners.root.click({ target });

test('tapping a bubble reveals its actions, tapping it again hides them', () => {
  const d = makeDom();

  tap(d, d.text1);
  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), true);

  tap(d, d.text1);
  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
});

test('only one bubble shows its actions at a time', () => {
  const d = makeDom();

  tap(d, d.text1);
  tap(d, d.text2);

  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
  assert.equal(d.row2.classList.contains(ACTIVE_CLASS), true);
});

test('a tap on a link, a mention or a quote inside the bubble toggles nothing', () => {
  const d = makeDom();

  tap(d, d.link1);

  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
});

test('a tap while text is selected toggles nothing (copying stays possible)', () => {
  const d = makeDom({ selection: 'du texte copié' });

  tap(d, d.text1);

  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
});

test('a tap outside the open bubble, a scroll or Escape hides the actions', () => {
  const d = makeDom();
  const outside = el([]);

  tap(d, d.text1);
  d.listeners.doc.click({ target: outside });
  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);

  tap(d, d.text1);
  d.listeners.root.scroll();
  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);

  tap(d, d.text1);
  d.listeners.doc.keydown({ key: 'Escape' });
  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
});

test('choosing an action closes the actions (the action itself is handled elsewhere)', () => {
  const d = makeDom();

  tap(d, d.text1);
  tap(d, d.action1);

  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), false);
});

test('a tap inside the open row (not outside) does not close it through the document listener', () => {
  const d = makeDom();

  tap(d, d.text1);
  d.listeners.doc.click({ target: d.text1 });

  assert.equal(d.row1.classList.contains(ACTIVE_CLASS), true);
});
