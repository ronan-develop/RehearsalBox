import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initTornPaper } from './tornpaper-init.js';

function fakeCard() {
  return { style: {} };
}

function fakeDocumentWithCards(cards) {
  return {
    querySelectorAll: (selector) => (selector === '.rb-planning-card' ? cards : []),
  };
}

test('initTornPaper does nothing when there are no planning cards', () => {
  const doc = fakeDocumentWithCards([]);
  let callCount = 0;
  const createFilter = () => {
    callCount += 1;
    return 'unused';
  };

  initTornPaper(doc, createFilter);

  assert.equal(callCount, 0);
});

test('initTornPaper applies a distinct filter to each planning card', () => {
  const cards = [fakeCard(), fakeCard(), fakeCard()];
  const doc = fakeDocumentWithCards(cards);
  const createFilter = ({ filterName }) => filterName;

  initTornPaper(doc, createFilter);

  assert.equal(cards[0].style.filter, 'url(#tornpaper-card-0)');
  assert.equal(cards[1].style.filter, 'url(#tornpaper-card-1)');
  assert.equal(cards[2].style.filter, 'url(#tornpaper-card-2)');
});

test('initTornPaper passes torn-edge tuning parameters to the filter factory', () => {
  const cards = [fakeCard()];
  const doc = fakeDocumentWithCards(cards);
  let receivedOptions;
  const createFilter = (options) => {
    receivedOptions = options;
    return 'tornpaper-card-0';
  };

  initTornPaper(doc, createFilter);

  assert.equal(receivedOptions.tornFrequency, 0.045);
  assert.equal(receivedOptions.tornScale, 9);
  assert.equal(receivedOptions.grungeFrequency, 0.04);
  assert.equal(receivedOptions.grungeScale, 2);
});

test('initTornPaper leaves the cards flat on a phone: the mobile planning is a plain list (#201)', () => {
  const cards = [fakeCard(), fakeCard()];
  let callCount = 0;

  initTornPaper(fakeDocumentWithCards(cards), () => { callCount += 1; return 'x'; }, () => false);

  assert.equal(callCount, 0);
  assert.equal(cards[0].style.filter, undefined);
});
