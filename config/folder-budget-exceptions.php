<?php

// Dossiers de src/ déjà trop pleins au moment de l'introduction du plafond (voir bin/check-folders.php, #287) : figés à leur effectif.
// Chaque ticket de migration par domaine vide un de ces dossiers, puis retire son entrée ici (cf. .claude/audit-architecture.md).
return [
    'Controller/Api'      => 17,
    'Entity'              => 29,
    'Presenter'           => 16,
    'Repository'          => 27,
    'Repository/Contract' => 25,
    'Security'            => 14,
    'Service'             => 42,
    'Service/Exception'   => 16,
];
