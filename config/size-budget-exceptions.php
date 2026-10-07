<?php

// Classes déjà trop grosses au moment de l'introduction du budget (voir bin/check-size.php) : figées à leur taille actuelle.
// Chaque ticket qui touche une de ces classes la découpe, puis retire son entrée ici.
return [
    'Account/Entity/User.php'                                              => ['lines' => 218, 'public' => 19],
];
