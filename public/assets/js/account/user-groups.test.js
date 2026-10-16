import { test } from 'node:test';
import assert from 'node:assert/strict';
import { availableGroups, groupRoleLabel, parseGroups, renderGroupsHtml } from './user-groups.js';

const ALL = [
  { id: 1, name: 'Alpha' },
  { id: 2, name: 'Beta' },
  { id: 3, name: 'Gamma' },
];

test('availableGroups exclut les groupes déjà pris et garde l\'ordre de allGroups', () => {
  const userGroups = [{ id: 2, name: 'Beta', role: 'membre' }];
  assert.deepEqual(availableGroups(userGroups, ALL), [
    { id: 1, name: 'Alpha' },
    { id: 3, name: 'Gamma' },
  ]);
});

test('availableGroups : tous les groupes sont pris => liste vide', () => {
  const userGroups = [
    { id: 1, name: 'Alpha', role: 'membre' },
    { id: 2, name: 'Beta', role: 'gestionnaire' },
    { id: 3, name: 'Gamma', role: 'membre' },
  ];
  assert.deepEqual(availableGroups(userGroups, ALL), []);
});

test('availableGroups : aucun groupe pris => tous les groupes', () => {
  assert.deepEqual(availableGroups([], ALL), ALL);
});

test('availableGroups ignore les entrées invalides', () => {
  const allGroups = [null, { id: 'x', name: 'Bad' }, { id: 4 }, { id: 5, name: 'Delta' }, 'Epsilon'];
  const userGroups = [null, { id: 'y' }, { id: 5, name: 'Delta', role: 'membre' }];
  assert.deepEqual(availableGroups(userGroups, allGroups), []);
  assert.deepEqual(availableGroups([null], allGroups), [{ id: 5, name: 'Delta' }]);
});

test('availableGroups : listes vides ou non-tableaux => []', () => {
  assert.deepEqual(availableGroups(null, null), []);
  assert.deepEqual(availableGroups(undefined, ALL), ALL);
  assert.deepEqual(availableGroups([], undefined), []);
  assert.deepEqual(availableGroups('nope', {}), []);
});

test('groupRoleLabel traduit les deux rôles et retombe sur Membre', () => {
  assert.equal(groupRoleLabel('gestionnaire'), 'Gestionnaire');
  assert.equal(groupRoleLabel('membre'), 'Membre');
  assert.equal(groupRoleLabel('inconnu'), 'Membre');
  assert.equal(groupRoleLabel(undefined), 'Membre');
});

test('parseGroups lit un tableau JSON valide', () => {
  assert.deepEqual(parseGroups('[{"id":1,"name":"Alpha","role":"gestionnaire"},{"id":"2","name":"Beta"}]'), [
    { id: 1, name: 'Alpha', role: 'gestionnaire' },
    { id: 2, name: 'Beta' },
  ]);
});

test('parseGroups : JSON invalide, valeur non tableau => []', () => {
  assert.deepEqual(parseGroups('{pas du json'), []);
  assert.deepEqual(parseGroups('{"id":1,"name":"Alpha"}'), []);
  assert.deepEqual(parseGroups('null'), []);
  assert.deepEqual(parseGroups(undefined), []);
});

test('parseGroups ignore les entrées sans id entier ou sans nom', () => {
  assert.deepEqual(parseGroups('[{"id":"abc","name":"X"},{"id":1},{"id":2,"name":"Ok"},3]'), [{ id: 2, name: 'Ok' }]);
});

test('renderGroupsHtml échappe un nom de groupe malveillant', () => {
  const html = renderGroupsHtml({
    userId: 3,
    userGroups: [{ id: 1, name: '<img src=x onerror=1>', role: 'membre' }],
    allGroups: [{ id: 1, name: '<img src=x onerror=1>' }],
  });
  assert.ok(!html.includes('<img'));
  assert.ok(html.includes('&lt;img src=x onerror=1&gt;'));
});

test('renderGroupsHtml : aucun groupe => texte « Aucun groupe »', () => {
  const html = renderGroupsHtml({ userId: 3, userGroups: [], allGroups: [] });
  assert.ok(html.includes('<p class="rb-admin-note">Aucun groupe</p>'));
  assert.ok(!html.includes('rb-user-group-row'));
});

test('renderGroupsHtml : aucun groupe disponible => pas de ligne d\'ajout ni de déplacement', () => {
  const html = renderGroupsHtml({
    userId: 3,
    userGroups: [{ id: 1, name: 'Alpha', role: 'membre' }],
    allGroups: [{ id: 1, name: 'Alpha' }],
  });
  assert.ok(html.includes('class="rb-user-group-row rb-card" data-group-id="1"'));
  assert.ok(html.includes('data-user-group-remove'));
  assert.ok(!html.includes('data-user-group-add'));
  assert.ok(!html.includes('data-user-group-move'));
  assert.ok(!html.includes('data-user-group-target'));
});

test('renderGroupsHtml : le bon rôle est sélectionné pour chaque ligne', () => {
  const html = renderGroupsHtml({
    userId: 3,
    userGroups: [{ id: 1, name: 'Alpha', role: 'gestionnaire' }],
    allGroups: [{ id: 1, name: 'Alpha' }],
  });
  assert.match(html, /<option value="gestionnaire" selected>Gestionnaire<\/option>/);
  assert.doesNotMatch(html, /<option value="membre" selected>/);
});

test('renderGroupsHtml : une carte par groupe, déplacement et ajout si des groupes sont disponibles', () => {
  const html = renderGroupsHtml({
    userId: 3,
    userGroups: [{ id: 2, name: 'Beta', role: 'membre' }],
    allGroups: ALL,
  });
  assert.match(html, /<li class="rb-user-group-row rb-card" data-group-id="2">/);
  assert.match(html, /<span class="rb-user-group-name">Beta<\/span>/);
  assert.match(html, /<select class="rb-input" data-user-group-role aria-label="Rôle dans Beta">/);
  assert.match(html, /<button type="button" class="rb-btn rb-btn-danger" data-user-group-remove>Retirer<\/button>/);
  assert.match(html, /<select class="rb-input" data-user-group-target aria-label="Changer de groupe">/);
  assert.match(html, /Changer de groupe vers…/);
  assert.match(html, /<button type="button" class="rb-btn" data-user-group-move>Déplacer<\/button>/);
  assert.match(html, /<div class="rb-user-group-add rb-card">/);
  assert.match(html, /<label for="rb-ug-add-target-3">Groupe<\/label>\s*<select id="rb-ug-add-target-3" class="rb-input" data-user-group-add-target>/);
  assert.match(html, /<label for="rb-ug-add-role-3">Rôle<\/label>\s*<select id="rb-ug-add-role-3" class="rb-input" data-user-group-add-role>/);
  assert.match(html, /<button type="button" class="rb-btn rb-btn-primary" data-user-group-add>Ajouter<\/button>/);
  assert.match(html, /<option value="1">Alpha<\/option>/);
  assert.doesNotMatch(html, /<option value="2">Beta<\/option>/);
});

test('renderGroupsHtml : aucun select nu, aucun style en ligne, aucune classe partagée avec la liste d\'étiquettes', () => {
  const html = renderGroupsHtml({
    userId: 3,
    userGroups: [{ id: 2, name: 'Beta', role: 'membre' }, { id: 1, name: 'Alpha', role: 'gestionnaire' }],
    allGroups: [...ALL, { id: 9, name: 'Gamma' }],
  });
  for (const tag of html.match(/<select[^>]*>/g)) {
    assert.match(tag, /class="rb-input"/, `select nu : ${tag}`);
  }
  assert.doesNotMatch(html, /style=/);
  assert.doesNotMatch(html, /class="rb-user-groups"/, 'les étiquettes du résumé utilisent déjà rb-user-groups');
  assert.match(html, /<div class="rb-group-manager" data-user-id="3">/);
});

test('renderGroupsHtml : les identifiants des libellés sont uniques par personne et échappés', () => {
  const html = renderGroupsHtml({ userId: '3"><x', userGroups: [], allGroups: ALL });
  assert.ok(!html.includes('"><x'));
  assert.match(html, /for="rb-ug-add-target-3&quot;&gt;&lt;x"/);
});
