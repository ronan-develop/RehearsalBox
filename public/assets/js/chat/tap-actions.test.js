import { test } from 'node:test';
import assert from 'node:assert/strict';
import { wireTapActions } from './tap-actions.js';

/** Mini DOM : un élément connaît son parent ; closest/contains/append/remove suivent la chaîne. */
function el(selectors = [], parent = null, { classes = [], attrs = {} } = {}) {
  const classSet = new Set(classes);
  const node = {
    parent,
    selectors,
    children: [],
    dataset: {},
    attrs: { ...attrs },
    classList: { contains: (c) => classSet.has(c) },
    hasAttribute: (n) => n in node.attrs,
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
    append(child) {
      child.parent = node;
      node.children.push(child);
    },
    remove() {
      if (node.parent) node.parent.children = node.parent.children.filter((c) => c !== node);
      node.parent = null;
    },
  };
  return node;
}

/** <template data-message-actions> : cloneNode(true) renvoie un fragment dont le premier enfant est <rb-message-actions>. */
function makeTemplate() {
  return {
    content: {
      cloneNode: () => {
        const actions = el(['rb-message-actions']);
        const quote = el(['[data-quote-message]'], actions);
        const edit = el(['[data-edit-message]'], actions);
        quote.selectors.push('[data-quote-message], [data-edit-message]');
        edit.selectors.push('[data-quote-message], [data-edit-message]');
        actions.children.push(quote, edit);
        actions.querySelector = (sel) => actions.children.find((c) => c.selectors.includes(sel)) ?? null;
        return { firstElementChild: actions };
      },
    },
  };
}

function makeRow({ mine = false, editable = false } = {}) {
  const row = el(['.rb-chat-message[data-message-id]'], null, { classes: mine ? ['rb-chat-message--mine'] : [], attrs: editable ? { 'data-editable': '' } : {} });
  const bubble = el(['.rb-chat-bubble'], row);
  const text = el([], bubble);
  const link = el(['a, button, .rb-chat-quote, .rb-chat-mention'], bubble);
  return { row, text, link };
}

function makeDom({ selection = '' } = {}) {
  const listeners = { root: {}, doc: {} };
  const root = { addEventListener: (e, cb) => { listeners.root[e] = cb; } };
  const template = makeTemplate();
  const doc = { addEventListener: (e, cb) => { listeners.doc[e] = cb; }, querySelector: (sel) => (sel === 'template[data-message-actions]' ? template : null) };
  const win = { getSelection: () => ({ toString: () => selection }) };
  wireTapActions(root, { doc, win });

  return { listeners, doc };
}

/** Un tap : évènement `pointerup` tactile (fiable sur iOS, contrairement à `click` sur une zone non interactive). */
const tap = (d, target, pointerType = 'touch') => d.listeners.root.pointerup({ target, pointerType });
/** Le bouton d'action est un vrai <button> : son `click` fonctionne partout. */
const clickAction = (d, target) => d.listeners.root.click({ target });
const actionsOf = (row) => row.children.find((c) => c.selectors.includes('rb-message-actions')) ?? null;
const buttonsOf = (actions) => actions.children.map((c) => (c.selectors.includes('[data-quote-message]') ? 'quote' : 'edit'));

test('tapping the bubble of someone else inserts the actions component on its RIGHT with the quote button only', () => {
  const d = makeDom();
  const { row, text } = makeRow({ mine: false });

  tap(d, text);

  const actions = actionsOf(row);
  assert.ok(actions, 'composant inséré dans la ligne');
  assert.equal(actions.dataset.side, 'right');
  assert.deepEqual(buttonsOf(actions), ['quote']);
});

test('tapping my recent bubble inserts the component on its LEFT with the edit button only', () => {
  const d = makeDom();
  const { row, text } = makeRow({ mine: true, editable: true });

  tap(d, text);

  const actions = actionsOf(row);
  assert.equal(actions.dataset.side, 'left');
  assert.deepEqual(buttonsOf(actions), ['edit']);
});

test('tapping my bubble that can no longer be edited offers the quote on its left', () => {
  const d = makeDom();
  const { row, text } = makeRow({ mine: true, editable: false });

  tap(d, text);

  const actions = actionsOf(row);
  assert.equal(actions.dataset.side, 'left');
  assert.deepEqual(buttonsOf(actions), ['quote']);
});

test('tapping the same bubble again removes the component from the DOM', () => {
  const d = makeDom();
  const { row, text } = makeRow();

  tap(d, text);
  tap(d, text);

  assert.equal(actionsOf(row), null);
});

test('only one bubble has its component at a time', () => {
  const d = makeDom();
  const a = makeRow();
  const b = makeRow();

  tap(d, a.text);
  tap(d, b.text);

  assert.equal(actionsOf(a.row), null);
  assert.ok(actionsOf(b.row));
});

test('a tap on a link, a mention or a quote inside the bubble inserts nothing', () => {
  const d = makeDom();
  const { row, link } = makeRow();

  tap(d, link);

  assert.equal(actionsOf(row), null);
});

test('a tap while text is selected inserts nothing (copying stays possible)', () => {
  const d = makeDom({ selection: 'du texte copié' });
  const { row, text } = makeRow();

  tap(d, text);

  assert.equal(actionsOf(row), null);
});

test('a tap outside, a scroll or Escape removes the component', () => {
  const d = makeDom();
  const { row, text } = makeRow();
  const outside = el([]);

  tap(d, text);
  d.listeners.doc.pointerup({ target: outside, pointerType: 'touch' });
  assert.equal(actionsOf(row), null);

  tap(d, text);
  d.listeners.root.scroll();
  assert.equal(actionsOf(row), null);

  tap(d, text);
  d.listeners.doc.keydown({ key: 'Escape' });
  assert.equal(actionsOf(row), null);
});

test('choosing an action removes the component (the action itself is handled elsewhere)', () => {
  const d = makeDom();
  const { row, text } = makeRow({ mine: true, editable: true });

  tap(d, text);
  clickAction(d, actionsOf(row).children[0]);

  assert.equal(actionsOf(row), null);
});

test('a tap inside the open row does not close it through the document listener', () => {
  const d = makeDom();
  const { row, text } = makeRow();

  tap(d, text);
  d.listeners.doc.pointerup({ target: text, pointerType: 'touch' });

  assert.ok(actionsOf(row));
});

test('without the server template nothing is wired and nothing breaks', () => {
  const listeners = {};
  const root = { addEventListener: (e, cb) => { listeners[e] = cb; } };
  const doc = { addEventListener: () => {}, querySelector: () => null };

  assert.doesNotThrow(() => wireTapActions(root, { doc, win: { getSelection: () => null } }));
  const { text } = makeRow();
  assert.doesNotThrow(() => listeners.pointerup?.({ target: text, pointerType: 'touch' }));
});

test('a mouse pointer never opens the component: hover devices keep their hover buttons', () => {
  const d = makeDom();
  const { row, text } = makeRow();

  tap(d, text, 'mouse');

  assert.equal(actionsOf(row), null);
});

test('an action button used with the mouse or keyboard is not affected by the tap logic', () => {
  const d = makeDom();
  const { row, text } = makeRow({ mine: true, editable: true });

  tap(d, text);
  tap(d, actionsOf(row).children[0]); // pointerup sur le bouton lui-même : ne ferme pas avant son propre click

  assert.ok(actionsOf(row), 'le pointerup sur le bouton ne referme pas : c\'est son click qui agit puis ferme');
});
