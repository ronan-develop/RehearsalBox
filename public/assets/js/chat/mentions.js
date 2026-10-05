/**
 * Logique pure des mentions « @Nom » (#178), sans DOM : trouver la mention en cours de saisie, l'insérer, et ne garder
 * à l'envoi que les personnes dont le « @Nom » figure encore dans le texte. Le serveur revérifie tout (identifiants,
 * appartenance, droit d'ajouter quelqu'un) : ce module n'est qu'une aide à la saisie.
 */
const MAX_QUERY = 30;
const WORD_CHAR = /[\p{L}\p{N}_]/u;

/** Mention en cours de saisie à la position du curseur : { start, query }, sinon null. */
export function activeQuery(text, caret) {
  const before = text.slice(0, caret);
  const at = before.lastIndexOf('@');
  if (at === -1 || (at > 0 && !/\s/.test(before[at - 1]))) {
    return null;
  }
  const query = before.slice(at + 1);
  const spaces = (query.match(/ /g) ?? []).length;
  if (query.length > MAX_QUERY || /[\n\r]/.test(query) || spaces > 1) {
    return null;
  }

  return { start: at, query };
}

/** Remplace « @requête » par « @Nom » suivi d'une espace ; renvoie le nouveau texte et la position du curseur. */
export function applyMention(text, start, caret, name) {
  const insert = `@${name} `;

  return { text: text.slice(0, start) + insert + text.slice(caret), caret: start + insert.length };
}

function containsMention(text, label) {
  let from = 0;
  for (let index = text.indexOf(label, from); index !== -1; index = text.indexOf(label, from)) {
    const next = text[index + label.length];
    if (next === undefined || !WORD_CHAR.test(next)) {
      return true;
    }
    from = index + 1;
  }

  return false;
}

/** @param {Map<number, {name: string, participant: boolean}>} picks personnes choisies dans la liste */
export function mentionedIds(text, picks) {
  return [...picks].filter(([, { name }]) => containsMention(text, `@${name}`)).map(([id]) => id);
}

/** Noms des personnes mentionnées qui ne sont pas encore dans la conversation (elles y seront ajoutées). */
export function pendingGuests(text, picks) {
  return [...picks]
    .filter(([, { name, participant }]) => !participant && containsMention(text, `@${name}`))
    .map(([, { name }]) => name);
}
