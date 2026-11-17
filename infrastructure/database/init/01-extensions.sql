-- Executado apenas na primeira inicialização do volume do PostgreSQL.
-- Habilita as extensões necessárias no banco da aplicação.

CREATE EXTENSION IF NOT EXISTS vector;    -- pgvector: busca semântica (memória/conhecimento do NEXO)
CREATE EXTENSION IF NOT EXISTS pgcrypto;  -- gen_random_uuid() e utilitários criptográficos
CREATE EXTENSION IF NOT EXISTS citext;    -- e-mails e identificadores case-insensitive
