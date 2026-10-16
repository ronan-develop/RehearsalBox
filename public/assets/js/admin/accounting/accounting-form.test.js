import test from 'node:test';
import assert from 'node:assert/strict';
import { PRESETS, categoriesFor, isVoucherRequired, presetValues, todayIso } from './accounting-form.js';

const CATEGORIES = [
  { value: 'cotisations', label: 'Cotisations', direction: 'recette' },
  { value: 'loyer', label: 'Loyer', direction: 'depense' },
  { value: 'electricite', label: 'Électricité', direction: 'depense' },
];

test('les catégories proposées suivent le sens choisi', () => {
  assert.deepEqual(categoriesFor('depense', CATEGORIES).map((c) => c.value), ['loyer', 'electricite']);
  assert.deepEqual(categoriesFor('recette', CATEGORIES).map((c) => c.value), ['cotisations']);
  assert.deepEqual(categoriesFor('autre', CATEGORIES), []);
});

test('la pièce justificative n\'est obligatoire que pour une dépense', () => {
  assert.equal(isVoucherRequired('depense'), true);
  assert.equal(isVoucherRequired('recette'), false);
  assert.equal(isVoucherRequired(''), false);
});

test('les écritures rapides préremplissent sens, catégorie, moyen et libellé ; le montant seulement quand il est connu', () => {
  assert.deepEqual(presetValues('cotisation'), { direction: 'recette', category: 'cotisations', paymentMethod: 'virement', label: 'Cotisation', amount: '20,00' });
  assert.equal(presetValues('loyer').direction, 'depense');
  assert.equal(presetValues('loyer').amount, '', 'le montant du loyer n\'est pas supposé');
  assert.equal(presetValues('electricite').amount, '');
  assert.equal(presetValues('inconnu'), null);
  assert.equal(presetValues('__proto__'), null);
});

test('chaque écriture rapide a une catégorie du bon sens', () => {
  for (const [key, preset] of Object.entries(PRESETS)) {
    assert.ok(['recette', 'depense'].includes(preset.direction), key);
    assert.ok(preset.label.length > 0 && preset.label.length <= 150, key);
    assert.match(preset.amount, /^(\d{1,9}(,\d{2})?)?$/, `${key} : montant en euros à la française ou vide`);
  }
});

test('la date du jour est celle de la machine, au format ISO, avec zéros', () => {
  assert.equal(todayIso(new Date(2026, 0, 5, 23, 59)), '2026-01-05');
  assert.equal(todayIso(new Date(2026, 11, 31, 0, 1)), '2026-12-31');
});
