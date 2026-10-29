#!/bin/sh
# Abre NEXO en http://127.0.0.1:8090 (con límite de subida de 25 MB y varios procesos).
# En el contenedor, docker/start.sh pasa NEXO_LISTEN=0.0.0.0:$PORT.
# ponytail: servidor embebido de PHP; pasar a nginx + php-fpm si hay tráfico real.
cd "$(dirname "$0")" || exit 1
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" exec php \
  -d upload_max_filesize=25M -d post_max_size=30M -d max_execution_time=300 -d memory_limit="${PHP_MEMORY_LIMIT:-512M}" \
  -S "${NEXO_LISTEN:-127.0.0.1:8090}" -t public vendor/codeigniter4/framework/system/rewrite.php
