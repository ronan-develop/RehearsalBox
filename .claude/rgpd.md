# Données personnelles et durées de conservation (#431)

Ce qu'RehearsalBox garde d'une personne, pourquoi, combien de temps, et ce que la personne peut faire elle-même. À tenir à jour à chaque nouvelle donnée personnelle (nouvelle colonne, nouveau journal, nouvel e-mail).

## Inventaire et durées

| Donnée | Où | Pourquoi | Durée de conservation |
|---|---|---|---|
| Nom affiché, adresse e-mail, rôle, état du compte | `users` | identifier la personne, la joindre, contrôler l'accès | tant que le compte existe ; **anonymisés à la suppression** (la ligne reste, « Ancien membre », adresse inutilisable) |
| Empreinte du mot de passe, compteur d'échecs, version de session, **date de dernière connexion** (#512) | `users` | connexion sécurisée, savoir qui a activé son compte | idem ; la date est effacée à la suppression ; l'empreinte est remplacée par un secret aléatoire à la suppression |
| Appartenance aux groupes et rôle | `group_user` | droits d'accès | supprimée avec le compte |
| Messages (texte), titres de conversation, anciennes versions corrigées | `conversation_*` | la messagerie entre groupes | texte **chiffré en base** (#171). Conservés tant que la conversation existe (ils appartiennent aussi aux autres participants) ; corbeille : 30 jours avant effacement définitif ; anciennes versions corrigées : 30 jours |
| Réservations libres, demandes d'échange de créneau | `free_slot_bookings`, `slot_exceptions` | gérer l'occupation du local | tant que le groupe existe ; rattachées à la ligne anonymisée après suppression du compte |
| Comptes rendus d'assemblée générale : noms du bureau, des présents, excusés et représentés (y compris non-inscrits saisis à la main), porteurs de pouvoir ; **votes anonymes** | `assemblies`, `assembly_*` | registre des délibérations de l'association | tant que l'association existe (archive : le nom est recopié, **non anonymisé** à la suppression d'un compte) ; un brouillon supprimé est effacé, mais subsiste dans les sauvegardes 14 jours ; lecture réservée aux administrateurs |
| Textes de l'association (statuts, règlement intérieur…) : peuvent contenir des noms et l'adresse du siège ; dernier modificateur et date | `association_texts` | disposer des textes de référence de l'association | tant que l'association en a besoin ; modification sur place (pas d'historique) ; **suppression effective**, mais le texte subsiste dans les sauvegardes 14 jours ; lecture et écriture réservées aux administrateurs |
| Bureau de l'association : compte titulaire et prénom et nom de chaque poste (président, trésorier, secrétaire) ; dernier modificateur et date | `association_board` | désigner les titulaires imprimés dans les comptes rendus d'AG | tant que le titulaire tient le poste ; remplacement ou **vidage effectif** (pas d'historique ; les comptes rendus validés gardent le nom recopié) ; lecture et écriture réservées aux administrateurs |
| Journal comptable de l'association : écritures (libellé, montant, catégorie, moyen de paiement, pièce) et exercices ; auteur (compte) et date de chaque écriture, clôture et approbation | `accounting_fiscal_years`, `accounting_entries` | tenir la comptabilité de trésorerie et la présenter à l'assemblée générale (art. 11 des statuts) | **10 ans après la clôture de l'exercice** (à valider avec le trésorier) ; écritures **immuables**, jamais supprimées ni modifiées (correction par contre-passation) ; un compte anonymisé laisse l'écriture en place ; lecture et écriture réservées aux administrateurs ; aucun nom de personne dans les libellés |
| Jetons de réinitialisation / changement d'adresse | `password_resets`, `email_changes` | opérations sécurisées | usage unique, 1 heure ; révoqués à la suppression du compte |
| Évènements de limitation (empreintes d'adresse IP et d'identifiant) | `throttle_events` | freiner les essais de connexion | 24 heures, empreintes seulement, jamais d'adresse en clair |
| Mesures d'exploitation (adresse IP **hachée** avec un secret, route, statut) | `metric_events`, `metric_hourly` | santé du site, détection d'abus | événements 30 jours (plafonnés par adresse et par jour, #442) ; agrégats 90 jours ; aucune adresse en clair |
| Journal d'erreurs applicatif | `storage/logs` (hors base) | diagnostic | rotation par taille (voir `logging` dans `config/config.php`), fichier réservé au propriétaire ; jamais de mot de passe ni de jeton |
| Documents des groupes (PDF, images) | `storage/group-documents` | partage de fiches techniques | tant que le groupe les garde ; images ré-encodées sans métadonnées (#432) |
| Relevés de compte de l'association (PDF, images) (#362) | `storage/accounting-statements` | pièces justificatives du livre (IBAN et noms de tiers possibles, jamais lus ni journalisés) | 10 ans (obligation comptable) ; administrateurs seulement, ni suppression ni partage |
| Cotisations par adhérent (#362) | `accounting_entries.member_user_id`, `period_month`, `accounting_member_exemptions` | savoir qui a cotisé quel mois (obligation comptable de l'association) | 10 ans avec le livre ; compte supprimé : la ligne reste, le compte est anonymisé ; administrateurs seulement ; aucun nom dans les journaux ni les e-mails |
| Lignes du CSV de la banque en attente (#362) | `accounting_import_lines` (libellé, date, montant) | relire et valider les mouvements du mois | 30 jours au plus (purge par le cron) ; les libellés bancaires contiennent des noms de tiers et parfois un IBAN ; administrateurs seulement ; le fichier n'est pas conservé ; seule une empreinte sans contenu reste 400 jours pour écarter les doublons |
| Sauvegardes de la base | `backups/` (hors racine web) | reprise après incident | 14 quotidiennes + 7 d'avant déploiement, puis effacées ; **une suppression de compte n'y est appliquée qu'à leur expiration** |

## Droits exercés par la personne, depuis « Mon compte »

- **Accès / portabilité** : « Télécharger mes données (JSON) » (`GET /api/account/export`) : profil, groupes, messages écrits (texte déchiffré), réservations et demandes. Aucun secret, aucune donnée d'autrui.
- **Effacement** : « Supprimer mon compte » (`DELETE /api/account`, mot de passe + mot SUPPRIMER). Voir `AccountDeletionService` : anonymisation, sortie des groupes, révocation des liens et des sessions, e-mail de confirmation à l'ancienne adresse. Refusé tant qu'un compte est administrateur ou dernier gestionnaire d'un groupe (il passe la main d'abord).
- **Rectification** : nom et adresse modifiables dans « Mon compte ».

## Limites connues et décisions

- Comptes rendus d'AG (#361) : une demande d'effacement ou de rectification d'un nom se traite à la main, par une **version corrigée** (la version validée remplacée reste lisible). Les personnes non inscrites n'ont pas d'espace « Mon compte » : leurs demandes passent par un administrateur. L'export « Mes données » n'inclut pas les comptes rendus (à décider). Le PDF produit par « Imprimer → PDF » sort de l'application : il relève de l'administrateur qui l'imprime. Aucun motif d'absence n'est demandé.
- Les messages envoyés **restent** dans les conversations des autres sous « Ancien membre » : ils font partie de leurs échanges. Une demande d'effacement des messages eux-mêmes se traite à la main (administrateur) : pas de fonction libre-service.
- Les mentions et libellés (`message_mentions.label`) gardent le nom tel qu'il était au moment du message.
- L'annuaire interne (recherche de membres, messages directs) est visible de tout membre connecté par construction ; aucune adresse e-mail n'y figure (#429).
- Les sauvegardes ne sont pas réécrites : un compte supprimé peut y subsister jusqu'à 14 jours (quotidiennes). À mentionner à la personne qui le demande.
- Aucun traceur ni service tiers côté navigateur (CSP `default-src 'self'`) ; le lieu d'hébergement et le contrat de sous-traitance relèvent du propriétaire (hors dépôt public).
