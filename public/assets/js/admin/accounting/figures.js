/**
 * Chiffres de l'exercice repris dans le rapport financier d'un compte rendu d'AG (#362), sans DOM : comment le texte reçu rejoint ce qui est
 * déjà écrit. On n'écrase jamais la saisie : le texte s'ajoute après, et une seconde reprise ne le duplique pas.
 */
export function mergeFigures(current, figures) {
  const written = current.trim();
  const incoming = figures.trim();
  if (incoming === '' || written.includes(incoming)) {
    return current;
  }

  return written === '' ? incoming : `${written}\n\n${incoming}`;
}
