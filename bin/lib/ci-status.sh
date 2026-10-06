#!/usr/bin/env bash
# État de la CI d'un commit (#300). À charger avec `source`.
#   ci_green_for_commit <sha>   code 0 seulement si au moins une exécution existe pour ce commit et que TOUTES sont terminées avec succès.
# Au moindre doute (gh absent ou en erreur, aucune exécution, une en cours ou en échec), le code est non nul : le déploiement retombe
# alors sur les contrôles locaux complets. Ne lit ni n'affiche aucune valeur sensible.

ci_green_for_commit() {
    local gh="${RB_GH:-gh}" runs
    runs=$("$gh" run list --commit "$1" --json status,conclusion -q '.[] | .status + ":" + .conclusion' 2>/dev/null) || return 1
    [ -n "$runs" ] || return 1
    ! grep -qv '^completed:success$' <<<"$runs"
}
