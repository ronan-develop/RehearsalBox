/**
 * Requêtes et messages de la page « Assemblées » (#361), sans DOM : chemins de l'API, corps, textes affichés. La page
 * (admin-assemblies.js) s'en sert ; les composants n'appellent jamais l'API.
 */
const ID = /^\d+$/;

function pathFor(id, suffix = '') {
  if (typeof id !== 'string' || !ID.test(id)) {
    throw new Error('Identifiant de compte rendu invalide.');
  }

  return `/api/admin/assemblies/${id}${suffix}`;
}

/** @returns {{ path: string, options: { method: string, body?: string } }} */
export function createRequest(kind, heldOn) {
  return { path: '/api/admin/assemblies', options: { method: 'POST', body: JSON.stringify({ kind, heldOn }) } };
}

export function saveRequest(id, payload) {
  return { path: pathFor(id), options: { method: 'PUT', body: JSON.stringify(payload) } };
}

export function validateRequest(id, revision) {
  return { path: pathFor(id, '/validate'), options: { method: 'POST', body: JSON.stringify({ revision }) } };
}

export function correctRequest(id, reason) {
  return { path: pathFor(id, '/corrections'), options: { method: 'POST', body: JSON.stringify({ reason }) } };
}

export function deleteRequest(id) {
  return { path: pathFor(id), options: { method: 'DELETE' } };
}

/** Le compte rendu a été validé ou modifié par un autre administrateur : la page doit être rechargée. */
export function isStale(error) {
  return error?.status === 409;
}

export function failureMessage(error) {
  return typeof error?.message === 'string' && error.message !== '' ? error.message : 'Une erreur est survenue.';
}

export const VALIDATE_WARNING = 'Une fois validé, le compte rendu ne pourra plus être modifié. Une correction créera une nouvelle version.';
export const DELETE_WARNING = 'Supprimer ce brouillon ? Il sera effacé définitivement.';
