<?php

include dirname(__FILE__) . '/php/phpQuery.php';

function create_reports($subsections, $topics){
	$tmp = array();
	$max = 119000;
	$pattern = '[spoiler="№№ %%start%% — %%end%%"]<br />[list=1]<br />[*=%%start%%]%%list%%[/list]<br />[/spoiler]<br />';
	$length = mb_strlen($pattern, 'UTF-8');
	foreach($topics as $topic){
		if($topic['dl'] == 1 && $topic['ss'] != 0){
			if(!isset($tmp[$topic['ss']])){
				$tmp[$topic['ss']]['start'] = 1;
				$tmp[$topic['ss']]['lgth'] = 0;
				$tmp[$topic['ss']]['dlsi'] = 0;
				$tmp[$topic['ss']]['dlqt'] = 0;
				$tmp[$topic['ss']]['qt'] = 0 ;
			}
			$str = '[url=viewtopic.php?t='.$topic['id'].']'.$topic['na'].'[/url] '.convert_bytes($topic['si']).' -  [color=red]'.round($topic['avg']).'[/color]';
			$lgth = mb_strlen($str, 'UTF-8');
			$current = $tmp[$topic['ss']]['lgth'] + $lgth;
			$available = $max - $length - ($tmp[$topic['ss']]['qt'] - $tmp[$topic['ss']]['start'] + 1) * 3;
			if($current > $available){
				$text = str_replace('%%start%%', $tmp[$topic['ss']]['start'], $pattern);
				$text = str_replace('%%end%%', $tmp[$topic['ss']]['qt'], $text);
				$tmp[$topic['ss']]['msg'][]['text'] = str_replace('%%list%%', implode('<br />[*]', $tmp[$topic['ss']]['str']), $text);
				$tmp[$topic['ss']]['start'] = $tmp[$topic['ss']]['qt'] + 1;
				$tmp[$topic['ss']]['lgth'] = 0;
				unset($tmp[$topic['ss']]['str']);
			}
			$tmp[$topic['ss']]['lgth'] += $lgth;
			$tmp[$topic['ss']]['str'][] = $str;
			$tmp[$topic['ss']]['qt']++;
			$tmp[$topic['ss']]['dlsi'] += $topic['si'];
			$tmp[$topic['ss']]['dlqt']++;
		}
	}
	$common = 'Актуально на: [b]' . date('d.m.Y', $topics[0]['ud']) . '[/b][br][br]<br /><br />'.
			  'Общее количество хранимых раздач: [b]%%dlqt%%[/b] шт.[br]<br />'.
			  'Общий вес хранимых раздач: [b]%%dlsi%%<br />[hr]<br />';
	$dlqt = 0;
	$dlsi = 0;
	foreach($subsections as &$subsection){
		if(!isset($tmp[$subsection['id']])) continue;
		if($tmp[$subsection['id']]['lgth'] != 0){
			$text = str_replace('%%start%%', $tmp[$subsection['id']]['start'], $pattern);
			$text = str_replace('%%end%%', $tmp[$subsection['id']]['qt'], $text);
			$tmp[$subsection['id']]['msg'][]['text'] = str_replace('%%list%%', implode('<br />[*]', $tmp[$subsection['id']]['str']), $text);
		}
		$subsection['messages'] = $tmp[$subsection['id']]['msg'];
		$dlqt += $subsection['dlqt'] = $tmp[$subsection['id']]['dlqt'];
		$dlsi += $subsection['dlsi'] = $tmp[$subsection['id']]['dlsi'];
		$info = 'Актуально на: [color=darkblue]' . date('d.m.Y', $topics[0]['ud']) . '[/color][br]<br />'.
				'Всего хранимых раздач в подразделе: ' . $subsection['dlqt'] . ' шт. / ' . convert_bytes($subsection['dlsi']) . '<br />';
		$subsection['messages'][0]['text'] = $info . $subsection['messages'][0]['text'];
		$header = 'Подраздел: [url=viewforum.php?f='.$subsection['id'].'][u][color=#006699]'.$subsection['na'].'[/u][/color][/url]'.
				  ' [color=gray]~>[/color] [url=tracker.php?f='.$subsection['id'].'&tm=-1&o=10&s=1&oop=1][color=indigo][u]Проверка сидов[/u][/color][/url][br][br]<br /><br />'.
				  'Актуально на: [color=darkblue]'. date('d.m.Y', $topics[0]['ud']) . '[/color][br]<br />'.
				  'Всего раздач в подразделе: ' . $subsection['qt'] .' шт. / ' . convert_bytes($subsection['si']) . '[br]<br />'.
				  'Всего хранимых раздач в подразделе: %%dlqt%% шт. / %%dlsi%%[br]<br />'.
				  'Количество хранителей: %%count%%<br />[hr]<br />'.
				  'Хранитель 1: [url=profile.php?mode=viewprofile&u=%%nick%%&name=1][u][color=#006699]%%nick%%[/u][/color][/url] [color=gray]~>[/color] '. $subsection['dlqt'] .' шт. [color=gray]~>[/color] '. convert_bytes($subsection['dlsi']) .'[br]<br /><br />';
		$common .= '[url=viewtopic.php?p=%%ins' . $subsection['id'] . '%%#%%ins' .$subsection['id'] . '%%][u]'.$subsection['na'] . '[/u][/url] — ' .	$subsection['dlqt'] .' шт. ('. convert_bytes($subsection['dlsi']) . ')[br]<br />';
		$subsection['header'] = $header;
	}
	$common = str_replace('%%dlqt%%', empty($dlqt) ? 0 : $dlqt, $common);
	$common = str_replace('%%dlsi%%', empty($dlsi) ? 0 : preg_replace('/ (?!.* )/', '[/b] ', convert_bytes($dlsi)), $common);
	$subsections['common'] = $common;
	return $subsections;
}

class SendReports {
	
	public $log;
	public $forum_url;
	public $proxy = array();
	
	protected $cookie;
	protected $uid;
	protected $login;
	protected $paswd;
	protected $ch;
	
	public function __construct($forum_url, $login, $paswd, $proxy_activate, $proxy_type = 0, $proxy_address = "", $proxy_auth = ""){
		$this->log = get_now_datetime() . 'Выполняется отправка отчётов на форум...<br />';
		$this->init_proxy($proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
		$this->paswd = mb_convert_encoding($paswd, 'Windows-1251', 'UTF-8');
		$this->login = mb_convert_encoding($login, 'Windows-1251', 'UTF-8');
		$this->forum_url = $forum_url;
		$this->ch = curl_init();
		$this->get_cookie();
	}
	
	private function init_proxy($proxy_activate, $proxy_type, $proxy_address, $proxy_auth){
		if(!$proxy_activate){
			$this->log .= get_now_datetime() . 'Прокси-сервер не используется.<br />';
			return;
		}
		$this->log .= get_now_datetime() . 'Используется ' . mb_strtoupper($proxy_type) . '-прокси: "' . $proxy_address . '".<br />';
		$proxy_array = array('http' => 0, 'socks4' => 4, 'socks4a' => 6, 'socks5' => 5);		
		$proxy_type = (array_key_exists($proxy_type, $proxy_array) ? $proxy_array[$proxy_type] : null);
		$proxy_address = (in_array(null, explode(':', $proxy_address)) ? null : $proxy_address);
		$proxy_auth = (in_array(null, explode(':', $proxy_auth)) ? null : $proxy_auth);		
		$this->proxy = array(
			CURLOPT_PROXYTYPE => $proxy_type,
			CURLOPT_PROXY => $proxy_address,
			CURLOPT_PROXYUSERPWD => $proxy_auth
		);
		curl_setopt_array($this->ch, $this->proxy);
	}
	
	private function get_cookie(){
		$data = $this->make_request(
			$this->forum_url . '/forum/login.php',
			array('login_username' => "$this->login", 'login_password' => "$this->paswd", 'login' => 'Вход'),
			array(CURLOPT_HEADER => 1)
		);
		preg_match("|.*Set-Cookie: [^-]*-([0-9]*)|", $data, $uid);
		preg_match("|.*Set-Cookie: ([^;]*);.*|", $data, $cookie);
		if(!isset($uid[1]) || !isset($cookie[1])){
			preg_match('|<title>(.*)</title>|sei', $data, $title);
			if(!empty($title))
				if($title[1] == 'rutracker.org'){
					preg_match('|<h4[^>]*?>(.*)</h4>|sei', $data, $text);
					if(!empty($text))
						$this->log .= get_now_datetime() . 'Error: ' . $title[1] . ' - ' . mb_convert_encoding($text[1], 'UTF-8', 'Windows-1251') . '.<br />';
				} else {
					$this->log .= get_now_datetime() . 'Error: ' . mb_convert_encoding($title[1], 'UTF-8', 'Windows-1251') . '.<br />';
				}
			throw new Exception(get_now_datetime() . 'Не удалось авторизоваться на форуме.<br />');
		}
		$this->uid = $uid[1];
		$this->cookie = $cookie[1];
	}
	
	private function make_request($url, $fields = array(), $options = array()){
		curl_setopt_array($this->ch, $options);
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_URL => $url,
			CURLOPT_POSTFIELDS => http_build_query($fields)
		));
		$data = curl_exec($this->ch);
		if($data === false)
			throw new Exception(get_now_datetime() . 'CURL ошибка: ' . curl_error($this->ch) . '<br />');
		return $data;
	}
	
	private function get_form_token(){
		$data = $this->make_request(
			$this->forum_url . '/forum/profile.php',
			array('mode' => 'viewprofile', 'u' => $this->uid)
		);
		$html = phpQuery::newDocumentHTML($data, 'Windows-1251');
		preg_match("|.*form_token : '([^,]*)',.*|sei", $html->find('script:first'), $form_token);
		if(!isset($form_token[1]))
			throw new Exception(get_now_datetime() . 'Не получен form_token.<br />');
		return $form_token[1];
	}
	
	private function send_message($mode, $message, $form_token, $topic_id, $post_id = "", $subject = ""){
		$message = str_replace('<br />', '', $message);
		$message = str_replace('[br]', "\n", $message);
		$data = $this->make_request(
			$this->forum_url . '/forum/posting.php',
			array(
				't' => $topic_id,
				'mode' => "$mode",
				'p' => $post_id,
				'subject' => mb_convert_encoding("$subject", 'Windows-1251', 'UTF-8'),
				'submit_mode' => "submit",
				'form_token' => "$form_token",
				'message' => mb_convert_encoding("$message", 'Windows-1251', 'UTF-8')
			)
		);
		$html = phpQuery::newDocumentHTML($data, 'Windows-1251');
		$error = $html->find('div.msg')->text();
		if(!empty($error)){
			$this->log .= get_now_datetime() . $error . '(' . $topic_id . ')<br />';
			return;
		}
		$post_id = $html->find('div.mrg_16 > a')->attr('href');
		if(empty($post_id)){
			$this->log .= get_now_datetime() . $html->find('div.mrg_16')->text() . '(' . $topic_id . ')<br />';
			return;
		}
		$post_id = preg_replace('/.*?([0-9]*)$/', '$1', $post_id);
		return $post_id;
	}
	
	public function send_reports($api_key, $api_url, $subsections, $data = array()){
		$common = $subsections['common'];
		unset($subsections['common']);
		// получаем ссылки на списки
		foreach($data as $data){
			$links[$data['id']] = preg_replace('/.*?([0-9]*)$/', '$1', $data['ln']);
		}
		// изменяем опции curl
		curl_setopt_array($this->ch, array(
			CURLOPT_HEADER => 0, CURLOPT_COOKIE => "$this->cookie"
		));
		// получение form_token
		$form_token = $this->get_form_token();
		// отправка отчётов по каждому подразделу
		foreach($subsections as &$subsection){
			if(!isset($subsection['messages'])) continue;
			if(empty($links[$subsection['id']])){
				$this->log .= get_now_datetime() . 'Для подраздела № ' . $subsection['id'] . ' не указана ссылка для отправки отчётов, пропускаем...<br />';
				continue;
			}
			$i = 0;
			$page = 1;
			// получение данных со страниц
			$this->log .= get_now_datetime() . 'Поиск своих сообщений в теме для подраздела № ' . $subsection['id'] . '...<br />';
			while($page > 0){
				$data = $this->make_request(
					$this->forum_url . '/forum/viewtopic.php?t=' . $links[$subsection['id']]. '&start=' . $i
				);
				$html = phpQuery::newDocumentHTML($data, 'Windows-1251');
				$topic_main = $html->find('table#topic_main');
				if(!empty($topic_main)){
					$j = 0;
					$topic_main = pq($topic_main);
					$nick_author = $topic_main->find('p.nick-author:first > a')->text();
					$post_author = str_replace('post_', '', $topic_main->find('tbody:first')->attr('id'));
					foreach($topic_main->find('tbody') as $row){
						$row = pq($row);
						$post = str_replace('post_', '', $row->attr('id'));
						if($post_author != $post && !empty($post)){
							$nick = $row->find('p.nick > a')->text();
							if($nick == $this->login){
								$subsection['messages'][$j]['id'] = $post;
								$j++;
							} else {
								// получаем id раздач хранимых другими хранителями
								foreach($row->find('a.postLink') as $topic){
									$topic = pq($topic);
									if(preg_match('/viewtopic.php\?t=[0-9]*/', $topic->attr('href'))){
										$keepers[$nick][] = preg_replace('/.*?([0-9]*)$/', '$1', $topic->attr('href'));
									}
								}
							}
						}
					}
				}
				$pages = $topic_main->find('a.pg:last')->prev();
				if(!empty($pages) && $i == 0)
					$page = $topic_main->find('a.pg:last')->prev()->text();
				$page--;
				$i += 30;
			}
			$this->log .= get_now_datetime() . 'Найдено сообщений: ' . $j . '.<br />';
			// отправка шапки
			if($nick_author == $this->login){
				// получение данных о раздачах хранимых другими хранителями
				if(isset($keepers)){
					$this->log .= get_now_datetime() . 'Сканирование сообщений других хранителей для подраздела № ' . $subsection['id'] . '...<br />';
					foreach($keepers as $nick => $ids){
						$webtlo = new Webtlo($api_key, $api_url, $this->proxy);
						$topics = $webtlo->get_tor_topic_data($ids);
						//~ $this->log .= $webtlo->log;
						$stored[$nick]['dlsi'] = 0;
						$stored[$nick]['dlqt'] = 0;
						foreach($topics as $topic){
							if($topic['forum_id'] == $subsection['id']){
								$stored[$nick]['dlsi'] += $topic['size'];
								$stored[$nick]['dlqt'] += 1;
							}
						}
					}
					// вставка в отчёты данных о других хранителях
					$q = 2;
					foreach($stored as $nick => $data){
						$subsection['header'] .= 'Хранитель '.$q.': [url=profile.php?mode=viewprofile&u='.$nick.'&name=1][u][color=#006699]'.$nick.'[/u][/color][/url] [color=gray]~>[/color] '. $data['dlqt'] .' шт. [color=gray]~>[/color] '. convert_bytes($data['dlsi']) .'[br]';
						$q++;
					}
				}
				$this->log .= get_now_datetime() . 'Отправка шапки для подраздела № ' . $subsection['id'] . '...<br />';
				$subsection['header'] = str_replace('%%nick%%', $this->login, $subsection['header']);
				$subsection['header'] = str_replace('%%count%%', isset($keepers) ? count($keepers) + 1 : 1, $subsection['header']);
				$subsection['header'] = str_replace('%%dlqt%%', isset($stored) ? array_sum(array_column_common($stored, 'dlqt')) + $subsection['dlqt'] : $subsection['dlqt'], $subsection['header']);
				$subsection['header'] = str_replace('%%dlsi%%', convert_bytes(isset($stored) ? array_sum(array_column_common($stored, 'dlsi')) + $subsection['dlsi'] : $subsection['dlsi']), $subsection['header']);
				// отправка сообщения с шапкой
				$this->send_message(
					'editpost', $subsection['header'], $form_token,
					$links[$subsection['id']], $post_author, '[Список] ' . $subsection['na']
				);
			}
			unset($keepers);
			unset($stored);
			// отправка сообщений
			$q = 1;
			foreach($subsection['messages'] as &$message){
				if(empty($message['id'])){
					$this->log .= get_now_datetime() . 'Вставка дополнительного ' . $q . '-ого сообщения для подраздела № ' . $subsection['id'] . '...<br />';
					$message['id'] = $this->send_message(
						'reply', '[spoiler]' . $q . str_repeat('?', 119981 - count($q)) . '[/spoiler]',
						$form_token, $links[$subsection['id']]
					);
					$q++;
				}
			}
			unset($message);
			// вставка ссылок в сводном отчёте на список
			$common = str_replace('%%ins' . $subsection['id'] . '%%', $subsection['messages'][0]['id'], $common);
			foreach($subsection['messages'] as $message){
				if(!empty($message['id'])){
					$this->log .= get_now_datetime() . 'Редактирование сообщения № ' . $message['id'] . ' для подраздела № ' . $subsection['id'] . '...<br />';
					$this->send_message(
						'editpost',	empty($message['text']) ? 'резерв' : $message['text'],
						$form_token, $links[$subsection['id']], $message['id']
					);
				}
			}
		}
		unset($subsection);
		// отправка сводного отчёта
		$this->log .= get_now_datetime() . 'Отправка сводного отчёта...<br />';
		$data = $this->make_request(
			$this->forum_url . '/forum/search.php',
			array('uid' => $this->uid, 't' => 4275633, 'dm' => 1)
		);
		$html = phpQuery::newDocumentHTML($data, 'Windows-1251');
		$common_post = $html->find('.row1:first');
		$post_id = empty($common_post) ? "" : preg_replace('/.*?([0-9]*)$/', '$1', pq($common_post)->find('.txtb')->attr('href'));
		$this->send_message(
			empty($post_id) ? 'reply' : 'editpost',
			$common, $form_token, 4275633, $post_id
		);
	}
	
	public function __destruct(){
		curl_close($this->ch);
	}
	
}

?>
