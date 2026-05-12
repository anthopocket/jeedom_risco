#!/bin/bash
# ============================================================
# Installation des dépendances du plugin Risco pour Jeedom
# Conforme au pattern Dyson / plugin-template Jeedom
#
# Usage : bash install_dep.sh [progress_file]
# ============================================================

PLUGIN_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
VENV_DIR="$PLUGIN_DIR/venv"
PROGRESS_FILE="${1:-/tmp/jeedom/risco/dependency}"

mkdir -p "$(dirname "$PROGRESS_FILE")"
echo 0 > "$PROGRESS_FILE"

# ── Couleurs ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

log()  { echo -e "${GREEN}[risco]${NC} $1"; }
warn() { echo -e "${YELLOW}[risco]${NC} $1"; }
fail() { echo -e "${RED}[risco] ERREUR${NC} : $1"; echo 100 > "$PROGRESS_FILE"; exit 1; }

log "======================================================"
log " Installation des dépendances du plugin Risco"
log "======================================================"
log "Répertoire plugin : $PLUGIN_DIR"
log "Environnement venv : $VENV_DIR"

# ── 1. Python3 ────────────────────────────────────────────────────────────────
echo 5 > "$PROGRESS_FILE"
PYTHON3=$(which python3 2>/dev/null)
[ -z "$PYTHON3" ] && fail "Python3 n'est pas installé sur ce système."
log "Python3 trouvé : $PYTHON3  ($($PYTHON3 --version 2>&1))"

# ── 2. python3-venv (absent sur Debian 11 base) ───────────────────────────────
echo 10 > "$PROGRESS_FILE"
if ! $PYTHON3 -m venv --help > /dev/null 2>&1; then
    warn "python3-venv absent, installation via apt..."
    apt-get update -qq 2>&1 | tail -3
    apt-get install -y python3-venv 2>&1 \
        || fail "Impossible d'installer python3-venv via apt. Lancez manuellement : sudo apt-get install python3-venv"
    $PYTHON3 -m venv --help > /dev/null 2>&1 \
        || fail "python3-venv toujours indisponible après installation."
fi
log "python3-venv OK"

# ── 3. Permissions AVANT création du venv ─────────────────────────────────────
echo 15 > "$PROGRESS_FILE"
chown -R www-data:www-data "$PLUGIN_DIR" 2>/dev/null || true

# ── 4. Création / mise à jour du virtualenv ───────────────────────────────────
echo 20 > "$PROGRESS_FILE"
if [ ! -d "$VENV_DIR" ] || [ ! -f "$VENV_DIR/bin/python3" ]; then
    log "Création du virtualenv..."
    $PYTHON3 -m venv "$VENV_DIR" 2>&1 \
        || fail "Impossible de créer le virtualenv dans $VENV_DIR"
else
    log "Virtualenv déjà présent, mise à jour si nécessaire."
fi
log "Virtualenv Python : $($VENV_DIR/bin/python3 --version 2>&1)"

# ── 5. pip à jour dans le venv ────────────────────────────────────────────────
echo 30 > "$PROGRESS_FILE"
log "Mise à jour de pip..."
"$VENV_DIR/bin/pip" install --upgrade pip setuptools wheel --quiet 2>&1

# ── 6. Installation de pyrisco ────────────────────────────────────────────────
echo 50 > "$PROGRESS_FILE"
log "Installation de pyrisco..."
"$VENV_DIR/bin/pip" install pyrisco --quiet 2>&1 \
    || fail "Impossible d'installer pyrisco."

# ── 7. Vérification de l'import pyrisco ──────────────────────────────────────
echo 80 > "$PROGRESS_FILE"
"$VENV_DIR/bin/python3" -c "import pyrisco; from pyrisco import RiscoLocal; print('pyrisco OK')" 2>&1 \
    || fail "pyrisco installé mais import impossible — voir ci-dessus."

# ── 8. Rapport final ──────────────────────────────────────────────────────────
echo 90 > "$PROGRESS_FILE"
log ""
log "Paquets installés dans le venv :"
"$VENV_DIR/bin/pip" list 2>&1 | grep -E "pyrisco|pip|setuptools"
log ""

# ── 9. Permissions finales ────────────────────────────────────────────────────
chown -R www-data:www-data "$PLUGIN_DIR" 2>/dev/null || true
chmod -R 755 "$PLUGIN_DIR" 2>/dev/null || true

echo 100 > "$PROGRESS_FILE"
log "======================================================"
log " Dépendances installées avec succès !"
log "======================================================"
# Supprimer le fichier progress — Jeedom détecte la fin par son absence
rm -f "$PROGRESS_FILE"
exit 0
