/**
 * Créneaux exceptionnels du tableau de bord : rechargement après l'acceptation d'une demande (#79). Les cartes sont dessinées
 * par le serveur (le même gabarit que la page, #243) : le navigateur n'en connaît pas le balisage, il insère le HTML reçu.
 * Aucun défilement automatique (#201) : le carrousel de bureau se parcourt au doigt ou à la molette, la liste mobile se lit
 * simplement.
 */
import { apiFetch } from './api.js';
import { initTornPaper } from './tornpaper-init.js';

/**
 * Recharge uniquement le carrousel des créneaux exceptionnels depuis /api/planning/exceptional après une acceptation réussie
 * (availability.js::handleRespond) : le planning fixe n'est pas affecté par une acceptation.
 */
export async function refreshExceptionalPlanning(root = document) {
  const track = root.querySelector('[data-planning-track-exceptional]');
  if (!track) {
    return;
  }

  const { html, count } = await apiFetch('/api/planning/exceptional');
  track.innerHTML = html;
  initTornPaper(track);

  const counter = root.querySelector('[data-planning-tab-count]');
  if (counter) {
    counter.textContent = String(count);
  }

  // Vide : masquée sur bureau (CSS), message « aucun créneau » sur mobile.
  root.querySelector('[data-exceptional-planning-section]')?.classList.toggle('rb-planning-section--empty', count === 0);
}
