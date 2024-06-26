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

    /**
     * Получение сведений о разрегистрированной раздаче
     *
     * @param int $topicID
     * @return ?array<string, mixed>
     * @throws Exception
     */
    public function getDataUnregisteredTopic(int $topicID): ?array
    {
        if (empty($topicID)) {
            return null;
        }

        $data = $this->make_request($this->forum_url . '/forum/viewtopic.php?t=' . $topicID);
        if (empty($data)) {
            return null;
        }

        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        unset($data);

        // ссылка для скачивания (доступна модераторам, даже если раздача закрыта).
        $downloadLink = $html->find('a.dl-link')->attr('href');

        // раздача в мусорке, не найдена
        $topicStatuses[] = $html->find('div.mrg_16')->text();
        // повтор, закрыто, не оформлена и т.п.
        $topicStatuses[] = $html->find('span#tor-status-resp b:first')->text();
        // поглощено и прочие открытые статусы
        $topicStatuses[] = $html->find('div.attach_link i.normal b:first')->text();

        // статус
        $topicStatus = implode('', array_filter($topicStatuses));
        if (empty($topicStatus) && !empty($downloadLink)) {
            $topicStatus = 'обновлено';
        }
        if (empty($topicStatus)) {
            $topicStatus = 'неизвестно';
        }

        // имя
        $topicName = $html->find('h1.maintitle a')->text();

        // приоритет
        $topicPriority = $html->find('div.attach_link b:last')->text();
        if (empty($topicPriority)) {
            $topicPriority = $html->find('table.attach b:first')->text();
        }
        if (empty($topicPriority)) {
            $topicPriority = 'обычный';
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
            'name'                => $topicName,
            'status'              => mb_strtolower($topicStatus),
            'priority'            => $topicPriority,
            'transferred_from'    => $transferredFrom,
            'transferred_to'      => $transferredTo,
            'transferred_by_whom' => $transferredByWhom,
        ];
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
}
