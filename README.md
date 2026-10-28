# NEXO UNAC · versión simple

Tres cosas: **apuntes**, **material** que tú subes y un **tutor** que responde con lo que subiste.

## Primera vez
1. Crea la base: `CREATE DATABASE nexo_lite CHARACTER SET utf8mb4;`
2. Copia `.env.example` a `.env` y revisa `database.default.*`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `QDRANT_URL` y `QDRANT_COLLECTION` (Qdrant debe estar corriendo).
3. `php spark migrate --all` y `php spark db:seed FacultySeeder`
4. `python3 -m venv python/.venv && python/.venv/bin/pip install -r python/requirements.txt`

## Cada día
`./run.sh` y abre http://127.0.0.1:8090. Crea tu cuenta con un correo `@unac.edu.pe`.

## Pruebas
`vendor/bin/phpunit`
