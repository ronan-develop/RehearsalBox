#!/usr/bin/env bash
# Retour arrière applicatif : remet le lien `current` sur la release précédente.
# La base n'est PAS restaurée automatiquement : la commande de restauration est affichée,
# à lancer à la main après vérification.
#
# Variables : RB_SSH_CONFIG (obligatoire), RB_SSH_HOST (rehearsalbox), RB_REMOTE_BASE (rehearsalbox),
#   RB_FORCE_ROLLBACK=1 (revenir à une release qui ne sait pas lire les messages chiffrés : jamais sans savoir pourquoi, #171)
set -euo pipefail

SSH_CONFIG="${RB_SSH_CONFIG:?RB_SSH_CONFIG requis}"
SSH_HOST="${RB_SSH_HOST:-rehearsalbox}"
BASE="${RB_REMOTE_BASE:-rehearsalbox}"

ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" bash -s -- "$BASE" "${RB_FORCE_ROLLBACK:-0}" <<'REMOTE'
set -euo pipefail
base=$1
force=$2
cd "$HOME/$base"
current=$(basename "$(readlink current)")
previous=$(ls -1 releases | sort | grep -B1 -x "$current" | head -n1)
if [ -z "$previous" ] || [ "$previous" = "$current" ]; then
    echo "Aucune release précédente disponible." >&2
    exit 1
fi
# Messages chiffrés (#171) : une release d'avant le chiffrement lirait le chiffré comme du texte. Refus, sauf contournement conscient.
if [ -e "$HOME/$base/shared/message-keys.json" ] && [ ! -f "releases/$previous/bin/message-keys.php" ] && [ "${force:-0}" != "1" ]; then
    echo "Refusé : la release $previous ne sait pas lire les messages chiffrés (pas de bin/message-keys.php)." >&2
    echo "Revenir à une release plus récente, ou forcer en connaissance de cause : RB_FORCE_ROLLBACK=1." >&2
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
