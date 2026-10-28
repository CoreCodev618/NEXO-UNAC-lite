#!/bin/sh
# Arranque del contenedor (Render): CA de MySQL, migraciones y servidor.
set -e
cd /app

# Aiven exige TLS: el certificado llega como texto en DB_SSL_CA.
if [ -n "$DB_SSL_CA" ]; then
    printf '%s\n' "$DB_SSL_CA" > /tmp/db-ca.pem
    export DB_SSL_CA_FILE=/tmp/db-ca.pem
fi

# Render publica la URL del servicio en RENDER_EXTERNAL_URL.
export app_baseURL="${app_baseURL:-${RENDER_EXTERNAL_URL:-http://localhost:${PORT:-8080}}/}"

mkdir -p writable/cache writable/logs writable/session writable/uploads/rag writable/debugbar
php spark migrate --all
php spark db:seed FacultySeeder

NEXO_LISTEN="0.0.0.0:${PORT:-8080}" exec ./run.sh
