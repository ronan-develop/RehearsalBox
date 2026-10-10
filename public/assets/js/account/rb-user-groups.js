/**
 * <rb-user-groups> (#272) — groupes d'un compte dans l'administration. Le composant rend lui-même le HTML (renderGroupsHtml) à partir
 * des attributs JSON `groups` (groupes du compte, avec rôle) et `all-groups` (tous les groupes). Il ne parle jamais à l'API : il émet
 * des évènements qui remontent (user-group-role, user-group-remove, user-group-move, user-group-add), c'est la page qui les traite.
 *
 *   <rb-user-groups user-id="3" groups='[{"id":1,"name":"Alpha","role":"membre"}]' all-groups='[{"id":1,"name":"Alpha"},{"id":2,"name":"Beta"}]'></rb-user-groups>
 */
import { parseGroups, renderGroupsHtml } from './user-groups.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbUserGroups;

if (typeof HTMLElement !== 'undefined') {
  RbUserGroups = class extends HTMLElement {
    static observedAttributes = ['groups', 'all-groups'];

    connectedCallback() {
      this.addEventListener('click', this.#onClick);
      this.addEventListener('change', this.#onChange);
      this.#render();
    }

    disconnectedCallback() {
      this.removeEventListener('click', this.#onClick);
      this.removeEventListener('change', this.#onChange);
    }

    attributeChangedCallback() {
      this.#render();
    }

    #render() {
      this.innerHTML = renderGroupsHtml({
        userId: Number(this.getAttribute('user-id')),
        userGroups: parseGroups(this.getAttribute('groups') ?? '[]'),
        allGroups: parseGroups(this.getAttribute('all-groups') ?? '[]'),
      });
    }

    #emit(name, detail) {
      this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true }));
    }

    #userId() {
      return Number(this.getAttribute('user-id'));
    }

    // Délégation : un seul écouteur pour les boutons de toutes les lignes et de la ligne d'ajout.
    #onClick = (event) => {
      const row = event.target.closest?.('.rb-user-group-row');
      const groupId = row ? Number(row.dataset.groupId) : null;

      if (event.target.closest?.('[data-user-group-remove]')) {
        this.#emit('user-group-remove', { userId: this.#userId(), groupId });
        return;
      }

      if (event.target.closest?.('[data-user-group-move]')) {
        const target = row.querySelector('[data-user-group-target]').value;
        if (target !== '') {
          this.#emit('user-group-move', { userId: this.#userId(), fromGroupId: groupId, toGroupId: Number(target) });
        }
        return;
      }

      if (event.target.closest?.('[data-user-group-add]')) {
        const add = event.target.closest('.rb-user-group-add');
        const target = add.querySelector('[data-user-group-add-target]').value;
        if (target !== '') {
          const role = add.querySelector('[data-user-group-add-role]').value;
          this.#emit('user-group-add', { userId: this.#userId(), groupId: Number(target), role });
        }
      }
    };

    #onChange = (event) => {
      if (event.target.closest?.('[data-user-group-role]')) {
        const row = event.target.closest('.rb-user-group-row');
        this.#emit('user-group-role', { userId: this.#userId(), groupId: Number(row.dataset.groupId), role: event.target.value });
      }
    };
  };

  if (!customElements.get('rb-user-groups')) {
    customElements.define('rb-user-groups', RbUserGroups);
  }
}
