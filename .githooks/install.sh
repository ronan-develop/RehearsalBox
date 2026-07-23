#!/usr/bin/env bash
# À lancer une fois après clone : active les git hooks versionnés du projet.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
git config core.hooksPath .githooks
echo "✅ Git hooks activés (.githooks/)."
