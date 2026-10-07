<?php

// Dossiers de src/ déjà trop pleins au moment de l'introduction du plafond (voir bin/check-folders.php, #287) : figés à leur effectif.
// Chaque ticket de migration par domaine vide un de ces dossiers, puis retire son entrée ici (cf. .claude/audit-architecture.md).
return [
    'Controller/Api'      => 14,
    'Entity'              => 25,
    'Presenter'           => 16,
    'Repository'          => 23,
    'Repository/Contract' => 21,
    'Security'            => 13,
    'Service'             => 38,
];
