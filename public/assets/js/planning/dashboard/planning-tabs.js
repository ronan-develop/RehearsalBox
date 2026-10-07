/**
 * Onglets Planning | Exceptionnels du tableau de bord (#201). Seul le mobile (< 768 px) n'en affiche qu'un à la fois : le CSS
 * masque les panneaux inactifs sous 768 px ; sur bureau les deux sections restent visibles. Sans JavaScript, tout est visible
 * (l'attribut data-planning-tabs-ready n'est posé qu'ici). Aucune donnée n'est modifiée : le dernier onglet est seulement mémorisé
 * sur l'appareil, sans effet s'il ne peut pas l'être.
 */
const STORAGE_KEY = 'rb-planning-tab';
const PANELS = ['regular', 'exceptional'];

function readStored(storage) {
  try {
    const value = storage?.getItem(STORAGE_KEY);
    return PANELS.includes(value) ? value : null;
  } catch {
    return null;
  }
}

function writeStored(storage, value) {
  try {
    storage?.setItem(STORAGE_KEY, value);
  } catch {
    // Mémorisation facultative.
  }
}

export function initPlanningTabs(root = document, storage = globalThis.localStorage) {
  const container = root.querySelector('[data-planning-tabs]');
  if (!container) {
    return;
  }

  const tabs = Array.from(container.querySelectorAll('[data-planning-tab]'));
  const panels = Array.from(container.querySelectorAll('[data-planning-panel]'));

  const activate = (name) => {
    tabs.forEach((tab) => tab.setAttribute('aria-selected', tab.dataset.planningTab === name ? 'true' : 'false'));
    panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.planningPanel === name));
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      activate(tab.dataset.planningTab);
      writeStored(storage, tab.dataset.planningTab);
    });
  });

  activate(readStored(storage) ?? 'regular');
  container.setAttribute('data-planning-tabs-ready', '');
}
