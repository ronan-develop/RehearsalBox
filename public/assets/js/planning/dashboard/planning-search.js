import { WEEKDAY_LABELS } from '../../core/weekdays.js';

/**
 * Filtre client des cartes du planning (#67) : toutes les données étant
 * déjà présentes dans le DOM au chargement (rb-planning-card), un filtre
 * purement JS suffit — pas de nouvel appel réseau ni de repository dédié.
 */
export function matchesPlanningSearch(card, rawQuery) {
  const query = rawQuery.trim().toLowerCase();
  if (query === '') {
    return true;
  }

  const groupName = (card.groupName ?? '').toLowerCase();
  const weekdayLabel = (WEEKDAY_LABELS[Number(card.weekday)] ?? '').toLowerCase();

  return groupName.includes(query) || weekdayLabel.includes(query);
}

/**
 * Liste mobile groupée par jour (#201) : un titre de jour dont toutes les cartes sont filtrées ne doit pas rester seul à l'écran.
 * Le titre précède ses cartes dans la piste ; il est masqué quand aucune d'elles n'est visible.
 */
export function syncDayHeadings(track) {
  let heading = null;
  let visible = false;
  const close = () => {
    if (heading !== null) {
      heading.classList.toggle('rb-planning-day--hidden', !visible);
    }
  };

  Array.from(track.children).forEach((child) => {
    if (child.matches('.rb-planning-day')) {
      close();
      heading = child;
      visible = false;
    } else if (child.matches('.rb-planning-card') && !child.classList.contains('rb-planning-card--hidden')) {
      visible = true;
    }
  });
  close();
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
        { weekday: card.getAttribute('weekday'), groupName: card.getAttribute('group-name') },
        input.value,
      );
      card.classList.toggle('rb-planning-card--hidden', !matches);
    });
    const track = doc.querySelector('[data-planning-track]');
    if (track) {
      syncDayHeadings(track);
    }
  });
}
