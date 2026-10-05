/**
 * Contrat d'événements entre les composants de la messagerie (#181). Les composants ne parlent jamais à l'API ni entre
 * eux : ils émettent ces événements (qui remontent dans le DOM) et le contrôleur <rb-chat> décide.
 */
export const EVT = Object.freeze({
  SUBMIT: 'composer:submit', // { text } : l'utilisateur envoie son message
  TYPING: 'composer:typing', // {} : il est en train d'écrire (déjà limité en débit)
  RENAME: 'header:rename', // { title } : nouveau titre ('' pour le retirer)
  SELECT: 'sidebar:select', // { id } : ouverture d'une conversation
  ARCHIVES: 'sidebar:archives', // { on } : afficher / quitter la liste des archives
  BACK: 'header:back', // {} : retour à la liste (mobile)
});

export function emit(target, name, detail = {}) {
  target.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}
