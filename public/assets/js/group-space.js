/**
 * Édition du line-up et des concerts à venir dans l'espace de groupe —
 * une ligne DOM par entrée, sauvegarde en un seul PATCH JSON (les deux
 * listes sont envoyées entières à chaque modification, cf. GroupService::updateProfile).
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

function rowValue(row, name) {
  return row.querySelector(`[name="${name}"]`)?.value?.trim() ?? '';
}

export function collectLineup(list) {
  return Array.from(list.querySelectorAll('[data-lineup-row]'))
    .map((row) => ({ name: rowValue(row, 'name'), instrument: rowValue(row, 'instrument') }))
    .filter((member) => member.name !== '');
}

export function collectUpcomingShows(list) {
  return Array.from(list.querySelectorAll('[data-show-row]'))
    .map((row) => ({ date: rowValue(row, 'date'), venue: rowValue(row, 'venue') }))
    .filter((show) => show.date !== '');
}

function buildRow(root, fields, rowAttribute) {
  const row = root.createElement('div');
  row.dataset[rowAttribute] = 'true';
  row.className = 'rb-group-space-editor-row';

  for (const field of fields) {
    const input = root.createElement('input');
    input.type = 'text';
    input.name = field.name;
    input.placeholder = field.placeholder;
    input.className = 'rb-input';
    row.appendChild(input);
  }

  const removeButton = root.createElement('button');
  removeButton.type = 'button';
  removeButton.dataset.removeRow = 'true';
  removeButton.className = 'rb-btn rb-btn-danger';
  removeButton.textContent = 'Retirer';
  row.appendChild(removeButton);

  return row;
}

export function addLineupRow(list, root = document) {
  const row = buildRow(root, [
    { name: 'name', placeholder: 'Nom' },
    { name: 'instrument', placeholder: 'Instrument' },
  ], 'lineupRow');
  list.appendChild(row);
  return row;
}

export function addShowRow(list, root = document) {
  const row = buildRow(root, [
    { name: 'date', placeholder: 'Date (AAAA-MM-JJ)' },
    { name: 'venue', placeholder: 'Lieu' },
  ], 'showRow');
  list.appendChild(row);
  return row;
}

export function removeRow(row) {
  row.remove();
}

export async function saveProfile(endpoint, lineup, upcomingShows, fetcher = apiFetch) {
  return fetcher(endpoint, {
    method: 'PATCH',
    body: JSON.stringify({ lineup, upcomingShows }),
  });
}

export function initGroupSpaceEditor(root = document) {
  const editor = root.querySelector('[data-group-space-editor]');
  if (!editor) {
    return;
  }

  const lineupList = editor.querySelector('[data-lineup-editor-list]');
  const showsList = editor.querySelector('[data-shows-editor-list]');
  const endpoint = editor.dataset.endpoint;

  editor.querySelector('[data-add-lineup-row]')?.addEventListener('click', () => {
    addLineupRow(lineupList, root);
  });

  editor.querySelector('[data-add-show-row]')?.addEventListener('click', () => {
    addShowRow(showsList, root);
  });

  editor.addEventListener('click', (event) => {
    const removeButton = event.target.closest('[data-remove-row]');
    if (removeButton) {
      removeRow(removeButton.closest('[data-lineup-row], [data-show-row]'));
    }
  });

  editor.querySelector('[data-group-space-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      await saveProfile(endpoint, collectLineup(lineupList), collectUpcomingShows(showsList));
      showToast('Fiche enregistrée.', 'success');
    } catch (error) {
      showToast(error.message, 'error');
    }
  });
}
