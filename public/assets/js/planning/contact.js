/**
 * Cartes du planning (#181) : un groupe dont on est membre ouvre son espace ; un autre groupe ouvre la page « nouvelle
 * conversation » (/messages/new/{id}), où l'on écrit le premier message comme dans Signal. Plus de modale : le serveur
 * revérifie tout à l'envoi, l'adresse e-mail de contact n'est jamais exposée au client.
 */
export function handlePlanningCardActivation(card, navigate = (url) => { window.location.href = url; }) {
  if (card.dataset.currentUserGroupRole) {
    navigate(`/groups/${card.dataset.contactGroupSlug}/space`);
    return;
  }

  navigate(`/messages/new/${card.dataset.contactGroupId}`);
}

export function initContact(root = document, navigate) {
  root.addEventListener('click', (event) => {
    const card = event.target.closest('[data-contact-group-id]');
    if (card) {
      handlePlanningCardActivation(card, navigate);
    }
  });

  root.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }
    const card = event.target.closest('[data-contact-group-id]');
    if (card) {
      event.preventDefault();
      handlePlanningCardActivation(card, navigate);
    }
  });
}
