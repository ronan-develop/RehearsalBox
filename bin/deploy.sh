#!/usr/bin/env bash
# Déploiement de RehearsalBox par releases, en SSH. Lancé depuis le poste de dev.
# Aucun secret ni identifiant d'infrastructure ici : tout vient de l'environnement
# et du fichier de secrets local (jamais committé).
#
# Variables obligatoires :
#   RB_SSH_CONFIG    fichier ssh_config local (alias, utilisateur, clé)
#   RB_DOCROOT_LINK  chemin (relatif au home distant) du lien <racine-web>/public
# Variables optionnelles :
#   RB_SSH_HOST (rehearsalbox), RB_REMOTE_BASE (rehearsalbox), RB_SECRETS_FILE (.secrets),
#   RB_REMOTE_PHP (/usr/local/bin/php), RB_REMOTE_COMPOSER (/usr/local/bin/composer),
#   RB_MAILER_DSN, RB_MAILER_FROM, RB_APP_URL (sinon MAILER_DSN / MAILER_FROM / APP_URL du fichier de secrets),
#   RB_REGEN_CONFIG=1 (régénère config.local.php), RB_SKIP_CHECKS=1 (saute phpunit/npm/audit),
#   RB_FORCE_LOCAL_CHECKS=1 (rejoue phpunit/npm même si la CI est verte sur le commit, cf. #300)
set -euo pipefail

cd "$(dirname "$0")/.."

SSH_CONFIG="${RB_SSH_CONFIG:?RB_SSH_CONFIG requis}"
DOCROOT_LINK="${RB_DOCROOT_LINK:?RB_DOCROOT_LINK requis}"
SSH_HOST="${RB_SSH_HOST:-rehearsalbox}"
BASE="${RB_REMOTE_BASE:-rehearsalbox}"
SECRETS_FILE="${RB_SECRETS_FILE:-.secrets}"
REMOTE_PHP="${RB_REMOTE_PHP:-/usr/local/bin/php}"
REMOTE_COMPOSER="${RB_REMOTE_COMPOSER:-/usr/local/bin/composer}"
KEEP_RELEASES=3
KEEP_BACKUPS=7

rb_ssh() { ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" "$@"; }
# Étapes numérotées avec barre de progression (#277) : 11 étapes au total, celles qu'on saute comptent quand même.
source "$(dirname "$0")/lib/progress.sh"
source "$(dirname "$0")/lib/ci-status.sh"
progress_init 11

secret() {
    local value
    value=$(awk -v k="$1" 'index($0,k"=")==1 {print substr($0,length(k)+2); exit}' "$SECRETS_FILE" | tr -d '\r')
    printf '%s' "$value" | sed -E "s/^\"(.*)\"\$/\\1/; s/^'(.*)'\$/\\1/"
}

# --- 1. Contrôles locaux --------------------------------------------------
if ! git diff --quiet HEAD -- bin config database public src templates composer.json composer.lock; then
    echo "Des modifications non commitées touchent les fichiers déployés : seul HEAD est envoyé." >&2
fi
commit=$(git rev-parse --short HEAD)

if [ "${RB_SKIP_CHECKS:-0}" != "1" ]; then
    # CI verte sur ce commit (#300) : phpunit et npm test ont déjà tourné, on ne les rejoue pas ; au moindre doute, contrôles complets.
    run_tests=1
    checks_label="Contrôles locaux (phpunit, npm test, composer audit)"
    if [ "${RB_FORCE_LOCAL_CHECKS:-0}" != "1" ] && ci_green_for_commit "$(git rev-parse HEAD)"; then
        run_tests=0
        checks_label="Contrôles : CI verte sur ${commit}, tests non rejoués (composer audit)"
    fi
    progress_step "$checks_label"
    if [ "$run_tests" = "1" ]; then
        ./vendor/bin/phpunit
        npm test --silent
    fi
    composer audit
else
    progress_skip "Contrôles locaux (RB_SKIP_CHECKS=1)"
fi

release="$(date +%Y%m%d%H%M%S)-${commit}"

# --- 2. Envoi du code (fichiers suivis par git uniquement) ----------------
progress_step "Envoi de la release ${release}"
rb_ssh "mkdir -p \"\$HOME/$BASE/releases\" \"\$HOME/$BASE/shared/storage/group-documents\" \"\$HOME/$BASE/shared/well-known\" \"\$HOME/$BASE/backups\" && chmod 700 \"\$HOME/$BASE/shared\" \"\$HOME/$BASE/backups\" && mkdir \"\$HOME/$BASE/releases/$release\""
# Jamais le seed (script destructif, comptes au mot de passe connu) : il n'a rien à faire en production.
git archive --format=tar HEAD -- bin config database public src templates composer.json composer.lock ':(exclude)database/seed.php' \
    | rb_ssh "tar -x -C \"\$HOME/$BASE/releases/$release\""

# --- 3. Dépendances et liens vers les éléments partagés -------------------
progress_step "composer install --no-dev (PHP CLI explicite)"
rb_ssh bash -s -- "$BASE" "$release" "$REMOTE_PHP" "$REMOTE_COMPOSER" <<'REMOTE'
set -euo pipefail
base=$1; rel=$2; php=$3; composer=$4
cd "$HOME/$base/releases/$rel"
"$php" "$composer" install --no-dev --optimize-autoloader --no-interaction --no-progress
if ! "$php" "$composer" install --dry-run --no-dev --no-interaction 2>&1 | grep -q 'Nothing to install, update or remove'; then
    echo "vendor/ incomplet après composer install." >&2
    exit 1
fi
ln -s "$HOME/$base/shared/config.local.php" config/config.local.php
rm -rf storage && ln -s "$HOME/$base/shared/storage" storage
ln -s "$HOME/$base/shared/well-known" public/.well-known
REMOTE

# --- 4. config.local.php (généré sur le serveur, 0600, hors webroot) ------
if [ "${RB_REGEN_CONFIG:-0}" = "1" ] || ! rb_ssh "test -f \"\$HOME/$BASE/shared/config.local.php\""; then
    progress_step "Génération de config.local.php sur le serveur"
    [ -f "$SECRETS_FILE" ] || { echo "Fichier de secrets introuvable." >&2; exit 1; }
    mailer_dsn="${RB_MAILER_DSN:-$(secret MAILER_DSN)}"
    mailer_from="${RB_MAILER_FROM:-$(secret MAILER_FROM)}"
    {
        for name in PROD_DB_HOST PROD_DB_PORT PROD_DB_DATABASE PROD_DB_USER PROD_DB_PASSWORD; do
            printf '%s=%s\n' "$name" "$(secret "$name" | base64 -w0)"
        done
        printf 'MAILER_DSN=%s\n' "$(printf '%s' "$mailer_dsn" | base64 -w0)"
        printf 'MAILER_FROM=%s\n' "$(printf '%s' "$mailer_from" | base64 -w0)"
        printf 'APP_URL=%s\n' "$(printf '%s' "${RB_APP_URL:-$(secret APP_URL)}" | base64 -w0)"
    } | rb_ssh "cd \"\$HOME/$BASE/releases/$release\" && $REMOTE_PHP bin/generate-config.php \"\$HOME/$BASE/shared/config.local.php\""
else
    progress_skip "config.local.php du serveur (déjà présent)"
fi

# --- 5. Sauvegarde de la base avant migration -----------------------------
progress_step "Sauvegarde de la base (si elle contient des tables)"
rb_ssh bash -s -- "$BASE" "$release" "$REMOTE_PHP" "$KEEP_BACKUPS" <<'REMOTE'
set -euo pipefail
base=$1; rel=$2; php=$3; keep=$4
umask 077
cfg="$HOME/$base/shared/config.local.php"
cnf=$(mktemp)
trap 'rm -f "$cnf"' EXIT
"$php" -r '$d = (require $argv[1])["db"]; printf("[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n", $d["host"], $d["port"], $d["user"], addcslashes($d["password"], "\"\\"));' "$cfg" > "$cnf"
db=$("$php" -r 'echo (require $argv[1])["db"]["name"];' "$cfg")
tables=$(mariadb --defaults-extra-file="$cnf" -N -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' "$db")
if [ "$tables" -gt 0 ]; then
    mariadb-dump --defaults-extra-file="$cnf" --single-transaction --routines --no-tablespaces "$db" \
        | gzip > "$HOME/$base/backups/pre-$rel.sql.gz"
    echo "Sauvegarde : backups/pre-$rel.sql.gz"
else
    echo "Base vide : pas de sauvegarde nécessaire."
fi
# Rotation : aucune sauvegarde existante n'est normale au premier déploiement
ls -1t "$HOME/$base/backups/"*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -- || true
REMOTE

# --- 6. Migrations, test à blanc, bascule ---------------------------------
progress_step "Migrations (jamais de seed en production)"
rb_ssh "cd \"\$HOME/$BASE/releases/$release\" && $REMOTE_PHP bin/migrate.php"

progress_step "Test à blanc du contrôleur frontal (GET /login en CLI)"
rb_ssh "cd \"\$HOME/$BASE/releases/$release/public\" && $REMOTE_PHP -d display_errors=0 -r '\$_SERVER[\"REQUEST_METHOD\"]=\"GET\"; \$_SERVER[\"REQUEST_URI\"]=\"/login\"; require \"index.php\";' | grep -qi '<html'"

progress_step "Marqueur de release"
rb_ssh "printf '%s\n' \"$release\" > \"\$HOME/$BASE/releases/$release/RELEASE\""

progress_step "Bascule vers ${release}"
rb_ssh bash -s -- "$BASE" "$release" "$DOCROOT_LINK" "$KEEP_RELEASES" <<'REMOTE'
set -euo pipefail
base=$1; rel=$2; docroot_link=$3; keep=$4
cd "$HOME/$base"
ln -sfn "$HOME/$base/releases/$rel" current.new
mv -T current.new current

link="$HOME/$docroot_link"
if [ -L "$link" ]; then
    ln -sfn "$HOME/$base/current/public" "$link"
elif [ -d "$link" ] && { [ -z "$(ls -A "$link")" ] || [ "$(ls -A "$link")" = "cgi-bin" ] && [ -z "$(ls -A "$link/cgi-bin")" ]; }; then
    # dossier créé par cPanel : vide, ou ne contenant qu'un cgi-bin vide
    rm -rf "$link/cgi-bin" 2>/dev/null || true
    rmdir "$link"
    ln -s "$HOME/$base/current/public" "$link"
elif [ ! -e "$link" ]; then
    ln -s "$HOME/$base/current/public" "$link"
else
    echo "ATTENTION : $docroot_link existe et n'est pas vide, lien non créé." >&2
fi

ls -1d releases/*/ | sort | head -n -"$keep" | xargs -r rm -rf --
echo "Release active : $(basename "$(readlink current)")"
REMOTE

# --- 7. Purge d'OPcache puis contrôle de la release servie ----------------
# L'hébergeur valide OPcache sur le chemin du lien symbolique (qui ne change pas) :
# sans purge, l'ancien code compilé reste servi après la bascule.
app_url="${RB_APP_URL:-$(secret APP_URL)}"
progress_step "Purge d'OPcache"
purge="opcache-reset-$(openssl rand -hex 16).php"
rb_ssh "printf '%s' '<?php echo function_exists(\"opcache_reset\") && opcache_reset() ? \"ok\" : \"ko\";' > \"\$HOME/$BASE/current/public/$purge\""
purged=$(curl -s "${app_url%/}/$purge" || true)
rb_ssh "rm -f \"\$HOME/$BASE/current/public/$purge\""
if [ "$purged" != "ok" ]; then
    echo "ATTENTION : purge d'OPcache impossible (réponse '${purged:-vide}'), contrôle de la release servie ci-dessous." >&2
fi

progress_step "Contrôle de la release servie"
expected=$(printf '%s' "$release" | sha256sum | cut -c1-12)
if ! ./bin/verify-release.sh "${app_url%/}/login" "$expected"; then
    echo "La bascule est faite mais le site sert l'ancienne release : lancer bin/rollback.sh ou purger OPcache (voir .claude/deploiement.md)." >&2
    exit 1
fi

progress_done "Déploiement terminé : ${release}"
