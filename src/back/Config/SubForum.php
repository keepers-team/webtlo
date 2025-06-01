<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Параметры подраздела, выбранного хранимым.
 */
final class SubForum
{
    /**
     * @param int            $id            ид подраздела
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
        public readonly int            $controlPeers = -2,
    ) {}
}
