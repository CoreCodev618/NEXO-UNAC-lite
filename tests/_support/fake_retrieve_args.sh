#!/bin/sh
# Simula python/retrieve.py: devuelve un fragmento cuyo texto son los argumentos recibidos.
printf '[{"score":0.5,"payload":{"doc_id":1,"text":"%s","source":"a.pdf","chunk_key":"a"}}]\n' "$*"
