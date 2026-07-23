/** Notifications non bloquantes — jamais d'alert()/confirm() natifs (cf. plan §6/§10.2). */

export function showToast(message, type = 'info') {
  const container = document.querySelector('.rb-toast-container') || createContainer();

  const toast = document.createElement('div');
  toast.className = `rb-toast rb-toast--${type}`;
  toast.textContent = message;

  container.appendChild(toast);

  setTimeout(() => toast.remove(), 4000);
}

function createContainer() {
  const container = document.createElement('div');
  container.className = 'rb-toast-container';
  document.body.appendChild(container);
  return container;
}
