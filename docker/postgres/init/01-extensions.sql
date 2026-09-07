-- Extensions required by SchoolFlow.
--   pgcrypto  : gen_random_uuid() for database-side UUID defaults
--   citext    : case-insensitive e-mail uniqueness
--   pg_trgm   : trigram indexes powering the global search
--   unaccent  : accent-insensitive matching for French/Kreyòl names
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
