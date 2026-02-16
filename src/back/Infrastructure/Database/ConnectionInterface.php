<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Интерфейс для работы с подключением к БД.
 */
interface ConnectionInterface
{
    /**
     * Получить объект PDO соединения.
     */
    public function getPdo(): PDO;

    /**
     * Выполнить готовый запрос к БД.
     *
     * @param string $sql SQL запрос
     *
     * @throws RuntimeException при ошибке выполнения
     */
    public function executeQuery(string $sql): void;

    /**
     * Подготовить запрос и выполнить с параметрами.
     *
     * @param string         $sql   SQL запрос
     * @param (int|string)[] $param Параметры запроса
     *
     * @throws RuntimeException при ошибке выполнения
     */
    public function executeStatement(string $sql, array $param = []): PDOStatement;

    /**
     * Запрос набора строк.
     *
     * @param string         $sql   SQL запрос
     * @param (int|string)[] $param Параметры запроса
     * @param int            $pdo   Тип возвращаемого результата (PDO::FETCH_*)
     *
     * @return array<int, mixed>
     */
    public function query(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): array;

    /**
     * Запрос одной строки.
     *
     * @param string         $sql   SQL запрос
     * @param (int|string)[] $param Параметры запроса
     *
     * @return ?array<string, mixed> Ассоциативный массив или null если строка не найдена
     */
    public function queryRow(string $sql, array $param = []): ?array;

    /**
     * Запрос одной ячейки.
     *
     * @param string         $sql   SQL запрос
     * @param (int|string)[] $param Параметры запроса
     *
     * @return null|int|string Значение ячейки или null если не найдено
     */
    public function queryColumn(string $sql, array $param = []): mixed;

    /**
     * Запрос count счётчика.
     *
     * @param string         $sql   SQL запрос
     * @param (int|string)[] $param Параметры запроса
     *
     * @return int Количество записей
     */
    public function queryCount(string $sql, array $param = []): int;

    /**
     * Получить количество изменённых строк предыдущим запросом.
     *
     * @return int Количество изменённых строк
     */
    public function queryChanges(): int;

    /**
     * Запрос количество строк в таблице.
     *
     * @param string $table Имя таблицы
     *
     * @return int Количество строк в таблице
     */
    public function selectRowsCount(string $table): int;

    /**
     * Начать транзакцию.
     */
    public function beginTransaction(): void;

    /**
     * Зафиксировать транзакцию.
     */
    public function commitTransaction(): void;

    /**
     * Откатить транзакцию.
     */
    public function rollbackTransaction(): void;
}
