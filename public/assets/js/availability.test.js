import { test } from 'node:test';
import assert from 'node:assert/strict';
import { getCurrentGroupId, handleRespond, handleCancel, handleUpdateSubmit } from './availability.js';

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

function fakeButton(exceptionId, accepted, occurrenceDate = '2026-10-20') {
  return { dataset: { exceptionId, accepted: String(accepted), occurrenceDate } };
}

function fakeRootWithCard() {
  const removed = [];
  return {
    root: {
      querySelector: (selector) => {
        const match = /\[data-exception-id="(.+)"\]/.exec(selector);
        if (match) {
          return { remove: () => removed.push(match[1]), closest: () => null };
        }
        if (selector === '[data-planning-track-exceptional]') {
          return { innerHTML: '', querySelectorAll: () => [] };
        }
        return null;
      },
    },
    removed,
  };
}

test('handleRespond posts accepted=true and removes the card on success', async () => {
  globalThis.fetch = async (url, options) => {
    if (url === '/api/planning') {
      return { ok: true, json: async () => ({ fixedSlots: [], occasionalSlots: [] }) };
    }
    assert.equal(url, '/api/availability/7/respond');
    assert.equal(JSON.parse(options.body).accepted, true);
    return { ok: true, json: async () => ({ id: 7, status: 'acceptee' }) };
  };
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleRespond(fakeButton('7', true), root);

  assert.deepEqual(removed, ['7']);
});

test('handleRespond refreshes the exceptional planning slider after a successful acceptance', async () => {
  let planningFetched = false;
  globalThis.fetch = async (url) => {
    if (url === '/api/planning') {
      planningFetched = true;
      return { ok: true, json: async () => ({ fixedSlots: [], occasionalSlots: [] }) };
    }
    return { ok: true, json: async () => ({ id: 7, status: 'acceptee' }) };
  };
  globalThis.document = fakeDocument();

  const { root } = fakeRootWithCard();
  await handleRespond(fakeButton('7', true), root);

  assert.equal(planningFetched, true);
});

test('handleRespond does not refresh the exceptional planning slider on refusal', async () => {
  let planningFetched = false;
  globalThis.fetch = async (url) => {
    if (url === '/api/planning') {
      planningFetched = true;
      return { ok: true, json: async () => ({ fixedSlots: [], occasionalSlots: [] }) };
    }
    return { ok: true, json: async () => ({ id: 7, status: 'refusee' }) };
  };
  globalThis.document = fakeDocument();

  const { root } = fakeRootWithCard();
  await handleRespond(fakeButton('7', false), root);

  assert.equal(planningFetched, false);
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

test('handleCancel renumbers the deck and reveals the empty state when it was the last card', async () => {
  globalThis.fetch = async () => ({ ok: true, json: async () => ({}) });
  globalThis.document = fakeDocument();

  const emptyState = { removeAttribute: () => { emptyState.hiddenRemoved = true; }, hiddenRemoved: false };
  const deck = {
    querySelectorAll: () => [],
    querySelector: (selector) => (selector === '.rb-exception-empty' ? emptyState : null),
  };
  const card = { remove: () => {}, closest: (selector) => (selector === '[data-exception-deck]' ? deck : null) };
  const root = { querySelector: () => card };

  await handleCancel(fakeCancelButton('20'), root);

  assert.equal(emptyState.hiddenRemoved, true);
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

test('handleRespond sends the date the holder saw, so a date changed in the meantime cannot be accepted by mistake (#221)', async () => {
  let body = null;
  globalThis.fetch = async (url, options) => {
    if (url === '/api/planning') {
      return { ok: true, json: async () => ({ fixedSlots: [], occasionalSlots: [] }) };
    }
    body = JSON.parse(options.body);
    return { ok: true, json: async () => ({ id: 7, status: 'acceptee' }) };
  };
  globalThis.document = fakeDocument();

  const { root } = fakeRootWithCard();
  await handleRespond(fakeButton('7', true, '2026-10-20'), root);

  assert.deepEqual(body, { accepted: true, occurrenceDate: '2026-10-20' });
});

test('a request changed in the meantime answers 409: the card is removed so the holder reloads and sees the new date', async () => {
  globalThis.fetch = async () => ({ ok: false, status: 409, json: async () => ({ error: 'La demande a été modifiée.' }) });
  globalThis.document = fakeDocument();

  const { root, removed } = fakeRootWithCard();
  await handleRespond(fakeButton('7', true), root);

  assert.deepEqual(removed, ['7']);
});
