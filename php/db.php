<?php

class Db {
	
	public static $db;
	
	public static function query_database($sql, $param = array(), $fetch = false, $pdo = PDO::FETCH_ASSOC){
		self::$db->sqliteCreateFunction('like', 'Db::lexa_ci_utf8_like', 2);
		$sth = self::$db->prepare($sql);
		if(self::$db->errorCode() != '0000') {
			$error = self::$db->errorInfo();
			throw new Exception( 'SQL ошибка: ' . $error[2] );
		}
		$sth->execute($param);
		return $fetch ? $sth->fetchAll($pdo) : true;
	}
	
	// https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
	public static function lexa_ci_utf8_like ( $mask, $value ) {
	    $mask = str_replace(
	        array("%", "_"),
	        array(".*?", "."),
	        preg_quote($mask, "/")
	    );
	    $mask = "/^$mask$/ui";
	    return preg_match ( $mask, $value );
	}
	
	public static function combine_set( $set ) {
		foreach( $set as $id => &$value ) {
			$value = array_map ( function ($e) {
				return is_numeric($e) ? $e : Db::$db->quote($e);
			}, $value);
			$value = ( empty( $value['id'] ) ? "$id," : "" ) . implode ( ',', $value );
		}
		$statement = 'SELECT ' . implode (' UNION ALL SELECT ', $set);
		return $statement;
	}
	
	public static function create() {
		
		self::$db = new PDO('sqlite:' . dirname(__FILE__) . '/../webtlo.db');
		
		// таблицы
		
		// список подразделов
		self::query_database('CREATE TABLE IF NOT EXISTS Forums (
				id INT NOT NULL PRIMARY KEY,
				na VARCHAR NOT NULL
		)');
		
		// разное
		self::query_database('CREATE TABLE IF NOT EXISTS Other AS SELECT 0 AS "id", 0 AS "ud"');
		
		// топики
		self::query_database('CREATE TABLE IF NOT EXISTS Topics (
				id INT NOT NULL PRIMARY KEY,
				ss INT NOT NULL,
				na VARCHAR NOT NULL,
				hs VARCHAR NOT NULL,
				se INT NOT NULL,
				si INT NOT NULL,
				st INT NOT NULL,
				rg INT NOT NULL,
				dl INT NOT NULL DEFAULT 0
		)');
		
		// средние сиды
		self::query_database('CREATE TABLE IF NOT EXISTS Seeders (
			id INT NOT NULL PRIMARY KEY,
			d0 INT, d1 INT,d2 INT,d3 INT,d4 INT,d5 INT,d6 INT,
			d7 INT,d8 INT,d9 INT,d10 INT,d11 INT,d12 INT,d13 INT,
			d14 INT,d15 INT,d16 INT,d17 INT,d18 INT,d19 INT,
			d20 INT,d21 INT,d22 INT,d23 INT,d24 INT,d25 INT,
			d26 INT,d27 INT,d28 INT,d29 INT,
			q0 INT, q1 INT,q2 INT,q3 INT,q4 INT,q5 INT,q6 INT,
			q7 INT,q8 INT,q9 INT,q10 INT,q11 INT,q12 INT,q13 INT,
			q14 INT,q15 INT,q16 INT,q17 INT,q18 INT,q19 INT,
			q20 INT,q21 INT,q22 INT,q23 INT,q24 INT,q25 INT,
			q26 INT,q27 INT,q28 INT,q29 INT
		)');
		
		// хранители
		self::query_database('CREATE TABLE IF NOT EXISTS Keepers (
			id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
			topic_id INTEGER NOT NULL, nick VARCHAR NOT NULL
		)');
		
		// триггеры
		
		// запретить дубликаты в keepers
		self::query_database('CREATE TRIGGER IF NOT EXISTS Keepers_not_duplicate
			BEFORE INSERT ON Keepers
	        WHEN EXISTS (SELECT id FROM Keepers WHERE topic_id = NEW.topic_id AND nick = NEW.nick)
			BEGIN
			    SELECT RAISE(IGNORE);
			END;
		');
		
		// обновить при вставке такой же записи
		self::query_database('CREATE TRIGGER IF NOT EXISTS Forums_update
			BEFORE INSERT ON Forums
	        WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)
			BEGIN
			    UPDATE Forums SET na = NEW.na
			    WHERE id = NEW.id;
			    SELECT RAISE(IGNORE);
			END;
		');
		
		// совместимость со старыми версиями базы данных
		$version = self::query_database('PRAGMA user_version', array(), true);
		
		if($version[0]['user_version'] < 1){
			self::query_database('ALTER TABLE Topics ADD COLUMN rt INT DEFAULT 1');
			self::query_database('ALTER TABLE Topics ADD COLUMN ds INT DEFAULT 0');
			self::query_database('ALTER TABLE Topics ADD COLUMN cl VARCHAR');
			self::query_database('PRAGMA user_version = 1');
		}
		
		if($version[0]['user_version'] < 2) {
			self::query_database('DROP TRIGGER IF EXISTS Seeders_update');
			self::query_database('ALTER TABLE Other ADD COLUMN se INT DEFAULT 0');
			self::query_database('ALTER TABLE Forums ADD COLUMN qt INT');
			self::query_database('ALTER TABLE Forums ADD COLUMN si INT');
			self::query_database('ALTER TABLE Topics RENAME TO TopicsTemp');
			
			self::query_database('CREATE TABLE IF NOT EXISTS Topics (
			    id INT PRIMARY KEY NOT NULL,
			    ss INT,
			    na VARCHAR,
			    hs VARCHAR,
			    se INT,
			    si INT,
			    st INT,
			    rg INT,
			    dl INT,
			    qt INT,
			    ds INT,
			    cl VARCHAR
			)');
			
			self::query_database('INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,dl,qt,ds,cl)
				SELECT id,ss,na,hs,se,si,st,rg,dl,rt,ds,cl FROM TopicsTemp'
			);
			
			self::query_database('DROP TABLE TopicsTemp');
			
			self::query_database('CREATE TRIGGER IF NOT EXISTS delete_seeders
				AFTER DELETE ON Topics FOR EACH ROW
				BEGIN
					DELETE FROM Seeders WHERE id = OLD.id;
				END;'
			);
			
			self::query_database('CREATE TRIGGER IF NOT EXISTS insert_seeders
				AFTER INSERT ON Topics
				BEGIN
					INSERT INTO Seeders (id) VALUES (NEW.id);
				END;
			');
			
			self::query_database('CREATE TRIGGER IF NOT EXISTS topic_exists
				BEFORE INSERT ON Topics
				WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)
				BEGIN
					UPDATE Topics SET
					    ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,
					    na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,
					    hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,
					    se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,
					    si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,
					    st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,
					    rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,
					    dl = CASE WHEN NEW.dl IS NULL THEN dl ELSE NEW.dl END,
					    qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,
					    ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,
					    cl = CASE WHEN NEW.cl IS NULL THEN cl ELSE NEW.cl END
					WHERE id = NEW.id;
					SELECT RAISE(IGNORE);
				END;'
			);
			
			self::query_database('CREATE TRIGGER IF NOT EXISTS transfer_seeders
				AFTER UPDATE ON Topics
				WHEN NEW.ds <> OLD.ds
				BEGIN
					UPDATE Seeders SET
						d0 = OLD.se, d1 = d0, d2 = d1, d3 = d2, d4 = d3,
						d5 = d4, d6 = d5, d7 = d6, d8 = d7, d9 = d8,
						d10 = d9, d11 = d10, d12 = d11, d13 = d12, d14 = d13,
						d15 = d14, d16 = d15, d17 = d16, d18 = d17, d19 = d18,
						d20 = d19, d21 = d20, d22 = d21, d23 = d22, d24 = d23,
						d25 = d24, d26 = d25, d27 = d26, d28 = d27, d29 = d28,
						q0 = OLD.qt, q1 = q0, q2 = q1, q3 = q2, q4 = q3,
						q5 = q4, q6 = q5, q7 = q6, q8 = q7, q9 = q8,
						q10 = q9, q11 = q10, q12 = q11, q13 = q12, q14 = q13,
						q15 = q14, q16 = q15, q17 = q16, q18 = q17, q19 = q18,
						q20 = q19, q21 = q20, q22 = q21, q23 = q22, q24 = q23,
						q25 = q24, q26 = q25, q27 = q26, q28 = q27, q29 = q28
					WHERE id = NEW.id;
				END;'
			);
			
			self::query_database('PRAGMA user_version = 2');
			
		}
		
		if( $version[0]['user_version'] < 3 ) {
			
			self::query_database( 'CREATE TABLE IF NOT EXISTS Blacklist (
				id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
				topic_id INTEGER NOT NULL
			)' );
			
			self::query_database( 'DROP TRIGGER delete_seeders' );
			
			self::query_database('CREATE TRIGGER IF NOT EXISTS delete_topics
				AFTER DELETE ON Topics FOR EACH ROW
				BEGIN
					DELETE FROM Seeders WHERE id = OLD.id;
					DELETE FROM Blacklist WHERE topic_id = OLD.id;
				END;'
			);
			
			self::query_database('PRAGMA user_version = 3');
			
		}
		
	}
	
}

// подключаемся к базе
Db::create();

?>
