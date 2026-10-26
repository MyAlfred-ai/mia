-- avatar_url peut dépasser 500 chars (ex: URLs Google)
ALTER TABLE users MODIFY COLUMN avatar_url TEXT DEFAULT NULL;
