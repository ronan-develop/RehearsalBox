/**
 * Contrat d'événements entre les composants de la messagerie (#181, #183). Les composants ne parlent jamais à l'API ni
 * entre eux : ils émettent ces événements (qui remontent dans le DOM) et le contrôleur <rb-chat> décide. La navigation
 * (conversations, archives, retour) n'en fait pas partie : ce sont de vrais liens.
 */
export const EVT = Object.freeze({
  SUBMIT: 'composer:submit', // { text, mentions } : l'utilisateur envoie son message (mentions = identifiants des personnes taguées)
  TYPING: 'composer:typing', // {} : il est en train d'écrire (déjà limité en débit)
  RENAME: 'header:rename', // { title } : nouveau titre ('' pour le retirer)
  EDIT_REQUEST: 'message:edit-request', // { id, text } : la personne veut corriger son message (appui long, bouton)
  EDIT: 'composer:edit', // { id, text, mentions } : le texte corrigé est validé
});

export function emit(target, name, detail = {}) {
  target.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}
