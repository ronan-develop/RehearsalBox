import { test } from 'node:test';
import assert from 'node:assert/strict';
import { getCurrentGroupId, handleRespond, handleRequestSubmit, handleCancel, handleUpdateSubmit } from './availability.js';

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

function fakeDocument() {
  const fakeElement = () => ({
    classList: { add() {} },
    style: {},
    appendChild() {},
    remove() {},
  });

  return {
    querySelector: () => null,
    createElement: fakeElement,
    body: { appendChild() {} },
  };
}

function fakeButton(exceptionId, accepted) {
  return { dataset: { exceptionId, accepted: String(accepted) } };
}

function fakeRootWithCard() {
  const removed = [];
  return {
    root: {
      querySelector: (selector) => {
        const match = /\[data-exception-id="(.+)"\]/.exec(selector);
        if (match) {
          return { remove: () => removed.push(match[1]) };
        }
        return null;
      },
    },
    removed,
  };
}

test('handleRespond posts accepted=true and removes the card on success', async () => {
  globalThis.fetch = async (url, options) => {
    assert.equal(url, '/api/availability/7/respond');
    assert.equal(JSON.parse(options.body).accepted, true);
    return { ok: true, json: async () => ({ id: 7, status: 'acceptee' }) };
  };
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleRespond(fakeButton('7', true), root);

  assert.deepEqual(removed, ['7']);
});

test('handleRespond removes the card on 409 (already responded)', async () => {
  globalThis.fetch = async () => ({
    ok: false,
    status: 409,
    json: async () => ({ error: 'Cette demande a déjà reçu une réponse.' }),
  });
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleRespond(fakeButton('9', false), root);

  assert.deepEqual(removed, ['9']);
});

test('handleRequestSubmit prevents native submit and posts the form as JSON', async () => {
  let calledUrl;
  let calledBody;
  globalThis.fetch = async (url, options) => {
    calledUrl = url;
    calledBody = JSON.parse(options.body);
    return { ok: true, json: async () => ({ id: 1, status: 'en_attente' }) };
  };
  globalThis.document = fakeDocument();

  let prevented = false;
  const RealFormData = globalThis.FormData;
  const formData = new RealFormData();
  formData.append('recurringSlotId', '3');
  formData.append('occurrenceDate', '2026-08-04');
  formData.append('requestingGroupId', '5');
  formData.append('reason', 'Concert samedi');

  const event = {
    preventDefault: () => {
      prevented = true;
    },
    target: {
      reset: () => {},
    },
  };
  globalThis.FormData = function FakeFormData() {
    return formData;
  };

  await handleRequestSubmit(event, fakeRoot());
  globalThis.FormData = RealFormData;

  assert.equal(prevented, true);
  assert.equal(calledUrl, '/api/availability/request');
  assert.deepEqual(calledBody, {
    recurringSlotId: 3,
    occurrenceDate: '2026-08-04',
    requestingGroupId: 5,
    reason: 'Concert samedi',
  });
});

function fakeCancelButton(exceptionId) {
  return { dataset: { exceptionId } };
}

test('handleCancel sends DELETE and removes the card on success', async () => {
  let calledUrl;
  let calledMethod;
  globalThis.fetch = async (url, options) => {
    calledUrl = url;
    calledMethod = options.method;
    return { ok: true, json: async () => ({}) };
  };
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleCancel(fakeCancelButton('12'), root);

  assert.equal(calledUrl, '/api/availability/12');
  assert.equal(calledMethod, 'DELETE');
  assert.deepEqual(removed, ['12']);
});

test('handleCancel removes the card on 409 (already responded)', async () => {
  globalThis.fetch = async () => ({
    ok: false,
    status: 409,
    json: async () => ({ error: 'Cette demande a déjà été traitée.' }),
  });
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleCancel(fakeCancelButton('13'), root);

  assert.deepEqual(removed, ['13']);
});

test('handleUpdateSubmit prevents native submit and PATCHes the form as JSON', async () => {
  let calledUrl;
  let calledMethod;
  let calledBody;
  globalThis.fetch = async (url, options) => {
    calledUrl = url;
    calledMethod = options.method;
    calledBody = JSON.parse(options.body);
    return { ok: true, json: async () => ({ id: 12, status: 'en_attente' }) };
  };
  globalThis.document = fakeDocument();

  let prevented = false;

  const RealFormData = globalThis.FormData;
  const formData = new RealFormData();
  formData.append('occurrenceDate', '2026-08-11');
  formData.append('reason', 'Raison modifiée');

  const event = {
    preventDefault: () => {
      prevented = true;
    },
    target: {
      reset: () => {},
      dataset: { exceptionId: '12' },
    },
  };
  globalThis.FormData = function FakeFormData() {
    return formData;
  };

  await handleUpdateSubmit(event, fakeRoot());
  globalThis.FormData = RealFormData;

  assert.equal(prevented, true);
  assert.equal(calledUrl, '/api/availability/12');
  assert.equal(calledMethod, 'PATCH');
  assert.deepEqual(calledBody, {
    occurrenceDate: '2026-08-11',
    reason: 'Raison modifiée',
  });
});
