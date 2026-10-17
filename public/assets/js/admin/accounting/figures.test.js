import test from 'node:test';
import assert from 'node:assert/strict';
import { mergeFigures } from './figures.js';

test('un rapport vide reçoit les chiffres tels quels', () => {
  assert.equal(mergeFigures('', 'Recettes : 20,00 €'), 'Recettes : 20,00 €');
  assert.equal(mergeFigures('   \n', 'Recettes : 20,00 €'), 'Recettes : 20,00 €');
});

test('la saisie existante est conservée : les chiffres s\'ajoutent après une ligne vide', () => {
  assert.equal(mergeFigures('Le trésorier présente les comptes.', 'Recettes : 20,00 €'), 'Le trésorier présente les comptes.\n\nRecettes : 20,00 €');
});

test('une seconde reprise ne duplique pas les chiffres', () => {
  const once = mergeFigures('Intro', 'Recettes : 20,00 €');
  assert.equal(mergeFigures(once, 'Recettes : 20,00 €'), once);
});

test('un texte reçu vide ne change rien', () => {
  assert.equal(mergeFigures('Intro', '  '), 'Intro');
});
