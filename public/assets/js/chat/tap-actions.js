/**
 * Actions d'un message au TAP sur sa bulle, sur écran tactile (#253) : la citation à droite (messages des autres), le crayon à
 * gauche (mes messages récents). Remplace les glissements horizontaux, peu fiables sur téléphone (sélection de texte, gestes de
 * bord d'écran de Chrome et Safari sur iPhone). Le module ne fait que montrer ou cacher (classe sur la ligne) : les boutons
 * existent déjà dans le gabarit et gardent leurs propres gestionnaires (rb-message-list.js).
 */
export const ACTIVE_CLASS = 'rb-chat-message--actions';

/** Ce qui, dans une bulle, a déjà son propre toucher : lien (dont la citation), bouton, mention. */
const OWN_TAP = 'a, button, .rb-chat-quote, .rb-chat-mention';
const ACTIONS = '[data-quote-message], [data-edit-message]';

const hasSelection = (win) => (win.getSelection?.()?.toString() ?? '') !== '';

export function wireTapActions(root, { doc = document, win = window } = {}) {
  let active = null;

  const close = () => {
    active?.classList.remove(ACTIVE_CLASS);
    active = null;
  };

  root.addEventListener('click', (event) => {
    if (event.target.closest(ACTIONS) !== null) {
      close();

      return;
    }
    const row = event.target.closest('.rb-chat-bubble')?.closest('.rb-chat-message[data-message-id]') ?? null;
    if (row === null || event.target.closest(OWN_TAP) !== null || hasSelection(win)) {
      return;
    }
    const wasActive = active === row;
    close();
    if (!wasActive) {
      row.classList.add(ACTIVE_CLASS);
      active = row;
    }
  });

  // Un toucher ailleurs, un défilement ou Échap referment ; un toucher dans la ligne ouverte est géré ci-dessus.
  doc.addEventListener('click', (event) => {
    if (active !== null && !active.contains(event.target)) {
      close();
    }
  });
  root.addEventListener('scroll', close, { passive: true });
  doc.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      close();
    }
  });
}
