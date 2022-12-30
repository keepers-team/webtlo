--
-- user_version = 1
--

ALTER TABLE Topics
    ADD COLUMN rt INT DEFAULT 1;

ALTER TABLE Topics
    ADD COLUMN ds INT DEFAULT 0;

ALTER TABLE Topics
    ADD COLUMN cl VARCHAR;

PRAGMA user_version = 1;
