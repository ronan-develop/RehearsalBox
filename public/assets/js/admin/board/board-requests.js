/**
 * Requêtes et messages de la page « Bureau de l'association », sans DOM : chemins de l'API, corps, textes affichés. La page
 * (admin-board.js) s'en sert ; les composants n'appellent jamais l'API.
 */
import { failureMessage } from '../assembly/assembly-requests.js';

export { failureMessage };

const POSTS = ['president', 'tresorier', 'secretaire'];

export const VACATE_WARNING = 'Ce poste n\'aura plus de titulaire : le choix redeviendra libre pour les prochains comptes rendus. Les comptes rendus déjà validés ne changent pas.';

function pathFor(post) {
  if (!POSTS.includes(post)) {
    throw new Error('Poste invalide.');
  }

  return `/api/admin/board/${post}`;
}

/** Le compte choisi dans la liste : un entier positif, sinon null (le serveur le refusera avec son message). */
export function parseUserId(value) {
  return /^[1-9]\d*$/.test(value) ? Number(value) : null;
}

export function assignRequest(post, userId, fullName) {
  return { path: pathFor(post), options: { method: 'PUT', body: JSON.stringify({ userId, fullName }) } };
}

export function vacateRequest(post) {
  return { path: pathFor(post), options: { method: 'DELETE' } };
}
