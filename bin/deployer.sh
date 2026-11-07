#!/bin/bash
# Mise à jour automatique depuis GitHub (tâche cron o2switch, toutes les 5 minutes) :
#   */5 * * * * /bin/bash $HOME/ascs/bin/deployer.sh
# Ne touche ni à .env ni à storage/ (ignorés par git). Les nouvelles tables sont créées
# par l'application elle-même à la première page ouverte.
set -u
cd "$(dirname "$0")/.." || exit 1
JOURNAL=storage/deploiement.log
BRANCHE=$(git rev-parse --abbrev-ref HEAD 2>/dev/null) || { echo "$(date '+%d/%m/%Y %H:%M') pas un dépôt git" >> "$JOURNAL"; exit 1; }
git fetch --quiet origin "$BRANCHE" 2>>"$JOURNAL" || { echo "$(date '+%d/%m/%Y %H:%M') ÉCHEC : GitHub injoignable" >> "$JOURNAL"; exit 1; }
if [ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCHE")" ]; then
    exit 0  # rien de nouveau
fi
if git merge --ff-only --quiet "origin/$BRANCHE" 2>>"$JOURNAL"; then
    echo "$(date '+%d/%m/%Y %H:%M') mis à jour en version $(cat VERSION)" >> "$JOURNAL"
else
    echo "$(date '+%d/%m/%Y %H:%M') ÉCHEC : fichiers modifiés sur le serveur, mise à jour bloquée (voir git status)" >> "$JOURNAL"
    exit 1
fi
