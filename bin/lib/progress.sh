#!/usr/bin/env bash
# Étapes numérotées et barre de progression du déploiement (#277). À charger avec `source`.
#   progress_init <total>     annonce le nombre d'étapes
#   progress_step "texte"     une étape : [n/N] ████░░░░ 40%  texte  (+12 s)
#   progress_skip "texte"     une étape non exécutée : garde la numérotation et le dit
#   progress_done "texte"     ligne finale avec la durée totale
# N'affiche que le nom des étapes, jamais une variable d'environnement, un chemin ou un identifiant d'hébergement.

PROGRESS_TOTAL=0
PROGRESS_INDEX=0
PROGRESS_WIDTH=20
PROGRESS_START=$SECONDS

progress_init() {
    PROGRESS_TOTAL=$1
    PROGRESS_INDEX=0
    PROGRESS_START=$SECONDS
}

# Affiche une ligne d'étape ; $1 = texte, $2 = suffixe facultatif (« ignorée »).
progress_line() {
    local percent=$(( PROGRESS_INDEX * 100 / PROGRESS_TOTAL ))
    local filled=$(( PROGRESS_INDEX * PROGRESS_WIDTH / PROGRESS_TOTAL ))
    local bar='' i
    for ((i = 0; i < PROGRESS_WIDTH; i++)); do
        if (( i < filled )); then bar+='█'; else bar+='░'; fi
    done
    printf '\n[%d/%d] %s %3d%%  %s%s  (+%d s)\n' "$PROGRESS_INDEX" "$PROGRESS_TOTAL" "$bar" "$percent" "$1" "$2" $(( SECONDS - PROGRESS_START ))
}

progress_step() {
    PROGRESS_INDEX=$(( PROGRESS_INDEX + 1 ))
    progress_line "$1" ''
}

progress_skip() {
    PROGRESS_INDEX=$(( PROGRESS_INDEX + 1 ))
    progress_line "$1" ' — ignorée'
}

progress_done() {
    printf '\n==> %s (%d s)\n' "$1" $(( SECONDS - PROGRESS_START ))
}
