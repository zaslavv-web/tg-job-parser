#!/usr/bin/env bash
# Склейка рантайма static-php-cli (micro.sfx) и архива приложения в один исполняемый файл.
#   build/make-executable.sh <micro.sfx> <app.phar> <выходной файл>
set -euo pipefail
cat "$1" "$2" > "$3"
chmod +x "$3"
echo "Готово: $3 ($(du -h "$3" | cut -f1))"
