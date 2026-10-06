/**
 * Actions d'un message au TAP sur sa bulle, sur écran tactile (#253, #257). Un composant <rb-message-actions> est INSÉRÉ dans
 * la ligne au tap et RETIRÉ à la fermeture : rien n'est caché dans la page en attendant (plus de bouton à opacité nulle, ni de
 * survol collant d'iOS qui le montrerait sans qu'il soit touchable). Le composant est cloné depuis un <template> rendu par le
 * serveur (une seule définition des icônes et libellés) ; il ne contient que les boutons déjà gérés par rb-message-list.js
 * (`data-quote-message`, `data-edit-message`) : le module ne fait qu'insérer et retirer.
 *
 * Le tap est l'évènement `pointerup` tactile, pas `click` : sur iOS (Safari, Chrome), `click` n'est pas envoyé pour un toucher sur une
 * zone non interactive (une bulle, le fond de page) ni quand le survol d'un tap change l'affichage ; `pointerup` l'est toujours, et
 * un défilement l'annule de lui-même (`pointercancel`). Les boutons d'action, de vrais <button>, gardent leur `click`.
 *
 * La citation à DROITE de la bulle des autres ; à GAUCHE de mes bulles : le crayon si elles sont encore modifiables
 * (`data-editable`), sinon la citation.
 */
const OWN_TAP = 'a, button, .rb-chat-quote, .rb-chat-mention'; // ce qui, dans une bulle, a déjà son propre toucher
const ACTIONS = '[data-quote-message], [data-edit-message]';
const ROW = '.rb-chat-message[data-message-id]';
const TEMPLATE = 'template[data-message-actions]';

const hasSelection = (win) => (win.getSelection?.()?.toString() ?? '') !== '';

/** Le composant propre à cette ligne : seuls les boutons utiles, du bon côté. */
function actionsFor(template, row) {
  const actions = template.content.cloneNode(true).firstElementChild;
  const mine = row.classList.contains('rb-chat-message--mine');
  const editable = row.hasAttribute('data-editable');

  if (!(mine && editable)) {
    actions.querySelector('[data-edit-message]')?.remove();
  }
  if (mine && editable) {
    actions.querySelector('[data-quote-message]')?.remove();
  }
  actions.dataset.side = mine ? 'left' : 'right';

  return actions;
}

export function wireTapActions(root, { doc = document, win = window } = {}) {
  const template = doc.querySelector(TEMPLATE);
  if (template === null) {
    return;
  }

  let open = null; // { row, actions }

  const close = () => {
    open?.actions.remove();
    open = null;
  };

  const isTouch = (event) => event.pointerType === 'touch' || event.pointerType === 'pen';

  root.addEventListener('pointerup', (event) => {
    if (!isTouch(event) || event.target.closest(ACTIONS) !== null) {
      return; // un bouton d'action agit par son propre « click » (ci-dessous)
    }
    const row = event.target.closest('.rb-chat-bubble')?.closest(ROW) ?? null;
    if (row === null || event.target.closest(OWN_TAP) !== null || hasSelection(win)) {
      return;
    }
    const wasOpen = open?.row === row;
    close();
    if (!wasOpen) {
      const actions = actionsFor(template, row);
      row.append(actions);
      open = { row, actions };
    }
  });

  // Choisir une action referme le composant : l'action elle-même est traitée par rb-message-list.js.
  root.addEventListener('click', (event) => {
    if (event.target.closest(ACTIONS) !== null) {
      close();
    }
  });

  // Un toucher ailleurs, un défilement ou Échap referment ; un toucher dans la ligne ouverte est géré ci-dessus.
  doc.addEventListener('pointerup', (event) => {
    if (isTouch(event) && open !== null && !open.row.contains(event.target)) {
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
