/**
 * Citer un message (#214), logique sans DOM : l'aperçu affiché dans la saisie et la lecture d'une bulle. L'aperçu suit la
 * même règle que le serveur (une seule ligne, 100 caractères) ; seul l'identifiant du message est envoyé, le texte cité est
 * relu par le serveur à l'affichage.
 */
export const QUOTE_EXCERPT_LENGTH = 100;

/** Une seule ligne, coupée à 100 caractères (jamais au milieu d'un caractère) avec « … » si elle est plus longue. */
export function quoteExcerpt(text) {
  const characters = [...String(text ?? '').replace(/\s+/g, ' ').trim()];

  return characters.length > QUOTE_EXCERPT_LENGTH ? `${characters.slice(0, QUOTE_EXCERPT_LENGTH).join('')}…` : characters.join('');
}

/** @returns {{ id: string, author: string, text: string } | null} null si la ligne n'est pas un message citable */
export function quoteFromRow(row) {
  const id = row?.dataset?.messageId;
  const author = row?.dataset?.author;
  const text = row?.querySelector?.('.rb-chat-text')?.textContent;
  if (!id || !author || typeof text !== 'string') {
    return null;
  }

  return { id, author, text };
}
