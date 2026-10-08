#!/bin/bash
# run_worker.sh — Wrapper para ejecución limpia de crons en cPanel / Jailshell.
# Evita errores de parsing de comillas y redirecciones (unexpected EOF) en cPanel.

SCRIPT_DIR="/home/muuk9x7m9to5/public_html/cualify"
WORKER="$1"

if [ -z "$WORKER" ]; then
    echo "Uso: ./run_worker.sh <script_name.php>"
    exit 1
fi

if [ ! -f "$SCRIPT_DIR/$WORKER" ]; then
    echo "El archivo $SCRIPT_DIR/$WORKER no existe."
    exit 1
fi

/usr/bin/php "$SCRIPT_DIR/$WORKER" >> "$SCRIPT_DIR/worker.log" 2>&1
