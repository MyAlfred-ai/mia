-- Options des images d'un assistant (fond transparent, format du fichier,
-- orientation — ImageOptions) : elles précisent celles de l'outil image que
-- l'assistant déclenche, sans toucher à l'outil, partagé par d'autres.
-- NULL = rien de précisé : l'outil décide, comme avant.
ALTER TABLE profiles
    ADD COLUMN image_options TEXT NULL;
