/**
 * Choix d'un horaire en deux listes, heures puis minutes (#296) : logique pure, sans DOM, testée à part. Le composant
 * <rb-time-picker> s'en sert ; les minutes sont les quarts d'heure, bornés par une heure minimale et une heure maximale.
 */
export const STEP_MINUTES = 15;

const QUARTERS = Array.from({ length: 60 / STEP_MINUTES }, (_, index) => String(index * STEP_MINUTES).padStart(2, '0'));

const toMinutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));

/** « HH:MM » quand l'heure ET les minutes sont choisies, sinon chaîne vide (le formulaire n'a alors pas encore d'horaire). */
export function combine(hour, minute) {
  return hour !== '' && minute !== '' ? `${hour}:${minute}` : '';
}

/** Les minutes proposées pour une heure donnée, entre l'horaire minimal et l'horaire maximal (« HH:MM », inclus). */
export function minutesFor(hour, min, max) {
  if (hour === '') {
    return [...QUARTERS];
  }

  return QUARTERS.filter((minute) => {
    const at = toMinutes(`${hour}:${minute}`);

    return at >= toMinutes(min) && at <= toMinutes(max);
  });
}
