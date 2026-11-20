-- Extensiones que usan las migraciones. PostGIS no: el stack de carga no lo necesita y las imagenes antiguas
-- del proyecto no lo traen bien enlazado (docker/postgres/Dockerfile).
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
