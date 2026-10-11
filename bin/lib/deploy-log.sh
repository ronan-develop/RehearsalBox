#!/usr/bin/env bash
# Journal des déploiements (#396). À charger avec `source` depuis bin/deploy.sh, après la définition de rb_ssh et de $BASE.
#   deploy_log_line release commit startedAt durationS outcome switched checks   une ligne JSON, ou RIEN si un champ est invalide
#   deploy_log_append "<ligne>"      l'ajoute à shared/deployments.jsonl sur le serveur (0600, 200 dernières lignes gardées)
#   deploy_log_finish <code>         construit la ligne du déploiement qui se termine et l'envoie (piège EXIT de deploy.sh)
# Chaque champ est validé par expression régulière : aucune variable d'environnement, aucun chemin ni aucun identifiant d'hébergement
# ne peut entrer dans la ligne. Un échec du journal n'a JAMAIS d'effet sur le déploiement (code de sortie conservé).

deploy_log_line() {
    local release=$1 commit=$2 started=$3 duration=$4 outcome=$5 switched=$6 checks=$7
    [[ $release =~ ^[0-9]{14}-[0-9a-f]{7,40}$ ]] || return 0
    [[ $commit =~ ^[0-9a-f]{7,40}$ ]] || return 0
    [[ $started =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || return 0
    [[ $duration =~ ^[0-9]{1,6}$ ]] || return 0
    [[ $outcome =~ ^(ok|failed)$ ]] || return 0
    [[ $switched =~ ^[01]$ ]] || return 0
    [[ $checks =~ ^(ci|local|skipped)$ ]] || return 0
    local flag=false
    [ "$switched" = 1 ] && flag=true
    printf '{"v":1,"release":"%s","commit":"%s","startedAt":"%s","durationS":%d,"outcome":"%s","switched":%s,"checks":"%s"}\n' \
        "$release" "$commit" "$started" "$((10#$duration))" "$outcome" "$flag" "$checks"
}

deploy_log_append() {
    local line=$1
    [ -n "$line" ] || return 0
    printf '%s\n' "$line" | rb_ssh -o ConnectTimeout=10 "umask 077; f=\"\$HOME/$BASE/shared/deployments.jsonl\"; cat >> \"\$f\" && tail -n 200 \"\$f\" > \"\$f.tmp\" && mv \"\$f.tmp\" \"\$f\"" \
        || echo "Journal : écriture impossible (déploiement non affecté)." >&2
    return 0
}

# Variables lues : release, commit, deploy_started (secondes epoch), switched (0|1), checks_mode (ci|local|skipped).
deploy_log_finish() {
    local code=$1 now outcome=ok started
    now=$(date +%s)
    [ "$code" = 0 ] || outcome=failed
    started=$(date -u -d "@${deploy_started:-$now}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)
    deploy_log_append "$(deploy_log_line "${release:-}" "${commit:-}" "$started" "$(( now - ${deploy_started:-$now} ))" "$outcome" "${switched:-0}" "${checks_mode:-skipped}")"
    return 0
}
