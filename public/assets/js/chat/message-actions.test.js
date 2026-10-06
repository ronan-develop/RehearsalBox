import { test } from 'node:test';
import assert from 'node:assert/strict';
import { actionsFor, tappedRow } from './message-actions.js';

test('the quote goes on the RIGHT of the bubble of someone else', () => {
  assert.deepEqual(actionsFor({ mine: false, editable: false }), { side: 'right', keep: 'quote' });
});

test('on my recent bubble the pencil goes on the LEFT, and it is the only action', () => {
  assert.deepEqual(actionsFor({ mine: true, editable: true }), { side: 'left', keep: 'edit' });
});

test('on my bubble that can no longer be edited the quote goes on the left, so nothing is lost', () => {
  assert.deepEqual(actionsFor({ mine: true, editable: false }), { side: 'left', keep: 'quote' });
});

/** Mini DOM : un élément connaît son parent ; closest suit la chaîne. */
function el(selectors = [], parent = null) {
  return {
    parent,
    closest(sel) {
      for (let n = this; n; n = n.parent) {
        if (n.selectors?.includes(sel)) return n;
      }
      return null;
    },
    selectors,
  };
}

function bubbleDom() {
  const row = el(['.rb-chat-message[data-message-id]']);
  const bubble = el(['.rb-chat-bubble'], row);
  const text = el([], bubble);
  const link = el(['a, button, .rb-chat-quote, .rb-chat-mention'], bubble);
  const action = el(['[data-quote-message], [data-edit-message]'], row);

  return { row, text, link, action };
}

const touch = (target) => ({ target, pointerType: 'touch' });
const noSelection = { getSelection: () => ({ toString: () => '' }) };

test('a touch tap on the text of a bubble designates its row', () => {
  const { row, text } = bubbleDom();

  assert.equal(tappedRow(touch(text), noSelection), row);
});

test('a pen tap counts, a mouse click does not (hover devices keep their hover buttons)', () => {
  const { row, text } = bubbleDom();

  assert.equal(tappedRow({ target: text, pointerType: 'pen' }, noSelection), row);
  assert.equal(tappedRow({ target: text, pointerType: 'mouse' }, noSelection), null);
});

test('a tap on a link, a mention, a quote or a button inside the bubble designates nothing', () => {
  const { link } = bubbleDom();

  assert.equal(tappedRow(touch(link), noSelection), null);
});

test('a tap on an action button designates nothing (the button acts through its own click)', () => {
  const { action } = bubbleDom();

  assert.equal(tappedRow(touch(action), noSelection), null);
});

test('a tap while text is selected designates nothing: copying stays possible', () => {
  const { text } = bubbleDom();

  assert.equal(tappedRow(touch(text), { getSelection: () => ({ toString: () => 'du texte copié' }) }), null);
});

test('a tap outside any bubble designates nothing', () => {
  assert.equal(tappedRow(touch(el([])), noSelection), null);
});
