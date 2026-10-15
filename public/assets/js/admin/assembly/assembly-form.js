/**
 * Logique pure du formulaire de compte rendu d'AG (#361), sans DOM : conversion des champs saisis vers le payload de l'API,
 * localisation d'une erreur de validation (clé pointée) et décision d'enregistrer avant de valider. La page ne relit jamais
 * les champs elle-même : elle passe ses valeurs ici.
 */
const LIST_SECTIONS = ['attendees', 'agenda', 'resolutions'];
const BOARD_POSTS = ['president', 'tresorier', 'secretaire'];

/** Texte saisi sans espaces, ou null quand il est vide. */
const text = (value) => {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
};

/** Nombre, ou le texte tel quel s'il n'en est pas un : le serveur le refuse, rien n'est converti en silence. */
const numeric = (trimmed) => (Number.isFinite(Number(trimmed)) ? Number(trimmed) : trimmed);

/** Identifiant ou révision : nombre, ou null quand il est vide. */
const number = (value) => {
  const trimmed = text(value);
  return trimmed === null ? null : numeric(trimmed);
};

/** Vote : entier, un vote vide compte pour zéro. */
const votes = (value) => {
  const trimmed = text(value);
  return trimmed === null ? 0 : numeric(trimmed);
};

/** Quorum atteint : '1' oui, '0' non, vide ou autre valeur : null. */
const quorum = (value) => {
  if (value === '1') return true;
  if (value === '0') return false;
  return null;
};

/** Une ligne est ignorée quand toutes ses valeurs sont vides. */
const isBlankRow = (row) => Object.values(row).every((value) => text(value) === null);

/** Charge utile de l'API : en-tête à plat, postes du bureau renseignés seulement, lignes vides retirées. */
export function buildPayload(header, rows) {
  const board = {};
  for (const post of BOARD_POSTS) {
    if (text(header.board?.[post]) !== null) {
      board[post] = number(header.board[post]);
    }
  }

  return {
    revision: number(header.revision),
    kind: text(header.kind),
    heldOn: text(header.heldOn),
    location: text(header.location),
    openedAt: text(header.openedAt),
    closedAt: text(header.closedAt),
    quorumReached: quorum(header.quorum),
    board,
    attendees: (rows.attendees ?? []).filter((row) => !isBlankRow(row)).map((row) => ({
      userId: number(row.userId),
      name: text(row.name),
      attendance: text(row.attendance),
      proxyHolder: text(row.proxyHolder),
    })),
    agenda: (rows.agenda ?? []).filter((row) => !isBlankRow(row)).map((row) => ({
      title: text(row.title),
      discussion: text(row.discussion),
    })),
    resolutions: (rows.resolutions ?? []).filter((row) => !isBlankRow(row)).map((row) => ({
      text: text(row.text),
      for: votes(row.for),
      against: votes(row.against),
      abstain: votes(row.abstain),
      outcome: text(row.outcome),
    })),
    moralReport: text(header.moralReport),
    financialReport: text(header.financialReport),
    otherBusiness: text(header.otherBusiness),
  };
}

/**
 * Où pointe une clé d'erreur : 'attendees.2.name' → ligne 2 de la liste, 'board.tresorier' → un poste du bureau,
 * 'heldOn' → un champ d'en-tête, 'attendees' → erreur de liste. null pour une clé vide, non textuelle ou inconnue.
 */
export function locateFieldError(key) {
  if (typeof key !== 'string' || key === '') return null;

  const parts = key.split('.');
  if (parts.length === 1) {
    if (LIST_SECTIONS.includes(key)) return { section: key, index: null, field: null };
    return { section: 'header', index: null, field: key };
  }

  const [section, second, third] = parts;
  if (section === 'board' && parts.length === 2) {
    return { section, index: null, field: second };
  }
  if (LIST_SECTIONS.includes(section) && parts.length <= 3 && /^\d+$/.test(second)) {
    return { section, index: Number(second), field: third ?? null };
  }

  return null;
}

/** Enregistrer avant de valider dès que le formulaire a des modifications non enregistrées. */
export function needsSaveBeforeValidate(dirty) {
  return Boolean(dirty);
}
