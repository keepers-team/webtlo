-- Удаляем давно не нужную таблицу
DROP TABLE IF EXISTS ForumsOptions;
DROP TRIGGER IF EXISTS forums_options_exists;

-- Убираем триггер сдвига история средних сидов.
DROP TRIGGER IF EXISTS seeders_transfer;

-- Запишем текущую версию БД.
PRAGMA user_version = 15;
