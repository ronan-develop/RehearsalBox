#!/usr/bin/env bash
# Copie HORS SERVEUR du dernier dump de la base de production (#167), lancée depuis le poste de dev.
# Ne se connecte jamais à la base et n'affiche jamais le contenu du dump.
#
# Usage : bin/fetch-backup.sh [--pre]
#   --pre : dernier dump d'avant déploiement (pre-*.sql.gz) au lieu du quotidien (db-*.sql.gz)
#
# Variables : RB_SSH_CONFIG (obligatoire), RB_SSH_HOST (rehearsalbox), RB_REMOTE_BASE (rehearsalbox),
#   RB_BACKUP_LOCAL_DIR ($HOME/rehearsalbox-backups, jamais dans le dépôt),
#   RB_BACKUP_LOCAL_KEEP (30 dumps conservés localement, par type)
set -euo pipefail
umask 077

SSH_CONFIG="${RB_SSH_CONFIG:?RB_SSH_CONFIG requis}"
SSH_HOST="${RB_SSH_HOST:-rehearsalbox}"
BASE="${RB_REMOTE_BASE:-rehearsalbox}"
LOCAL_DIR="${RB_BACKUP_LOCAL_DIR:-$HOME/rehearsalbox-backups}"
KEEP="${RB_BACKUP_LOCAL_KEEP:-30}"

# Type de dump : quotidien (db-) ou avant déploiement (pre-)
PREFIX=db
case "${1:-}" in
    "") ;;
    --pre) PREFIX=pre ;;
    *) echo "Usage : $0 [--pre]" >&2; exit 1 ;;
esac

case "$KEEP" in
    ''|*[!0-9]*|0) echo "RB_BACKUP_LOCAL_KEEP doit être un entier supérieur ou égal à 1 (sinon la copie qu'on vient de récupérer serait supprimée)." >&2; exit 1 ;;
esac

# Dépôt = dossier parent de bin/ ; le dossier local ne doit jamais s'y trouver
REPO="$(cd "$(dirname "$0")/.." && pwd -P)"

# Contrôle AVANT de créer quoi que ce soit : `realpath -m` résout aussi un chemin qui n'existe pas encore.
LOCAL_ABS="$(realpath -m -- "$LOCAL_DIR")"

case "$LOCAL_ABS/" in
    "$REPO/"*)
        echo "Refusé : le dossier de sauvegarde ($LOCAL_ABS) est dans le dépôt." >&2
        echo "Choisir un dossier hors du dépôt via RB_BACKUP_LOCAL_DIR." >&2
        exit 1
        ;;
esac

mkdir -p "$LOCAL_DIR"
chmod 700 "$LOCAL_DIR"

# Nom du dernier dump côté serveur (seul le nom de fichier est lu, jamais son contenu)
REMOTE_NAME="$(ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" bash -s -- "$BASE" "$PREFIX" <<'REMOTE'
set -euo pipefail
base=$1
prefix=$2
cd "$HOME/$base/backups" 2>/dev/null || exit 0
last=$(ls -1 "$prefix"-*.sql.gz 2>/dev/null | sort | tail -n 1 || true)
if [ -n "$last" ]; then
    basename "$last"
fi
REMOTE
)" || { echo "Connexion SSH impossible vers $SSH_HOST." >&2; exit 1; }

if [ -z "$REMOTE_NAME" ]; then
    echo "Aucun dump $PREFIX-*.sql.gz sur le serveur ($BASE/backups)." >&2
    exit 1
fi

# Garde-fou : le nom doit correspondre exactement au motif attendu
case "$REMOTE_NAME" in
    "$PREFIX"-*.sql.gz) ;;
    *) echo "Nom de dump inattendu sur le serveur, abandon." >&2; exit 1 ;;
esac

DEST="$LOCAL_DIR/$REMOTE_NAME"

# Jamais d'écrasement : un dump déjà présent localement n'est pas retéléchargé
if [ -e "$DEST" ]; then
    echo "Déjà présent localement : $DEST (rien à télécharger)."
    exit 0
fi

# Téléchargement dans un .partial, renommé seulement une fois l'intégrité vérifiée
PARTIAL="$DEST.partial"
if ! scp -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST:$BASE/backups/$REMOTE_NAME" "$PARTIAL"; then
    rm -f "$PARTIAL"
    echo "Échec du téléchargement de $REMOTE_NAME." >&2
    exit 1
fi

if ! gzip -t "$PARTIAL"; then
    rm -f "$PARTIAL"
    echo "Archive corrompue : $REMOTE_NAME supprimé localement." >&2
    exit 1
fi

chmod 600 "$PARTIAL"
mv "$PARTIAL" "$DEST"

# Rotation locale : on ne garde que les RB_BACKUP_LOCAL_KEEP plus récents de ce type
i=0
while IFS= read -r f; do
    i=$((i + 1))
    if [ "$i" -gt "$KEEP" ]; then
        rm -f -- "$f"
    fi
done < <(ls -1 "$LOCAL_DIR/$PREFIX"-*.sql.gz 2>/dev/null | sort -r || true)

echo "Sauvegarde récupérée : $REMOTE_NAME ($(du -h "$DEST" | cut -f1))"
echo "Dossier local : $LOCAL_DIR"
echo "Les messages sont chiffrés : un dump seul ne suffit pas à les relire, conservez aussi la sauvegarde de la clé (KeePass), à part."
