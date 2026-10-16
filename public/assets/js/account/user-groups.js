/**
 * Logique pure des groupes d'un compte (#272), testée sans DOM : quels groupes sont encore disponibles, libellés des rôles,
 * lecture de l'attribut JSON du composant et gabarit HTML de <rb-user-groups>. Aucun appel réseau ici.
 */
import { escapeHtml } from '../core/html.js';

/** Un identifiant de groupe valide est un entier ; un nom doit être une chaîne. */
function isValidGroup(entry) {
  return entry !== null && typeof entry === 'object' && Number.isInteger(entry.id) && typeof entry.name === 'string';
}

/** Ne garde que les entrées valides, sans les modifier. */
function validGroups(list) {
  return Array.isArray(list) ? list.filter(isValidGroup) : [];
}

/** Groupes de `allGroups` dont l'id n'est pas déjà dans `userGroups`, dans l'ordre de `allGroups`. */
export function availableGroups(userGroups, allGroups) {
  const taken = new Set(validGroups(userGroups).map((group) => group.id));

  return validGroups(allGroups).filter((group) => !taken.has(group.id));
}

export function groupRoleLabel(role) {
  return role === 'gestionnaire' ? 'Gestionnaire' : 'Membre';
}

/** Lit l'attribut JSON `groups` / `all-groups` : tableau d'objets valides, [] sinon. */
export function parseGroups(json) {
  let data;
  try {
    data = JSON.parse(json);
  } catch {
    return [];
  }
  if (!Array.isArray(data)) {
    return [];
  }

  return data.flatMap((entry) => {
    if (entry === null || typeof entry !== 'object') {
      return [];
    }
    const id = typeof entry.id === 'string' || typeof entry.id === 'number' ? Number(entry.id) : NaN;
    const group = { id, name: entry.name };
    if (!isValidGroup(group)) {
      return [];
    }

    return [typeof entry.role === 'string' ? { ...group, role: entry.role } : group];
  });
}

function roleOptions(role) {
  const current = role === 'gestionnaire' ? 'gestionnaire' : 'membre';

  return ['membre', 'gestionnaire']
    .map((value) => `<option value="${value}"${value === current ? ' selected' : ''}>${groupRoleLabel(value)}</option>`)
    .join('');
}

function groupOptions(groups) {
  return groups.map((group) => `<option value="${escapeHtml(group.id)}">${escapeHtml(group.name)}</option>`).join('');
}

/** HTML du composant <rb-user-groups> : une carte par groupe de la personne, puis une carte d'ajout si des groupes sont libres. */
export function renderGroupsHtml({ userId, userGroups, allGroups }) {
  const groups = validGroups(userGroups);
  const available = availableGroups(groups, allGroups);
  const id = escapeHtml(userId);

  const rows = groups.map((group) => {
    const name = escapeHtml(group.name);
    const move = available.length > 0
      ? '<div class="rb-user-group-move">'
        + `<select class="rb-input" data-user-group-target aria-label="Changer de groupe"><option value="">Changer de groupe vers…</option>${groupOptions(available)}</select>`
        + '<button type="button" class="rb-btn" data-user-group-move>Déplacer</button>'
        + '</div>'
      : '';

    return `<li class="rb-user-group-row rb-card" data-group-id="${escapeHtml(group.id)}">`
      + '<div class="rb-user-group-head">'
      + `<span class="rb-user-group-name">${name}</span>`
      + `<select class="rb-input" data-user-group-role aria-label="Rôle dans ${name}">${roleOptions(group.role)}</select>`
      + '<button type="button" class="rb-btn rb-btn-danger" data-user-group-remove>Retirer</button>'
      + '</div>'
      + move
      + '</li>';
  });

  const list = rows.length > 0 ? `<ul class="rb-user-group-list">${rows.join('')}</ul>` : '<p class="rb-admin-note">Aucun groupe</p>';
  const add = available.length > 0
    ? '<div class="rb-user-group-add rb-card">'
      + '<p class="rb-user-group-add-title">Ajouter à un groupe</p>'
      + '<div class="rb-field">'
      + `<label for="rb-ug-add-target-${id}">Groupe</label>`
      + `<select id="rb-ug-add-target-${id}" class="rb-input" data-user-group-add-target><option value="">Choisir un groupe…</option>${groupOptions(available)}</select>`
      + '</div>'
      + '<div class="rb-field">'
      + `<label for="rb-ug-add-role-${id}">Rôle</label>`
      + `<select id="rb-ug-add-role-${id}" class="rb-input" data-user-group-add-role>${roleOptions('membre')}</select>`
      + '</div>'
      + '<button type="button" class="rb-btn rb-btn-primary" data-user-group-add>Ajouter</button>'
      + '</div>'
    : '';

  return `<div class="rb-group-manager" data-user-id="${id}">${list}${add}</div>`;
}
