import { test } from 'node:test';
import assert from 'node:assert/strict';
import { getCurrentGroupId } from './availability.js';

function fakeRoot(selectValue) {
  return {
    querySelector: (selector) => {
      if (selector === '[data-current-group-select]' && selectValue !== undefined) {
        return { value: selectValue };
      }
      return null;
    },
  };
}

test('getCurrentGroupId reads the value from the group select when present', () => {
  const result = getCurrentGroupId(fakeRoot('42'));

  assert.equal(result, '42');
});

test('getCurrentGroupId returns undefined when no group select is present', () => {
  const result = getCurrentGroupId(fakeRoot());

  assert.equal(result, undefined);
});
