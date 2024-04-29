<?php

use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\Forum\AccessCheck;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Legacy\Proxy;

include_once dirname(__FILE__) . '/../phpQuery.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

class Reports
{
    /**
     * @var CurlHandle
     */
    protected $ch;

    protected string $forum_url;

    protected Credentials $user;

    protected bool $blocking_send;

    protected string $blocking_reason;

    private ?DateTimeImmutable $compareDate = null;

    private int $StatBotUserId = 46790908;

    private array $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private array $months_ru = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

    /**
     * @throws Exception
     */
    public function __construct(string $forum_url, Credentials $user)
    {
        // Проверяем наличие сессии или пробуем авторизоваться.
        $this->user = UserDetails::checkSession($forum_url, $user);

        $this->forum_url = $forum_url;

        $this->ch = curl_init();

        Log::append(sprintf('Используется зеркало для форума: %s. %s',
            $forum_url,
            !empty(Proxy::$proxy['forum']) ? Proxy::getInfo() : 'Без прокси.'
        ));
    }

    public function curl_setopts($options)
    {
        curl_setopt_array($this->ch, $options);
    }

    private function make_request($url, $fields = [])
    {
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_URL => $url,
            CURLOPT_COOKIE => UserDetails::$cookie,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
        ]);
        curl_setopt_array($this->ch, Proxy::$proxy['forum']);

        $try_number = 1; // номер попытки
        $try = 3; // кол-во попыток
        while (true) {
            $data = curl_exec($this->ch);
            $completeData = strpos($data, '</html>');
            if (
                $data === false
                || $completeData === false
            ) {
                $http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                if (
                    $http_code < 300
                    && $try_number <= $try
                ) {
                    Log::append("Повторная попытка $try_number/$try получить данные.");
                    sleep(5);
                    $try_number++;
                    continue;
                }
                throw new Exception("CURL ошибка: " . curl_error($this->ch) . " [$http_code]");
            }
            return $data;
        }
    }

    // поиск темы со списком
    public function search_topic_id($title, $forum_id = 1584)
    {
        if (empty($title)) {
            return false;
        }
        $title = html_entity_decode($title);
        $search = preg_replace('/.*» ?(.*)$/', '$1', $title);
        if (mb_strlen($search, 'UTF-8') < 2) {
            return false;
        }
        $title = explode(' » ', $title);
        $i = 0;
        $page = 1;
        $page_id = "";
        while ($page > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/search.php?id=$page_id",
                [
                    'nm' => mb_convert_encoding("$search", 'Windows-1251', 'UTF-8'),
                    'start' => $i,
                    'f' => $forum_id,
                ]
            );
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $topic_main = $html->find('table.forum > tbody:first');
            $pages = $html->find('a.pg:last')->prev();
            if (
                !empty($pages)
                && $i == 0
            ) {
                $page = $html->find('a.pg:last')->prev()->text();
                $page_id = $html->find('a.pg:last')->attr('href');
                $page_id = preg_replace('/.*id=([^\&]*).*/', '$1', $page_id);
            }
            unset($html);
            if (!empty($topic_main)) {
                $topic_main = pq($topic_main);
                foreach ($topic_main->find('tr.tCenter') as $row) {
                    $row = pq($row);
                    $topic_title = $row->find('a.topictitle')->text();
                    if (!empty($topic_title)) {
                        $topic_title = explode('»', str_replace('[Список] ', '', $topic_title));
                        $topic_title = array_map('trim', $topic_title);
                        if ($title === $topic_title) {
                            $topic_id = $row->find('a.topictitle')->attr('href');
                            $topic_id = preg_replace('/.*?([0-9]*)$/', '$1', $topic_id);
                            phpQuery::unloadDocuments();
                            return $topic_id;
                        }
                    }
                }
            }
            $page--;
            $i += 50;
            phpQuery::unloadDocuments();
        }
        return false;
    }

    /**
     * получение сведений о разрегистрированной раздаче
     * @param int $topicID
     * @return bool|array
     */
    public function getDataUnregisteredTopic($topicID)
    {
        if (!is_numeric($topicID)) {
            return false;
        }
        $data = $this->make_request($this->forum_url . '/forum/viewtopic.php?t=' . $topicID);
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        unset($data);
        // ссылка для скачивания
        $downloadLink = $html->find('a.dl-link')->attr('href');
        // раздача в мусорке, не найдена
        $topicStatuses[] = $html->find('div.mrg_16')->text();
        // повтор, закрыто, не оформлена и т.п.
        $topicStatuses[] = $html->find('span#tor-status-resp b:first')->text();
        // поглощено и прочие открытые статусы
        $topicStatuses[] = $html->find('div.attach_link i.normal b:first')->text();
        // имя
        $topicName = $html->find('h1.maintitle a')->text();
        // приоритет
        $topicPriority = $html->find('div.attach_link b:last')->text();
        // статус
        $topicStatus = implode('', array_diff($topicStatuses, ['']));
        if (!empty($downloadLink)) {
            $topicStatus = 'обновлено';
        }
        if (empty($topicPriority)) {
            $topicPriority = $html->find('table.attach b:first')->text();
        }
        // данные о переносе раздачи
        $transferredTo = $html->find('td.t-breadcrumb-top:first > a')->text();
        $transferredTo = str_replace(PHP_EOL, ' » ', rtrim($transferredTo, PHP_EOL));
        $totalPages = $html->find('a.pg:last')->prev()->text();
        if ($totalPages > 1) {
            $lastPage = ($totalPages - 1) * 30;
            $data = $this->make_request($this->forum_url . '/forum/viewtopic.php?t=' . $topicID . '&start=' . $lastPage);
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
        }
        // сканирование последнего сообщения в теме
        $transferredFrom = '';
        $transferredByWhom = '';
        $avatarLastMessage = $html->find('table#topic_main > tbody:last')->find('p.avatar > img')->attr('src');
        if (preg_match('/17561.gif$/i', $avatarLastMessage)) {
            $transferredFrom = $html->find('table#topic_main > tbody:last')->find('a.postLink:first')->text();
            $lastLink = $html->find('table#topic_main > tbody:last')->find('a.postLink:last')->attr('href');
            if (preg_match('/^profile.php\?mode=viewprofile&u=[0-9]+$/', $lastLink)) {
                $transferredByWhom = $html->find('table#topic_main > tbody:last')->find('a.postLink:last')->text();
            }
        }
        if (
            mb_strpos($transferredTo, 'Архив') === false
            && empty($transferredFrom)
            && empty($transferredByWhom)
        ) {
            $transferredFrom = preg_replace('/.*» /', '', $transferredTo);
        }
        unset($html);
        phpQuery::unloadDocuments();
        return [
            'name' => $topicName,
            'status' => mb_strtolower($topicStatus),
            'priority' => $topicPriority,
            'transferred_from' => $transferredFrom,
            'transferred_to' => $transferredTo,
            'transferred_by_whom' => $transferredByWhom
        ];
    }

    // поиск ID тем по заданным параметрам
    public function searchTopicsIDs($params, $forumID = 1584)
    {
        if (empty($params)) {
            return false;
        }
        $topicsIDs = [];
        $startIndex = 0;
        $currentPage = 1;
        $pageID = '';
        while ($currentPage > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/search.php?id=$pageID",
                array_merge(
                    [
                        'start' => $startIndex,
                        'f' => $forumID
                    ],
                    $params
                )
            );
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $blockTopics = $html->find('table.forum > tbody:first');
            $totalPages = $html->find('a.pg:last')->prev();
            if (
                !empty($totalPages)
                && $startIndex == 0
            ) {
                $currentPage = $html->find('a.pg:last')->prev()->text();
                $pageID = $html->find('a.pg:last')->attr('href');
                $pageID = preg_replace('/.*id=([^\&]*).*/', '$1', $pageID);
            }
            unset($html);
            if (!empty($blockTopics)) {
                $blockTopics = pq($blockTopics);
                foreach ($blockTopics->find('tr.tCenter') as $row) {
                    $row = pq($row);
                    $topicIcon = $row->find('img.topic_icon')->attr('src');
                    // получаем ссылки на темы со списками
                    if (preg_match('/.*(folder|folder_new)\.gif$/i', $topicIcon)) {
                        $topicID = $row->find('a.topictitle')->attr('href');
                        $topicsIDs[] = preg_replace('/.*?([0-9]*)$/', '$1', $topicID);
                    }
                }
            }
            $currentPage--;
            $startIndex += 50;
            phpQuery::unloadDocuments();
        }
        return $topicsIDs;
    }

    public function search_post_id($topic_id, $last_post = false)
    {
        if (empty($topic_id)) {
            return false;
        }
        $posts_ids = [];
        $i = 0;
        $page = 1;
        $page_id = "";
        while ($page > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/search.php?id=$page_id",
                [
                    'start' => $i,
                    'uid' => UserDetails::$uid,
                    't' => $topic_id,
                    'dm' => 1,
                ]
            );
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $topic_main = $html->find('table.topic:first');
            $pages = $html->find('a.pg:last')->prev();
            if (
                !empty($pages)
                && $i == 0
            ) {
                $page = $html->find('a.pg:last')->prev()->text();
                $page_id = $html->find('a.pg:last')->attr('href');
                $page_id = preg_replace('/.*id=([^\&]*).*/', '$1', $page_id);
            }
            unset($html);
            if (!empty($topic_main)) {
                $topic_main = pq($topic_main);
                foreach ($topic_main->find('tr') as $row) {
                    $row = pq($row);
                    $post_id = $row->find('a.small')->attr('href');
                    if (preg_match('/#[0-9]+$/', $post_id)) {
                        $post_id = preg_replace('/.*?([0-9]*)$/', '$1', $post_id);
                        if ($last_post) {
                            phpQuery::unloadDocuments();
                            return $post_id;
                        }
                        $posts_ids[] = $post_id;
                    }
                }
            }
            $page--;
            $i += 30;
            phpQuery::unloadDocuments();
        }
        return $posts_ids;
    }

    public function scanning_viewforum($forum_id)
    {
        if (empty($forum_id)) {
            return false;
        }
        $topics_ids = [];
        $i = 0;
        $page = 1;
        while ($page > 0) {
            $data = $this->make_request($this->forum_url . "/forum/viewforum.php?f=$forum_id&start=$i");
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $topic_main = $html->find('table.forum > tr.hl-tr');
            $pages = $html->find('a.pg:last')->prev();
            if (
                !empty($pages)
                && $i == 0
            ) {
                $page = $html->find('a.pg:last')->prev()->text();
            }
            unset($html);
            if (!empty($topic_main)) {
                $topic_main = pq($topic_main);
                foreach ($topic_main as $row) {
                    $row = pq($row);
                    $topic_icon = $row->find('img.topic_icon')->attr('src');
                    // получаем ссылки на темы со списками
                    if (preg_match('/.*(folder|folder_new)\.gif$/i', $topic_icon)) {
                        $topic_id = $row->find('a.topictitle')->attr('href');
                        $topics_ids[] = preg_replace('/.*?([0-9]*)$/', '$1', $topic_id);
                    }
                }
            }
            $page--;
            $i += 50;
            phpQuery::unloadDocuments();
        }
        return $topics_ids;
    }

    public function scanning_viewtopic($topic_id, $posted_days = -1)
    {
        if (empty($topic_id)) {
            return false;
        }
        $keepers = [];
        $i = 0;
        $page = 1;
        $index = -1;
        while ($page > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/viewtopic.php?t=$topic_id&start=$i"
            );
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $topic_main = $html->find('table#topic_main');
            $pages = $html->find('a.pg:last')->prev();
            if (
                !empty($pages)
                && $i == 0
            ) {
                $page = $html->find('a.pg:last')->prev()->text();
            }
            unset($html);
            if (!empty($topic_main)) {
                $topic_main = pq($topic_main);
                foreach ($topic_main->find('tbody') as $row) {
                    $row = pq($row);

                    // вытаскиваем параметры сообщения и ид пользователя
                    $link_data = $row->find('div.post_body')->attr('data-ext_link_data');
                    if (!empty($link_data)) {
                        $link_data = json_decode($link_data);
                    }
                    if (empty($link_data) || empty($link_data->p)) {
                        continue;
                    }
                    $index++;

                    $post_id = $link_data->p;
                    $user_id = $link_data->u;

                    $nickname = $row->find('p.nick > a')->text();
                    $keepers[$index] = [
                        'post_id'  => $link_data->p,
                        'user_id'  => $link_data->u,
                        'nickname' => htmlspecialchars($nickname),
                    ];
                    unset($link_data);

                    // вытаскиваем дату отправки/редактирования сообщения
                    $postedDateTime = $this->getPostedDateTime($row, $post_id);
                    $keepers[$index]['posted'] = $postedDateTime->format('U');

                    // Если задан фильтр по актуальности отчёта.
                    if ($posted_days > -1) {
                        // Насколько отчёт старый, в днях.
                        $days_diff = $this->compareDates($postedDateTime);

                        // Пропускаем сообщение, если оно старше $posted_days дней.
                        if ($days_diff > $posted_days) {
                            continue;
                        }
                    }

                    // Skip topics links from user StatsBot
                    if ($user_id === $this->StatBotUserId) {
                        continue;
                    }
                    // получаем id раздач хранимых другими хранителями
                    $topics = $row->find('a.postLink');
                    if (!empty($topics)) {
                        foreach ($topics as $topic) {
                            $topic = pq($topic);
                            $href = $topic->attr('href');
                            if (preg_match('/viewtopic.php\?t=[0-9]+$/', $href)) {
                                $keeperTopicID = preg_replace('/.*?([0-9]*)$/', '$1', $href);
                                $keepers[$index]['topics_ids'][1][] = $keeperTopicID;
                            } elseif (preg_match('/viewtopic.php\?t=[0-9]+#dl$/', $href)) {
                                $keeperTopicID = preg_replace('/.*?([0-9]*)#dl$/', '$1', $href);
                                $keepers[$index]['topics_ids'][0][] = $keeperTopicID;
                            }
                        }
                    }
                    unset($topics);
                }
                unset($topic_main);
            }
            $page--;
            $i += 30;
            phpQuery::unloadDocuments();
        }
        // array( 'post_id', 'user_id', 'nickname', 'posted', topics_ids' => array( [0] => array( ...), [1] => array(...) ) )
        return $keepers;
    }

    /**
     * Определить дату актуальности списка из поста.
     *
     * @throws Exception
     */
    private function getPostedDateTime($row, int $post_id): DateTimeImmutable
    {
        $dates    = [];
        $postBody = $row->find('.post_body')->text();

        // Дата публикации поста.
        $postedMessage = $row->find('.p-link')->text();
        $postedMessage = str_replace($this->months_ru, $this->months, $postedMessage);

        $dates['posted'] = DateTimeImmutable::createFromFormat('d-M-y H:i', $postedMessage);

        // Дата редактирования поста.
        $editedMessage = $row->find('.posted_since')->text();
        $editedPattern = '/(\d+)-(\D+)-(\d+) (\d+):(\d+)/';
        if (preg_match($editedPattern, $editedMessage, $matches) && !empty($matches)) {
            $editedMessage = str_replace($this->months_ru, $this->months, $matches[0]);

            $dates['edited'] = DateTimeImmutable::createFromFormat('d-M-y H:i', $editedMessage);
        }

        // Дата актуальности списка.
        $actualPattern = '/^Актуально на.{0,100}(\d{2}\.\d{2}\.\d{4})/';
        if (preg_match($actualPattern, trim($postBody), $matches) && !empty($matches)) {
            $parsed = DateTimeImmutable::createFromFormat('d.m.Y', $matches[1]);
            $parsed->setTime(1, 0);

            $dates['actual'] = $parsed;
        }

        $dates = array_filter($dates);
        if (!count($dates)) {
            throw new Exception("Не удалось определить дату редактирования поста ($post_id)");
        }

        // Из всех найденных дат, выбираем максимальную.
        return max($dates);
    }

    public function send_message($mode, $message, $topic_id, $post_id = "", $subject = "")
    {
        // блокировка отправки сообщений
        if (!isset($this->blocking_send)) {
            $this->blocking_send = false;

            if ($unavailable = $this->check_access()) {
                $this->blocking_send = true;
                $this->blocking_reason = $unavailable->value;
            }
        }
        if ($this->blocking_send) {
            throw new Exception($this->blocking_reason);
        }

        $message = str_replace('<br />', '', $message);
        $message = str_replace('[br]', "\n", $message);
        // получение form_token
        if (empty(UserDetails::$form_token)) {
            UserDetails::get_form_token();
        }

        $data = $this->make_request(
            $this->forum_url . '/forum/posting.php',
            [
                't' => $topic_id,
                'mode' => $mode,
                'p' => $post_id,
                'subject' => mb_convert_encoding("$subject", 'Windows-1251', 'UTF-8'),
                'submit_mode' => "submit",
                'form_token' => UserDetails::$form_token,
                'message' => mb_convert_encoding("$message", 'Windows-1251', 'UTF-8'),
            ]
        );
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        unset($data);
        $msg = $html->find('div.msg')->text();
        if (!empty($msg)) {
            Log::append("Error: $msg ($topic_id).");
            phpQuery::unloadDocuments();
            return false;
        }
        $post_id = $html->find('div.mrg_16 > a')->attr('href');
        if (empty($post_id)) {
            $msg = $html->find('div.mrg_16')->text();
            if (empty($msg)) {
                $msg = $html->find('h2')->text();
                if (empty($msg)) {
                    $msg = 'Неизвестная ошибка';
                }
            }
            Log::append("Error: $msg ($topic_id).");
            phpQuery::unloadDocuments();
            return false;
        }
        phpQuery::unloadDocuments();
        $post_id = preg_replace('/.*?([0-9]*)$/', '$1', $post_id);
        return $post_id;
    }

    public function check_access(): ?AccessCheck
    {
        $data = $this->make_request(
            $this->forum_url . "/forum/viewtopic.php?t=4546540"
        );
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');

        if ($html->find('h1.pagetitle')->text() === 'Вход') {
            return AccessCheck::NOT_AUTHORIZED;
        }
        if ($html->find('div.mrg_16')->text() === 'Тема не найдена') {
            return AccessCheck::USER_CANDIDATE;
        }
        $topic_title = $html->find('a#topic-title')->text();
        if (!(str_ends_with($topic_title, '#3'))) {
            return AccessCheck::VERSION_OUTDATED;
        }
        return null;
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }

    /** Получить разницу в днях между эталонной датой (текущей) и датой отчёта. */
    private function compareDates(DateTimeImmutable $postDate): int
    {
        if (null === $this->compareDate) {
            $this->compareDate = new DateTimeImmutable('now');
        }

        return (int)$this->compareDate->diff($postDate)->format('%a');
    }
}
