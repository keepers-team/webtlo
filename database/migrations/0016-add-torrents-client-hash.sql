-- Храним отдельно хеш раздачи на форуме и идентификатор раздачи в торрент-клиенте.
ALTER TABLE Torrents ADD COLUMN client_hash TEXT NOT NULL DEFAULT '';

-- Идентификатор клиента оставляем пустым до следующего сканирования или ручного действия.
-- Для гибридных раздач он может отличаться от хеша раздачи на форуме.

-- Служебные сведения о раздаче удаляем только после удаления её из всех клиентов.
DROP TRIGGER IF EXISTS remove_unregistered_topics;
CREATE TRIGGER remove_unregistered_topics
    AFTER DELETE ON Torrents FOR EACH ROW
    WHEN NOT EXISTS (SELECT 1 FROM Torrents WHERE info_hash = OLD.info_hash)
BEGIN
    DELETE FROM TopicsUnregistered WHERE info_hash = OLD.info_hash;
END;

DROP TRIGGER IF EXISTS remove_untracked_topics;
CREATE TRIGGER remove_untracked_topics
    AFTER DELETE ON Torrents FOR EACH ROW
    WHEN NOT EXISTS (SELECT 1 FROM Torrents WHERE info_hash = OLD.info_hash)
BEGIN
    DELETE FROM TopicsUntracked WHERE info_hash = OLD.info_hash;
END;

-- Запишем текущую версию БД.
PRAGMA user_version = 16;
