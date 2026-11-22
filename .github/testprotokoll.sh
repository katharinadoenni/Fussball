#!/usr/bin/env bash
# Schreibt Testprotokoll und Vorschau-Seiten in den Branch «testprotokoll» (überschreibt den letzten Stand).
set -euo pipefail
TMP="$(mktemp -d)"
cp testprotokoll.txt "$TMP/" 2>/dev/null || echo "Kein Testprotokoll vorhanden" > "$TMP/testprotokoll.txt"
[ -d vorschau ] && cp -r vorschau "$TMP/" || true
{ echo "Branch: ${GITHUB_REF_NAME}"; echo "Commit: ${GITHUB_SHA}"; echo "Lauf: ${GITHUB_SERVER_URL}/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}"; } > "$TMP/lauf.txt"
git config user.name "github-actions[bot]"
git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
git checkout -q --orphan testprotokoll-neu
git rm -rqf . >/dev/null 2>&1 || true
git clean -fdxq
cp -r "$TMP"/. .
git add -A
git commit -qm "Testprotokoll ${GITHUB_REF_NAME} ${GITHUB_SHA::7}"
git push -qf origin HEAD:testprotokoll
