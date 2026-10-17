#!/usr/bin/env bash
# Copie HORS SERVEUR d'un dossier de fichiers de la production (#362), lancée depuis le poste de dev : les relevés de compte de l'association
# (`accounting-statements`) ou les documents de groupes (`group-documents`) ne sont PAS dans un dump de la base.
# Archive tar.gz envoyée en flux par SSH (aucune dépendance côté serveur), vérifiée avant d'être rangée, jamais dans le dépôt.
# Les relevés contiennent des IBAN et des noms de tiers : le dossier local est en 0700 ; le ranger sur un disque chiffré.
#
# Usage : bin/fetch-files.sh [accounting-statements|group-documents]   (défaut : accounting-statements)
# Variables : RB_SSH_CONFIG (obligatoire), RB_SSH_HOST (rehearsalbox), RB_REMOTE_BASE (rehearsalbox),
#   RB_BACKUP_LOCAL_DIR ($HOME/rehearsalbox-backups, jamais dans le dépôt), RB_BACKUP_LOCAL_KEEP (30 archives par dossier)
set -euo pipefail
umask 077

SSH_CONFIG="${RB_SSH_CONFIG:?RB_SSH_CONFIG requis}"
SSH_HOST="${RB_SSH_HOST:-rehearsalbox}"
BASE="${RB_REMOTE_BASE:-rehearsalbox}"
LOCAL_DIR="${RB_BACKUP_LOCAL_DIR:-$HOME/rehearsalbox-backups}"
KEEP="${RB_BACKUP_LOCAL_KEEP:-30}"

# Liste fermée : jamais un nom venu de l'extérieur dans la commande distante
FOLDER="${1:-accounting-statements}"
case "$FOLDER" in
    accounting-statements|group-documents) ;;
    *) echo "Usage : $0 [accounting-statements|group-documents]" >&2; exit 1 ;;
esac

case "$KEEP" in
    ''|*[!0-9]*|0) echo "RB_BACKUP_LOCAL_KEEP doit être un entier supérieur ou égal à 1 (sinon l'archive qu'on vient de récupérer serait supprimée)." >&2; exit 1 ;;
esac

REPO="$(cd "$(dirname "$0")/.." && pwd -P)"
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

DEST="$LOCAL_DIR/$FOLDER-$(date -u +%Y%m%d%H%M%S).tgz"
PARTIAL="$DEST.partial"

# Le dossier peut ne pas exister encore (aucun relevé déposé) : ce n'est pas une erreur. Une panne de connexion, elle, en est une.
PRESENT="$(ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" "if [ -d \"\$HOME/$BASE/shared/storage/$FOLDER\" ]; then echo present; else echo absent; fi")" \
    || { echo "Connexion SSH impossible vers $SSH_HOST." >&2; exit 1; }
if [ "$PRESENT" != "present" ]; then
    echo "Rien à copier : $FOLDER n'existe pas encore sur le serveur."
    exit 0
fi

if ! ssh -F "$SSH_CONFIG" -o BatchMode=yes "$SSH_HOST" "tar -C \"\$HOME/$BASE/shared/storage\" -czf - \"$FOLDER\"" > "$PARTIAL"; then
    rm -f "$PARTIAL"
    echo "Échec de la copie de $FOLDER." >&2
    exit 1
fi

if ! tar -tzf "$PARTIAL" > /dev/null; then
    rm -f "$PARTIAL"
    echo "Archive corrompue : copie supprimée." >&2
    exit 1
fi

chmod 600 "$PARTIAL"
mv "$PARTIAL" "$DEST"

# Rotation locale : on ne garde que les RB_BACKUP_LOCAL_KEEP plus récentes de ce dossier
i=0
while IFS= read -r f; do
    i=$((i + 1))
    if [ "$i" -gt "$KEEP" ]; then
        rm -f -- "$f"
    fi
done < <(ls -1 "$LOCAL_DIR/$FOLDER"-*.tgz 2>/dev/null | sort -r || true)

echo "Fichiers récupérés : $(basename "$DEST") ($(du -h "$DEST" | cut -f1), $(tar -tzf "$DEST" | grep -vc '/$' || true) fichier(s))"
echo "Dossier local : $LOCAL_DIR (à ranger sur un disque chiffré : IBAN et noms de tiers)."
