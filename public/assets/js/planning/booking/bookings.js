/**
 * Page « Réserver le local » (#263) : calcule le plan à chaque saisie, exécute l'envoi « en un geste » (la partie libre PUIS les demandes,
 * chaque étape indépendante des autres) et annule une réservation. Les composants <rb-booking-form> et <rb-booking-item> ne parlent
 * jamais à l'API ; la logique pure est dans booking-plan.js. Après un envoi ou une annulation, la page est rechargée pour afficher
 * la liste à jour ; le bilan survit au rechargement (sessionStorage, facultatif).
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { cancelRequest, errorText, planLines, planQuery, primaryAction, stepRequest, summarize } from './booking-plan.js';
import { PLAN_REQUEST, SUBMIT } from './rb-booking-form.js';
import { CANCEL_EVENT } from './rb-booking-item.js';
import '../../ui/rb-time-picker.js';

const FLASH_KEY = 'rb-booking-flash';

function storeFlash(flash, storage = globalThis.sessionStorage) {
  try {
    storage?.setItem(FLASH_KEY, JSON.stringify(flash));
  } catch {
    // Le bilan est un confort : sans stockage, la page se recharge simplement.
  }
}

function takeFlash(storage = globalThis.sessionStorage) {
  try {
    const raw = storage?.getItem(FLASH_KEY);
    storage?.removeItem(FLASH_KEY);
    const flash = raw ? JSON.parse(raw) : null;

    return Array.isArray(flash?.lines) && flash.lines.every((line) => typeof line === 'string') ? flash : null;
  } catch {
    return null;
  }
}

export function initBookings(root = document) {
  const form = root.querySelector('rb-booking-form');
  const planning = { action: null, sequence: 0 };

  if (form !== null) {
    const flash = takeFlash();
    if (flash !== null) {
      form.showResult(flash.lines, flash.ok);
    }

    form.addEventListener(PLAN_REQUEST, async (event) => {
      const query = planQuery(event.detail);
      const sequence = ++planning.sequence;
      if (query === null) {
        planning.action = null;
        form.clearPlan();

        return;
      }
      try {
        const { plan } = await apiFetch(`/api/bookings/plan?${query}`);
        if (sequence !== planning.sequence) {
          return; // une saisie plus récente a déjà demandé un autre plan
        }
        planning.action = primaryAction(plan);
        form.showPlan({ lines: planLines(plan), action: planning.action });
      } catch (error) {
        if (sequence === planning.sequence) {
          planning.action = null;
          form.clearPlan();
          form.showError(errorText(error));
        }
      }
    });

    form.addEventListener(SUBMIT, async (event) => {
      const { action } = planning;
      if (action === null) {
        return;
      }
      form.setBusy(true);
      const results = [];
      for (const step of action.steps) {
        try {
          const { path, options } = stepRequest(step, event.detail);
          await apiFetch(path, options);
          results.push({ step, ok: true });
        } catch (error) {
          results.push({ step, ok: false, error: { message: errorText(error) } });
        }
      }
      const summary = summarize(results);
      form.setBusy(false);
      form.showResult(summary.lines, summary.ok);
      if (summary.anySent) {
        showToast(summary.ok ? 'Envoyé.' : 'Envoyé en partie : voir le détail.', summary.ok ? 'success' : 'info');
        storeFlash({ lines: summary.lines, ok: summary.ok });
        window.setTimeout(() => window.location.reload(), 1200);
      }
    });
  }

  root.addEventListener(CANCEL_EVENT, async (event) => {
    const item = event.target.closest('rb-booking-item');
    if (item === null) {
      return;
    }
    item.setBusy(true);
    try {
      const { path, options } = cancelRequest(event.detail.id);
      await apiFetch(path, options);
      showToast('Réservation annulée.', 'success');
      storeFlash({ lines: ['Réservation annulée.'], ok: true });
      window.location.reload();
    } catch (error) {
      item.setBusy(false);
      item.showError(errorText(error));
    }
  });
}
