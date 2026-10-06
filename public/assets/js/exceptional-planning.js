/**
 * Créneaux exceptionnels du tableau de bord : construction des cartes et rechargement après l'acceptation d'une demande (#79).
 * Aucun défilement automatique (#201) : le carrousel de bureau se parcourt au doigt ou à la molette, la liste mobile se lit
 * simplement.
 */
import { apiFetch } from './api.js';
import { initTornPaper } from './tornpaper-init.js';
import { escapeHtml } from './html.js';
import { WEEKDAY_LABELS } from './weekdays.js';

function formatTime(time) {
  return time.slice(0, 5);
}

function formatOccurrenceDate(isoDate) {
  const [year, month, day] = isoDate.split('-');
  return `${day}/${month}/${year}`;
}

/**
 * Miroir JS de $renderExceptionalCard (templates/dashboard/index.php) —
 * utilisé par refreshExceptionalPlanning() pour reconstruire le slider de
 * créneaux exceptionnels sans reload après acceptation d'une demande (#79).
 * Cartes non cliquables (#81) : pas de role/tabindex/data-contact-group-*.
 */
export function buildExceptionalCardMarkup(requestableSlot) {
  const groupName = escapeHtml(requestableSlot.groupName);
  const weekdayLabel = WEEKDAY_LABELS[requestableSlot.weekday];
  const occurrenceDate = requestableSlot.occurrenceDate ? formatOccurrenceDate(requestableSlot.occurrenceDate) : '';

  return `
    <article class="rb-planning-card rb-planning-card--exceptional">
      <span class="rb-badge" aria-hidden="true">Occasionnel</span>
      <h4 class="rb-planning-card-group">${groupName}</h4>
      <p class="rb-planning-card-when"><span class="rb-planning-card-weekday">${escapeHtml(weekdayLabel)}</span> <span class="rb-planning-card-date">${escapeHtml(occurrenceDate)}</span></p>
      <p class="rb-planning-card-time">${escapeHtml(formatTime(requestableSlot.startTime))} – ${escapeHtml(formatTime(requestableSlot.endTime))}</p>
    </article>
  `;
}

/**
 * Recharge uniquement le slider de créneaux exceptionnels depuis
 * /api/planning après une acceptation réussie (availability.js::handleRespond)
 * — le planning fixe n'est pas affecté par une acceptation, pas besoin de
 * le reconstruire (et data-current-user-group-role n'est pas exposé par
 * l'API, donc pas reconstructible fidèlement côté client).
 */
export async function refreshExceptionalPlanning(root = document) {
  const track = root.querySelector('[data-planning-track-exceptional]');
  if (!track) {
    return;
  }

  const data = await apiFetch('/api/planning');
  track.innerHTML = data.occasionalSlots.map(buildExceptionalCardMarkup).join('');
  initTornPaper(track);

  const count = root.querySelector('[data-planning-tab-count]');
  if (count) {
    count.textContent = String(data.occasionalSlots.length);
  }

  // Vide : masquée sur bureau (CSS), message « aucun créneau » sur mobile.
  root.querySelector('[data-exceptional-planning-section]')?.classList.toggle('rb-planning-section--empty', data.occasionalSlots.length === 0);
}

