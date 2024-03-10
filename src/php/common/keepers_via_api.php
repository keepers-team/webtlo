<?php

include_once __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;


$app = AppContainer::create('keepers.log');

Timers::start('update_keepers');

// получение настроек
$cfg = $app->getLegacyConfig();

$logger = $app->getLogger();

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическое обновление списков других хранителей отключено в настройках.');
    }
}

$user = ConfigValidate::checkUser($cfg);

$logger->info('Начато обновление списков раздач хранителей...');

// Параметры таблиц.
$tabForumsOptions = CloneTable::create('ForumsOptions', [], 'forum_id');
$tabKeepersList   = CloneTable::create(
    'KeepersLists',
    ['topic_id', 'keeper_id', 'keeper_name', 'posted', 'complete'],
    'topic_id'
);

// Список ид хранимых подразделов.
$keptForums = array_keys($cfg['subsections'] ?? []);
$forumKeys  = KeysObject::create($keptForums);

// Удалим данные о нехранимых более подразделах.
Db::query_database(
    "DELETE FROM $tabForumsOptions->origin WHERE $tabForumsOptions->primary NOT IN ($forumKeys->keys)",
    $forumKeys->values
);


// Список ид обновлений подразделов.
$keptForumsUpdate = array_map(fn($el) => 100000 + $el, $keptForums);

$updateStatus = new LastUpdate($keptForumsUpdate);
$updateStatus->checkMarkersLess(7200);

// Если количество маркеров не совпадает, обнулим имеющиеся.
if ($updateStatus->getLastCheckStatus() === UpdateStatus::MISSED) {
    Db::query_database("DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000");
}
// Проверим минимальную дату обновления данных других хранителей.
if ($updateStatus->getLastCheckStatus() === UpdateStatus::EXPIRED) {
    $logger->warning(
        sprintf(
            'Notice: Обновление списков других хранителей и сканирование форума не требуется. Дата последнего выполнения %s',
            date("d.m.y H:i", $updateStatus->getLastCheckUpdateTime())
        )
    );

    return;
}

$apiReportClient = $app->getApiReportClient();

$apiClient = $app->getApiClient();
// Получаем список хранителей форума.
$response = $apiClient->getKeepersList();
if ($response instanceof ApiError) {
    $logger->error(
        sprintf('Не получены данные о хранителях (%d: %s).', $response->code, $response->text)
    );

    return;
}

/** @var KeeperData[] $keepers */
$keepers = array_combine(array_map(fn($el) => $el->keeperId, $response->keepers), $response->keepers);

$forumsScanned = 0;
$keeperIds     = [];
$forumsParams  = [];

$reportColumns = ['status','last_update_time', 'last_seeded_time'];
if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forumId => $subsection) {
        $forumReports = $apiReportClient->getForumReports($forumId, $reportColumns);
        foreach ($forumReports as $keeperReport) {
            // Пропускаем раздачи несуществующих хранителей.
            if (empty($keeper = $keepers[(int)$keeperReport['user_id']])) {
                continue;
            }
            // Пропускаем себя в списке хранителей подраздела.
            if ($keeper->keeperId === $user->userId) {
                continue;
            }
            // Пропускаем раздачи кандидатов в хранители.
            if ($keeper->isCandidate) {
                continue;
            }

            $keeperIds[] = $keeper->keeperId;

            $preparedTopics = [];

            $keptTopics = array_map(fn($el) => array_combine($keeperReport['columns'], $el),$keeperReport['kept_releases']);
            foreach ($keptTopics as $keptTopic) {
                if (!($keptTopic['status'] & KeepingStatuses::ReportedByApi->value)) {
                    continue;
                }

                // Пропускаем раздачи, у которых нет данных о дате включения в отчёт.
                $posted = max($keptTopics['last_update_time'], $keptTopics['last_seeded_time']);
                if (empty($posted)) {
                    continue;
                }

                $complete = 1;
                if ($keptTopic['status'] & KeepingStatuses::Downloading->value) {
                    $complete = 0;
                }
                $preparedTopics[] = array_combine($tabKeepersList->keys, [
                    $keptTopic['topic_id'],
                    $keeper->keeperId,
                    $keeper->keeperName,
                    (new DateTimeImmutable($posted))->getTimestamp(),
                    $complete,
                ]);
            }
            $tabKeepersList->cloneFillChunk($preparedTopics, 200);
        }
        $forumsScanned++;
    }
}


// записываем изменения в локальную базу
$count_kept_topics = $tabKeepersList->cloneCount();
if ($count_kept_topics > 0) {
    $logger->info(
        sprintf(
            'Просканировано подразделов: %d шт, хранителей: %d, хранимых раздач: %d шт.',
            $forumsScanned,
            count(array_unique($keeperIds)),
            $count_kept_topics
        )
    );
    $logger->info('Запись в базу данных списков раздач хранителей...');

    // Переносим данные из временной таблицы в основную.
    $tabKeepersList->moveToOrigin();

    // Удаляем неактуальные записи списков.
    Db::query_database(
        "DELETE FROM $tabKeepersList->origin WHERE topic_id || keeper_id NOT IN (
            SELECT upd.topic_id || upd.keeper_id
            FROM $tabKeepersList->clone AS tmp
            LEFT JOIN $tabKeepersList->origin AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
            WHERE upd.topic_id IS NOT NULL
        )"
    );
}
$logger->info('Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
