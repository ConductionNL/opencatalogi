-- Enable PostgreSQL extensions used by OpenRegister when installed on top of
-- this OpenCatalogi dev stack. Ships in the pgvector/pgvector:pg16 image, so
-- CREATE EXTENSION is all that's needed.
--
--   pg_trgm — trigram similarity, used by MagicSearchHandler for _fuzzy=true.
--   vector  — pgvector, used by OpenRegister's semantic search / embeddings.
--
-- Runs once at first PostgreSQL init (before Nextcloud creates its schema).

CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS vector;
