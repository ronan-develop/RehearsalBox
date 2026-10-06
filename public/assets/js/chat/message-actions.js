/**
 * Actions d'un message sur écran tactile (#253, #257), logique sans DOM : quel bouton, de quel côté, et ce qui compte comme un tap
 * sur une bulle. Le composant <rb-message-actions> (rb-message-actions.js) s'en sert ; <rb-message-list> décide quand l'insérer.
 */

/**
 * La citation à DROITE de la bulle des autres ; à GAUCHE de mes bulles : le crayon si elles sont encore modifiables, sinon la
 * citation (rien n'est perdu). Un seul bouton à la fois.
 *
 * @returns {{ side: 'left' | 'right', keep: 'quote' | 'edit' }}
 */
export function actionsFor({ mine, editable }) {
  return { side: mine ? 'left' : 'right', keep: mine && editable ? 'edit' : 'quote' };
}

/** Ce qui, dans une bulle, a déjà son propre toucher : lien (dont la citation), bouton, mention. */
const OWN_TAP = 'a, button, .rb-chat-quote, .rb-chat-mention';
const ACTIONS = '[data-quote-message], [data-edit-message]';
const ROW = '.rb-chat-message[data-message-id]';

/**
 * Le tap est l'évènement `pointerup` tactile, pas `click` : sur iOS (Safari, Chrome), `click` n'est pas envoyé pour un toucher sur
 * une zone non interactive (une bulle) ni quand le survol d'un tap change l'affichage ; `pointerup` l'est toujours, et un défilement
 * l'annule de lui-même (`pointercancel`). La ligne tapée, ou null : souris (le survol suffit), lien, mention, citation, bouton
 * d'action (il agit par son propre « click »), ou texte sélectionné (copier reste possible).
 */
export function tappedRow(event, win = window) {
  if (event.pointerType !== 'touch' && event.pointerType !== 'pen') {
    return null;
  }
  if (event.target.closest(ACTIONS) !== null || event.target.closest(OWN_TAP) !== null) {
    return null;
  }
  if ((win.getSelection?.()?.toString() ?? '') !== '') {
    return null;
  }

  return event.target.closest('.rb-chat-bubble')?.closest(ROW) ?? null;
}
