#!/usr/bin/env bash
# Vérifie que l'URL sert bien la release attendue (en-tête X-Release).
# Usage : verify-release.sh <url> <empreinte-attendue> [tentatives=5]
# Échoue bruyamment si l'ancienne release (ou aucun marqueur) est servie.
set -uo pipefail

url=${1:?url requise}
expected=${2:?empreinte attendue requise}
tries=${3:-5}
pause=${RB_VERIFY_SLEEP:-5}

served=""
for ((i = 1; i <= tries; i++)); do
    served=$(curl -s -o /dev/null -D - "$url" | tr -d '\r' | awk -F': *' 'tolower($1)=="x-release" {print $2; exit}')
    if [ "$served" = "$expected" ]; then
        echo "Release servie vérifiée (X-Release ${served})."
        exit 0
    fi
    sleep "$pause"
done

echo "ÉCHEC : l'ancienne release est encore servie (X-Release attendu ${expected}, reçu '${served:-aucun}')." >&2
exit 1
