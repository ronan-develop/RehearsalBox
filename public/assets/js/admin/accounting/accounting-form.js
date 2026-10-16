/**
 * Aides de saisie de la page « Comptabilité » (#362), sans DOM : catégories du sens choisi, écritures rapides, date du jour.
 * Rien ici ne décide de ce qui est valide : le serveur revérifie tout.
 */

/** Écritures rapides : sens, catégorie, moyen et libellé préremplis ; un montant seulement quand il est connu (cotisation de 20 €). */
export const PRESETS = {
  cotisation: { direction: 'recette', category: 'cotisations', paymentMethod: 'virement', label: 'Cotisation', amount: '20,00' },
  'droit-entree': { direction: 'recette', category: 'droits_entree', paymentMethod: 'virement', label: 'Droit d\'entrée', amount: '' },
  loyer: { direction: 'depense', category: 'loyer', paymentMethod: 'virement', label: 'Loyer du local', amount: '' },
  electricite: { direction: 'depense', category: 'electricite', paymentMethod: 'virement', label: 'Électricité', amount: '' },
  assurance: { direction: 'depense', category: 'assurances', paymentMethod: 'virement', label: 'Assurance', amount: '' },
  'frais-bancaires': { direction: 'depense', category: 'frais_bancaires', paymentMethod: 'virement', label: 'Frais bancaires', amount: '' },
};

/** @returns {{ direction: string, category: string, paymentMethod: string, label: string, amount: string } | null} */
export function presetValues(key) {
  return Object.hasOwn(PRESETS, key) ? { ...PRESETS[key] } : null;
}

/** Les catégories d'un sens, dans l'ordre de la liste fournie par le serveur. */
export function categoriesFor(direction, categories) {
  return categories.filter((category) => category.direction === direction);
}

export function isVoucherRequired(direction) {
  return direction === 'depense';
}

/** Date locale de la machine au format ISO (jj/mm sans fuseau : pas de décalage d'un jour le soir). */
export function todayIso(date = new Date()) {
  const two = (n) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())}`;
}
