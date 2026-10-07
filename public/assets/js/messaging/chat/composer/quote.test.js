import { test } from 'node:test';
import assert from 'node:assert/strict';
import { quoteExcerpt, quoteFromRow, QUOTE_EXCERPT_LENGTH } from './quote.js';

test('the excerpt is one line: line breaks and runs of spaces collapse', () => {
  assert.equal(quoteExcerpt("Salut\n\n  tout   le monde\r\n"), 'Salut tout le monde');
});

test('a long text is cut at a hundred characters with an ellipsis, like the server does', () => {
  assert.equal(QUOTE_EXCERPT_LENGTH, 100);
  assert.equal(quoteExcerpt('a'.repeat(100) + 'SUITE'), 'a'.repeat(100) + '…');
  assert.equal(quoteExcerpt('court'), 'court');
});

test('the cut never splits a character made of several UTF-16 units', () => {
  const text = '😀'.repeat(150);

  assert.equal(quoteExcerpt(text), '😀'.repeat(100) + '…');
});

const row = (dataset, text) => ({
  dataset,
  querySelector: (selector) => (selector === '.rb-chat-text' && text !== null ? { textContent: text } : null),
});

test('a quote request is read from the message row: its id, its author and its text', () => {
  assert.deepEqual(
    quoteFromRow(row({ messageId: '25', author: 'Bob' }, 'Jeudi à 20h ?')),
    { id: '25', author: 'Bob', text: 'Jeudi à 20h ?' },
  );
});

test('a row that cannot be quoted gives nothing: no id, no author or no text', () => {
  assert.equal(quoteFromRow(row({ author: 'Bob' }, 'texte')), null);
  assert.equal(quoteFromRow(row({ messageId: '25' }, 'texte')), null);
  assert.equal(quoteFromRow(row({ messageId: '25', author: 'Bob' }, null)), null);
  assert.equal(quoteFromRow(null), null);
});
