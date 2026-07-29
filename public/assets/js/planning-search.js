/**
 * Filtre client des cartes du planning (#67) : toutes les données étant
 * déjà présentes dans le DOM au chargement (rb-planning-card), un filtre
 * purement JS suffit — pas de nouvel appel réseau ni de repository dédié.
 */
const WEEKDAY_LABELS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

export function matchesPlanningSearch(card, rawQuery) {
  const query = rawQuery.trim().toLowerCase();
  if (query === '') {
    return true;
  }

  const groupName = (card.groupName ?? '').toLowerCase();
  const weekdayLabel = WEEKDAY_LABELS[Number(card.weekday)] ?? '';

  return groupName.includes(query) || weekdayLabel.includes(query);
}

export function initPlanningSearch(doc = document) {
  const input = doc.querySelector('[data-planning-search]');
  if (!input) {
    return;
  }

  input.addEventListener('input', () => {
    const cards = doc.querySelectorAll('.rb-planning-card');
    cards.forEach((card) => {
      const matches = matchesPlanningSearch(
        { weekday: card.dataset.weekday, groupName: card.dataset.contactGroupName },
        input.value,
      );
      card.classList.toggle('rb-planning-card--hidden', !matches);
    });
  });
}
