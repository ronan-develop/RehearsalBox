#!/usr/bin/env bash
# Retour arrière applicatif : remet le lien `current` sur la release précédente.
# La base n'est PAS restaurée automatiquement : la commande de restauration est affichée,
# à lancer à la main après vérification.
#
# Variables : RB_SSH_CONFIG (obligatoire), RB_SSH_HOST (rehearsalbox), RB_REMOTE_BASE (rehearsalbox)
set -euo pipefail

SSH_CONFIG="${RB_SSH_CONFIG:?RB_SSH_CONFIG requis}"
SSH_HOST="${RB_SSH_HOST:-rehearsalbox}"
BASE="${RB_REMOTE_BASE:-rehearsalbox}"

ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" bash -s -- "$BASE" <<'REMOTE'
set -euo pipefail
base=$1
cd "$HOME/$base"
current=$(basename "$(readlink current)")
previous=$(ls -1 releases | sort | grep -B1 -x "$current" | head -n1)
if [ -z "$previous" ] || [ "$previous" = "$current" ]; then
    echo "Aucune release précédente disponible." >&2
    exit 1
fi
ln -sfn "$HOME/$base/releases/$previous" current.new
mv -T current.new current
echo "Retour arrière : $current -> $previous"
if [ -f "backups/pre-$current.sql.gz" ]; then
    echo "Sauvegarde d'avant migration disponible : backups/pre-$current.sql.gz"
    echo "Restauration (à lancer à la main si la migration doit être annulée) :"
    echo "  gunzip -c backups/pre-$current.sql.gz | mariadb --defaults-extra-file=<fichier d'options> <base>"
fi
REMOTE
