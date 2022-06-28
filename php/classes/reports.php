<?php

include_once dirname(__FILE__) . '/../phpQuery.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

class Reports
{
    /**
     * @var CurlHandle
     */
    protected $ch;

    /**
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $forum_url;

    /**
     * @var bool
     */
    protected $blocking_send;

    /**
     * @var array
     */
    private $months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');

    /**
     * @var array
     */
    private $months_ru = array('Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек');

    public function __construct($forum_url, $login, $paswd, $cap_fields = array())
    {
        $this->login = $login;
        $this->forum_url = $forum_url;
        UserDetails::$forum_url = $forum_url;
        UserDetails::get_cookie($login, $paswd, $cap_fields);
        $this->ch = curl_init();
        Log::append('Используется зеркало для форума: ' . $forum_url);
    }

    public function curl_setopts($options)
    {
        curl_setopt_array($this->ch, $options);
    }

    private function make_request($url, $fields = array(), $options = array())
    {
        curl_setopt_array($this->ch, array(
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
        ));
        curl_setopt_array($this->ch, Proxy::$proxy['forum']);
        curl_setopt_array($this->ch, $options);
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
                array(
                    'nm' => mb_convert_encoding("$search", 'Windows-1251', 'UTF-8'),
                    'start' => $i,
                    'f' => $forum_id,
                )
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

    // поиск сведений о раздаче в архиве
    public function getTopicDataFromArchive($topicID)
    {
        if (!is_numeric($topicID)) {
            return false;
        }
        $data = $this->make_request($this->forum_url . '/forum/viewtopic.php?t=' . $topicID);
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        unset($data);
        // раздача в мусорке, не найдена
        $topicStatus = $html->find('div.mrg_16')->text();
        if (!empty($topicStatus)) {
            unset($html);
            return false;
        }
        // раздача зарегистрирована
        $topicStatus = $html->find('a.dl-topic')->attr('href');
        if (!empty($topicStatus)) {
            unset($html);
            return false;
        }
        // поглощено, повтор, закрыто
        $topicStatus = $html->find('span#tor-status-resp b:first')->text();
        if (!empty($topicStatus)) {
            unset($html);
            return false;
        }
        $currentForum = $html->find('td.t-breadcrumb-top:first > a')->text();
        $currentForum = str_replace(PHP_EOL, ' » ', rtrim($currentForum, PHP_EOL));
        $lastStatus = $html->find('fieldset.attach')->find('i.normal b')->text();
        $totalPages = $html->find('a.pg:last')->prev()->text();
        if ($totalPages > 1) {
            $lastPage = ($totalPages - 1) * 30;
            $data = $this->make_request($this->forum_url . '/forum/viewtopic.php?t=' . $topicID . '&start=' . $lastPage);
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
        }
        $originalForum = '';
        $whoTransferred = '-';
        $avatarLastMessage = $html->find('table#topic_main > tbody:last')->find('p.avatar > img')->attr('src');
        if (preg_match('/17561.gif$/i', $avatarLastMessage)) {
            $originalForum = $html->find('table#topic_main > tbody:last')->find('a.postLink:first')->text();
            $lastLink = $html->find('table#topic_main > tbody:last')->find('a.postLink:last')->attr('href');
            if (preg_match('/^profile.php\?mode=viewprofile&u=[0-9]+$/', $lastLink)) {
                $whoTransferred = $html->find('table#topic_main > tbody:last')->find('a.postLink:last')->text();
            }
        }
        unset($html);
        return array(
            'current_forum' => $currentForum,
            'original_forum' => $originalForum,
            'last_status' => $lastStatus,
            'who_transferred' => $whoTransferred
        );
    }

    // поиск ID тем по заданным параметрам
    public function searchTopicsIDs($params, $forumID = 1584)
    {
        if (empty($params)) {
            return false;
        }
        $topicsIDs = array();
        $startIndex = 0;
        $currentPage = 1;
        $pageID = '';
        while ($currentPage > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/search.php?id=$pageID",
                array_merge(
                    array(
                        'start' => $startIndex,
                        'f' => $forumID
                    ),
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
        $posts_ids = array();
        $i = 0;
        $page = 1;
        $page_id = "";
        while ($page > 0) {
            $data = $this->make_request(
                $this->forum_url . "/forum/search.php?id=$page_id",
                array(
                    'start' => $i,
                    'uid' => UserDetails::$uid,
                    't' => $topic_id,
                    'dm' => 1,
                )
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
        $topics_ids = array();
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
        $keepers = array();
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
                    $post_id = str_replace('post_', '', $row->attr('id'));
                    if (empty($post_id)) {
                        continue;
                    }
                    $index++;
                    $nickname = $row->find('p.nick > a')->text();
                    $keepers[$index] = array(
                        'post_id' => $post_id,
                        'nickname' => $nickname,
                    );
                    // вытаскиваем дату отправки/редактирования сообщения
                    $postedDateMessage = $row->find('.p-link')->text();
                    $editedDateMessage = $row->find('.posted_since')->text();
                    $changedMessage = preg_match('/(\d+)-(\D+)-(\d+) (\d+):(\d+)/', $editedDateMessage, $matches);
                    if ($changedMessage) {
                        $postedDateMessage = $matches[0];
                    }
                    $postedDateMessage = str_replace($this->months_ru, $this->months, $postedDateMessage);
                    $postedDateTime = DateTime::createFromFormat('d-M-y H:i', $postedDateMessage);
                    if (!$postedDateTime) {
                        throw new Exception("Error: Неправильный формат даты отправки сообщения - " . $postedDateMessage);
                    }
                    $keepers[$index]['posted'] = $postedDateTime->format('U');
                    $days_diff = Date::now()->diff($postedDateTime)->format('%a');
                    // пропускаем сообщение, если оно старше $posted_days дней
                    if (
                        $posted_days != -1
                        && $days_diff > $posted_days
                    ) {
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
        // array( 'post_id', 'nickname', 'posted', topics_ids' => array( [0] => array( ...), [1] => array(...) ) )
        return $keepers;
    }

    public function send_message($mode, $message, $topic_id, $post_id = "", $subject = "")
    {
        // блокировка отправки сообщений
        if (!isset($this->blocking_send)) {
            $data = $this->make_request(
                $this->forum_url . "/forum/viewtopic.php?t=4546540"
            );
            $html = phpQuery::newDocumentHTML($data, 'UTF-8');
            unset($data);
            $topic_title = $html->find('a#topic-title')->text();
            unset($html);
            phpQuery::unloadDocuments();
            $this->blocking_send = preg_match('/#3$/', $topic_title);
            if (!$this->blocking_send) {
                Log::append("Notice: Установите актуальную версию web-TLO для корректной отправки отчётов");
                throw new Exception("Error: Отправка отчётов для текущей версии web-TLO заблокирована");
            }
        }
        $message = str_replace('<br />', '', $message);
        $message = str_replace('[br]', "\n", $message);
        // получение form_token
        if (empty(UserDetails::$form_token)) {
            UserDetails::get_form_token();
        }

        $data = $this->make_request(
            $this->forum_url . '/forum/posting.php',
            array(
                't' => $topic_id,
                'mode' => $mode,
                'p' => $post_id,
                'subject' => mb_convert_encoding("$subject", 'Windows-1251', 'UTF-8'),
                'submit_mode' => "submit",
                'form_token' => UserDetails::$form_token,
                'message' => mb_convert_encoding("$message", 'Windows-1251', 'UTF-8'),
            )
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

    public function __destruct()
    {
        curl_close($this->ch);
    }
}
