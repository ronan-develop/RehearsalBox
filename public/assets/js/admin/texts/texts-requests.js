/**
 * Requêtes et messages de la page « Textes de l'association », sans DOM : chemins de l'API, corps, textes affichés. La page
 * (admin-texts.js) s'en sert ; les composants n'appellent jamais l'API.
 */
import { failureMessage, isStale } from '../assembly/assembly-requests.js';

export { failureMessage, isStale };

const ID = /^\d+$/;

function pathFor(id) {
  if (typeof id !== 'string' || !ID.test(id)) {
    throw new Error('Identifiant de texte invalide.');
  }

  return `/api/admin/texts/${id}`;
}

/** @returns {{ path: string, options: { method: string, body?: string } }} */
export function createRequest(title, body) {
  return { path: '/api/admin/texts', options: { method: 'POST', body: JSON.stringify({ title, body }) } };
}

export function updateRequest(id, revision, title, body) {
  return { path: pathFor(id), options: { method: 'PUT', body: JSON.stringify({ revision, title, body }) } };
}

export function deleteRequest(id) {
  return { path: pathFor(id), options: { method: 'DELETE' } };
}

export const DELETE_WARNING = 'Supprimer ce texte ? Il sera effacé définitivement.';
