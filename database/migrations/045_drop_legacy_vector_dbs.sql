-- Suppression du RAG vectoriel externe legacy (Chroma/Qdrant distants, jamais
-- déployé), remplacé par les bases de connaissances (044) sur Qdrant embarqué.
-- Ordre impératif : FK avant colonne avant table.

ALTER TABLE profiles DROP FOREIGN KEY fk_profiles_vector_db;
ALTER TABLE profiles DROP COLUMN vector_db_id;
DROP TABLE IF EXISTS vector_databases;
