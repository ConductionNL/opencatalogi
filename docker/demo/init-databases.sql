-- OpenCatalogi demo rig: initialize both databases with required extensions.
--
-- Runs on the default database (main) first, then creates and configures peer.
-- Modelled on tests/federation/init-databases.sql — OpenRegister needs vector
-- and pg_trgm present at database level, and a database created without them
-- fails at the first search rather than at install, which is much later and
-- much less obviously the cause.

-- Extensions for `main` (the default database).
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

ALTER DATABASE main SET pg_trgm.similarity_threshold = 0.3;
ALTER DATABASE main SET maintenance_work_mem = '256MB';

-- Create and configure `peer`.
CREATE DATABASE peer OWNER nextcloud;

\connect peer;

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

ALTER DATABASE peer SET pg_trgm.similarity_threshold = 0.3;
ALTER DATABASE peer SET maintenance_work_mem = '256MB';

DO $$
BEGIN
    RAISE NOTICE 'OpenCatalogi demo databases initialized: main + peer';
END $$;
