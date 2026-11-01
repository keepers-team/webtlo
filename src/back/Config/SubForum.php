<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Параметры подраздела, выбранного хранимым.
 */
final class SubForum
{
    /**
     * @param positive-int   $id            ид подраздела
     * @param string         $name          полное название подраздела
     * @param int            $clientId      ид торрент-клиента, куда добавляются раздачи
     * @param string         $label         метка, выставляемая раздачам при добавлении в клиент
     * @param string         $dataFolder    путь хранения файлов
     * @param ?SubFolderType $subFolderType тип создаваемого подкаталога
     * @param bool           $hideTopics    скрывать раздачи подраздела из основного разворота
     * @param bool           $reportExclude исключить подраздел из отправляемых отчётов
     * @param int            $controlPeers  значение для регулировки раздач подраздела (-2 - "пусто", -1 - выключено)
     */
    public function __construct(
        public readonly int            $id,
        public readonly string         $name,
        public readonly int            $clientId,
        public readonly string         $label,
        public readonly string         $dataFolder,
        public readonly ?SubFolderType $subFolderType = null,
        public readonly bool           $hideTopics = true,
        public readonly bool           $reportExclude = false,
        public readonly int            $controlPeers = TopicControl::EmptyValue,
    ) {}

    /**
     * Желаемый путь хранения содержимого раздачи
     * в соответствии с настройками хранимого подраздела.
     */
    public function makeContentPath(int $topicId, string $topicHash): string
    {
        // Если путь хранения не указан - используем пустую строку.
        // Потому что разные клиенты по-разному понимают не абсолютный путь хранения добавляемых раздач.
        if ($this->dataFolder === '') {
            return '';
        }

        // Если создание подкаталога не выбрано, используем указанный путь без изменений.
        if ($this->subFolderType === null) {
            return $this->dataFolder;
        }

        // Подкаталоги для каждой раздачи.
        $subFolders = match ($this->subFolderType) {
            SubFolderType::Topic         => [(string) $topicId],
            SubFolderType::ShardTopic256 => [sprintf('%03d', $topicId % 256), (string) $topicId],
            SubFolderType::Hash          => [$topicHash],
            SubFolderType::ShardHash2    => [substr($topicHash, 0, 2), $topicHash],
        };

        // Попытка угадать, тип ФС торрент-клиента по пути хранения.
        $delimiter = str_contains($this->dataFolder, '/') ? '/' : '\\';

        return implode($delimiter, [$this->dataFolder, ...$subFolders]);
    }
}
