<?php
/*
 * web-TLO (Web Torrent List Organizer)
 * gui.php
 * author: Cuser (cuser@yandex.ru)
 * previous change: 30.04.2014
 * editor: berkut_174 (webtlo@yandex.ru)
 * last change: 10.03.2016
 */
 
// подготовка списка раздач в отчёты
function output_preparation($TT_torrents, &$TT_subsections){
	$tmp = array();
	foreach($TT_torrents as $torrent)
	{
		if($torrent['dl'] == 1)
		{
			if($tmp[$torrent['ss']]['qt'] == 0)
			{
			$tmp[$torrent['ss']]['t0'] = 1;
			$tmp[$torrent['ss']]['t3'] = 1;
			$tmp[$torrent['ss']]['te'] = '%%nsp%%';
			}
			
			//--- START REWRITE Your output representation here --- Блок задания формата вывода строки торрента в отчете (данные, bb коды и прочее)
			
			$t = '<br/>[*][url=viewtopic.php?t='.$torrent['id'].']'.$torrent['na'].'[/url] '.convert_bytes($torrent['si']).' -  [color=red]'.$torrent['se'].'[/color]';
			
			//--- END REWRITE Your output representation here --- Блок задания формата вывода строки торрента в отчете (данные, bb коды и прочее)
			
			
			$tmp[$torrent['ss']]['t2'] += mb_strlen($t);												//длина секции
			$tmp[$torrent['ss']]['te'] = str_replace(	'%%twn%%',										//не забываем про номер окончания предыд секции, т.к. она оказывается не последней
														$tmp[$torrent['ss']]['t3']-1,
														$tmp[$torrent['ss']]['te']);
			$tmp[$torrent['ss']]['te'] = str_replace(	'%%acc%%',										//не забываем про аккордион предыд секции, т.к. она оказывается не последней
														'<h3>Сообщение '. $tmp[$torrent['ss']]['t0'] .'</h3><div title="double click me">',
														$tmp[$torrent['ss']]['te']);
			
			if(($tmp[$torrent['ss']]['qt'] != 0) && ($tmp[$torrent['ss']]['qt'] % 10 == 0))
			{
				$tmp[$torrent['ss']]['t1'] .= '<br/>';
				if($tmp[$torrent['ss']]['t2'] > 100*1000)												//предельная длина одного отформатированного списка хранимых раздач раздела
				{
					$tmp[$torrent['ss']]['te'] = str_replace(	'%%nsp%%',								//не забываем стереть лишний спойл предыд секции, т.к. она оказывается не последней
																'',
																$tmp[$torrent['ss']]['te']);

					//---------------------------------------------------------------------------------------
					$tmp[$torrent['ss']]['t1'] =														//1		добавляем открывающие бб-коды к секции,
					//~ '[spoiler="Раздачи, взятые на хранение ('. date("Y-m-d") .')"]<br/><br/>'.			//		если секция не последняя, %%twn%% заменится на номер 
					'[spoiler="'. $tmp[$torrent['ss']]['t3'] .' — %%twn%%"]<br/><br/>'.			//		если секция не последняя, %%twn%% заменится на номер 
					//~ '№№ '. $tmp[$torrent['ss']]['t3'] .' — %%twn%%<br/>'.								//		окончания секции (см. перву строку в этом if),
					'[list=1]<br/>'.																	//		если последняя, заменится на суммарное кол-во отфильтрованных
					$tmp[$torrent['ss']]['t1'].															//		раздач раздела (см. следующий foreach)
					'<br/>';
					//---------------------------------------------------------------------------------------
					$tmp[$torrent['ss']]['t1'] .= 														//2		добавляем закрывающие бб-коды к секции
					'[/list]<br/>'.																		//		и конец-начало секции аккордиона
					'[/spoiler]<br/><br/>'.
					'</div>'.
					'%%acc%%'.
					'%%nsp%%';
					//---------------------------------------------------------------------------------------
					$tmp[$torrent['ss']]['te'] .= $tmp[$torrent['ss']]['t1'];							//3		сохраняем текст текущей секции
					//---------------------------------------------------------------------------------------
					$tmp[$torrent['ss']]['t1'] = '';													//4		обнуляем текст секции
					//---------------------------------------------------------------------------------------
					$tmp[$torrent['ss']]['t2'] = mb_strlen($t);											// 'обнуляем' длину секции
					$tmp[$torrent['ss']]['t3'] = $tmp[$torrent['ss']]['qt'] + 1;						//запоминаем начало следующей
					$tmp[$torrent['ss']]['t0']++;
					$t = str_replace('<br/>[*]','<br/>[*='.$tmp[$torrent['ss']]['t3'].']', $t);			//устанавливаем номер по списку для первого элемента следующей секции
				}
			}
			$tmp[$torrent['ss']]['t1'] .= $t;
			$tmp[$torrent['ss']]['qt'] += 1;
			$tmp[$torrent['ss']]['si'] += $torrent['si'];
		}
	}
	foreach($TT_subsections as &$subsection)
	{
		if(isset($tmp[$subsection['id']]))
		{
			$subsection['dlqt'] = $tmp[$subsection['id']]['qt'];
			$subsection['dlsi'] = $tmp[$subsection['id']]['si'];
			
			$tmp[$subsection['id']]['te'] = str_replace(	'%%twn%%',															//не забываем про номер окончания предыд секции, если след началась, но не продолжилась
															$tmp[$subsection['id']]['t3']-1,
															$tmp[$subsection['id']]['te']);
			$tmp[$subsection['id']]['te'] = str_replace(	'%%acc%%',															//не забываем про аккордион предыд секции, если след началась, но не продолжилась
															'<h3>Сообщение '. $tmp[$subsection['id']]['t0'] .'</h3><div title="double click me">',
															$tmp[$subsection['id']]['te']);
			$tmp[$subsection['id']]['te'] = str_replace(	'%%nsp%%',															//не забываем про аккордион и бб-коды последней секции
															//~ '[spoiler="Раздачи, взятые на хранение ('. date("Y-m-d") .')"]<br/><br/>'.
															'[spoiler="'. $tmp[$subsection['id']]['t3'] .' — %%tln%%"]<br/><br/>'.
															//~ '№№ '. $tmp[$subsection['id']]['t3'] .' - %%tln%%<br/>'.
															'[list=1]<br/>',
															$tmp[$subsection['id']]['te']);
			$tmp[$subsection['id']]['te'] = str_replace('%%tln%%', $subsection['dlqt'], $tmp[$subsection['id']]['te']);			//не забываем про номер окончания последней секции
			$tmp[$subsection['id']]['te'] .= $tmp[$subsection['id']]['t1'].'<br/><br/>[/list]<br/>'.'[/spoiler]<br/>';		//не забываем про текст последней секции
			
			$subsection['dlte'] = $tmp[$subsection['id']]['te'];
		}
	}
}

// вывод отчётов
function output_reports($TT_subsections, $TT_login, $log){

	$ini = new TIniFileEx(dirname(__FILE__) . '/config.ini');

	// заголовки вкладок
	
	$output = '<h2>Отчёты - ' . date('H:i / d.m.Y', $ini->read('other', 'update_time', '')) . '</h2>'.
		'<div id="reporttabs" class="report">'.
		'<ul class="report">';
	
	$output .= '<li class="report"><a href="#tabs-wtlocommon" class="report">Сводный отчёт</a></li>';
	
	foreach($TT_subsections as $subsection)
	{
		$output .= '<li class="report"><a href="#tabs-wtlo'.$subsection['id'].'" class="report"><span class="rp-header">№ '. $subsection['id'].'</span> - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a></li>';
	}
	
	$output .= '</ul><br/>';
	
	// содержимое вкладки сводного отчета
	
	$common_qt = 0;
	$common_si = 0;
	$ti_max_len = 0;
	$ti_curr_len = 0;
	
	//для красивого вывода: макс длина заголовка подраздела
	//~ foreach($TT_subsections as $subsection)
	//~ {
		//~ $ti_curr_len = mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3);
		//~ $ti_curr_len = strlen($subsection['na']);
		//~ if($ti_curr_len > $ti_max_len) $ti_max_len = $ti_curr_len;
	//~ }
	
	$output .= '<div id="tabs-wtlocommon" class="report">'.
		'<span class="report">'.
			'Актуально на: [b]' . date('d.m.Y', $ini->read('other', 'update_time', '')) . '[/b]<br/><br/>';
	foreach($TT_subsections as $subsection)
	{
		$common_qt += $subsection['dlqt'];
		$common_si += $subsection['dlsi'];
	}
	$output .= 
			'Общее количество хранимых раздач: [b]'. $common_qt .'[/b] шт.<br/>'.
			'Общий вес хранимых раздач: [b]'. str_replace(' ', '[/b] ', convert_bytes($common_si)).'<br />[hr]';
	foreach($TT_subsections as $subsection)
	{
		$output .= '<br/>'. $subsection['na'] . ' — ' .
			$subsection['dlqt'] .' шт. ('. convert_bytes($subsection['dlsi']) . ')';
			//~ '[[url=http://rutracker.org/forum/tracker.php?f='.$subsection['id'].'&tm=-1&o=10&s=1&oop=1][color=#993399][size=10]check[/size][/color][/url]]';
	}
	
	$output .= 
		'<br/></span>'.
		'</div>';
	
	// Содержимое вкладок подразделов
	
	foreach($TT_subsections as $subsection)
	{
		$output .= 
		
		'<div id="tabs-wtlo'.$subsection['id'].'" class="report">'.
			//~ '<p>'.mb_substr($subsection['ti'],mb_stripos($subsection['ti'],'&raquo;')+8).'</p><br/>'.
						
	//--- START REWRITE Your output representation here --- Блок задания формата вывода заголовка отчета (данные, bb коды и прочее)
			
			//~ оформление от berkut_174
			//~ '[br][b]Подраздел[/b]: [url=http://rutracker.org/forum/viewforum.php?f='.$subsection['id'].'][u][b][color=#006699]'.$subsection['na'].'[/color][/b][/u][/url] '.
			//~ '[b]»[/b] [url=http://rutracker.org/forum/tracker.php?f='.$subsection['id'].'&tm=-1&o=10&s=1&oop=1][b][u][color=#006699]Проверка сидов[/color][/u][/b][/url]<br/>'.
			//~ '[b]Всего раздач в подразделе на '. date('d.m.Y', $ini->read('other', 'update_time', '')) .'[/b]: [color=#006699][b]'. $subsection['qt'] .' шт.[/b][/color] [b]/[/b] [color=#006699][b]'.convert_bytes($subsection['si']).' [/b][/color]<br/>'.
			//~ '[b]Количество хранителей[/b]: [color=#006699][b]1[/b][/color]<br/>'.
			//~ '[b]Общее количество хранимых раздач[/b]: [color=#006699][b]'. $subsection['dlqt'] .' шт.[/b][/color]<br/>'.
			//~ '[b]Общий объём хранимых раздач[/b]: [color=#006699][b]'. convert_bytes($subsection['dlsi']) .' [/b][/color]<br/>'.
			//~ '[b]Хранитель 1[/b]: [url=http://rutracker.org/forum/profile.php?mode=viewprofile&u='.$TT_login.'&name=1][u][b][color=#006699]'.$TT_login.'[/color][/b][/u][/url] [b]»[/b] [color=#006699][b]'. $subsection['dlqt'] .' шт.[/b][/color] [b]»[/b] [color=#006699][b]'. convert_bytes($subsection['dlsi']) .' [/b][/color][br]<br/><br/>'.
			
			'Подраздел: [url=forum/viewforum.php?f='.$subsection['id'].'][u][color=#006699]'.$subsection['na'].'[/u][/color][/url]'.
			' [color=gray]~>[/color] [url=forum/tracker.php?f='.$subsection['id'].'&tm=-1&o=10&s=1&oop=1][color=indigo][u]Проверка сидов[/u][/color][/url]<br/><br/>'.
			'Актуально на: [color=darkblue]'. date('d.m.Y', $ini->read('other', 'update_time', '')) . '[/color][br]<br/>'.
			'Всего раздач в подразделе: ' . $subsection['qt'] .' шт. / ' . convert_bytes($subsection['si']) . '<br/>'.
			'Количество хранителей: 1<br/>'.
			'Всего хранимых раздач в подразделе: '. $subsection['dlqt'] .' шт. / '. convert_bytes($subsection['dlsi']) .'[hr]<br/><br/>'.
			'Хранитель 1: [url=profile.php?mode=viewprofile&u='.$TT_login.'&name=1][u][color=#006699]'.$TT_login.'[/u][/color][/url] [color=gray]~>[/color] '. $subsection['dlqt'] .' шт. [color=gray]~>[/color] '. convert_bytes($subsection['dlsi']) .'<br/><br/>'.
			
			'<br/><div id="accordion-wtlo'.$subsection['id'].'" class="report acc">'.
				'<h3>Сообщение 1</h3>'.
					'<div title="double click me">'.
		
						
						//~ оформление от berkut_174
						//~ '[b]Список актуален на [color=#006699]' . date('d.m.Y', $ini->read('other', 'update_time', '')) . '[/color][/b][br]<br/>'.
						'Актуально на: [color=darkblue]' . date('d.m.Y', $ini->read('other', 'update_time', '')) . '[/color]<br/>'.
						'Всего хранимых раздач в подразделе: ' . $subsection['dlqt'] . ' шт. / ' . convert_bytes($subsection['dlsi']) . '<br />' .
						$subsection['dlte'] . '<br/>'.					// отформатированный список хранимых раздач раздела
						
	//--- END REWRITE Your output representation here --- Блок задания формата вывода заголовка отчета (данные, bb коды и прочее)
		
					'</div>'.
				'</div>'.
		'</div>';			
	}
	
	$output .= '</div>';		
	//~ echo $output;
	echo json_encode(array('report' => $output, 'log' => $log));
}

// вывод топиков на главной странице
function output_topics($forum_url, $TT_torrents, $TT_subsections, $log){
		// заголовки вкладок
		$output = '<div id="topictabs" class="report">'.
			'<ul class="report">';
		
		foreach($TT_subsections as $subsection)
		{
			$output .= '<li class="report"><a href="#tabs-topic_'.$subsection['id'].'" class="report"><span class="rp-header">№ '.$subsection['id'].'</span> - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a></li>';
		};
		
		$output .= '</ul>';		
		
		// содержимое вкладок подразделов //
		foreach($TT_subsections as $subsection)
		{
			$output .= 
			
			'<div id="tabs-topic_'.$subsection['id'].'" class="report tab-topic">'.
			'<form action="" method="POST" id="topic_'.$subsection['id'].'">'. //форма текущей вкладки, используется для отправки данных в php
			'<div class="btn_cntrl">'. // вывод кнопок управления: выделить все, отменить выделение и скачать выделенные //
				//~ '<input type="button" name="select_'.$subsection['id'].'" title="Выделить все/снять выделение" value="Выделить все" data-id="select_'.$subsection['id'].'" id="select_'.$subsection['id'].'" onclick="SelAll(this)">'.
				//~ '<input type="button" name="unselect_'.$subsection['id'].'" value="Отменить выделение" data-id="unselect_'.$subsection['id'].'" id="unselect_'.$subsection['id'].'" onclick="SelAll(this)">'.
				//~ '<input type="button" name="dwnld_'.$subsection['id'].'" value="Скачать выделенные" data-id="dwnld_'.$subsection['id'].'" id="dwnld_'.$subsection['id'].'" onclick="DwnldSel(this)">'.
				'<button type="button" class="tor_select" action="select" subsection="'.$subsection['id'].'" title="Выделить все раздачи текущего подраздела">Выделить все</button>'.
				'<button type="button" class="tor_unselect" action="unselect" subsection="'.$subsection['id'].'" title="Снять выделение всех раздач текущего подраздела">Снять выделение</button>'.
				'<button type="button" class="tor_download" subsection="'.$subsection['id'].'" title="Скачать *.torrent файлы выделенных раздач текущего раздела в каталог"><img id="downloading_'.$subsection['id'].'" class="downloading" src="loading.gif" />Скачать</button>'.
				'<button type="button" class="tor_add" subsection="'.$subsection['id'].'" title="Добавить выделенные раздачи текущего раздела в торрент-клиент"><img id="adding_'.$subsection['id'].'" class="adding" src="loading.gif" />Добавить</button>'.
				//~ '<button type="button" id="test" onclick="test()">text</button>'.
			'</div><br/><div id="result_'.$subsection['id'].'">Выбрано раздач: <span id="tp_count_'.$subsection['id'].'" class="rp-header">0</span> (<span id="tp_size_'.$subsection['id'].'">0.00</span>).</div></br>'. // куда выводить результат после скачивания т.-файлов
			'<div class="topics" id="topics_list_'.$subsection['id'].'">';
			$q = 1;
			foreach($TT_torrents as $topic_id => &$param)
			{
				if(($param['dl'] == 0) && ($param['ss'] == $subsection['id']))
				{
					// вывод топиков
					$output .=
							'<div id="topic_' . $param['id'] . '"><label>' .
								//~ '<input type="checkbox" id="topic_'.$subsection['id'].'_'.$param['id'].'_'.$param['si'].'" onclick="SelTopic(this)">'.
								'<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$param['id'].'" subsection="'.$subsection['id'].'" size="'.$param['si'].'" hash="'.$param['hs'].'">'.
								'<a href="'.$forum_url.'/forum/viewtopic.php?t='.$param['id'].'" target="_blank">'.$param['na'].'</a>'.' ('.convert_bytes($param['si']).')'.' - '.'<span class="seeders">'.$param['se'].'</span>'.
							'</label></div>';
				}
			}
			$output .= '</div></form></div>';
		}
		
		$output .= '</div>';
		echo json_encode(array('topics' => $output, 'log' => $log));
		//~ echo $output;
}

// вывод основного интерфейса
function output_main(){
	$ini = new TIniFileEx(dirname(__FILE__) . '/config.ini');
	
	// формирование списка т.-клиентов
	$qt = $ini->read("other", "qt", "0");
	$tcs = '';
	$clients = '';
	$list_clients = array();
	for($i = 1; $i <= $qt; $i++){
		$tcs .=
			'<option value="'.$ini->read("torrent-client-$i","comment","").'" data="'.
			$ini->read("torrent-client-$i","comment","").'|'.
			$ini->read("torrent-client-$i","client","").'|'.
			$ini->read("torrent-client-$i","hostname","").'|'.
			$ini->read("torrent-client-$i","port","").'|'.
			$ini->read("torrent-client-$i","login","").'|'.
			$ini->read("torrent-client-$i","password","").
			'">'.$ini->read("torrent-client-$i","comment","").
			'</option>';
		$clients .= 
			'<option value="'.
			$ini->read("torrent-client-$i","comment","").
			'">'.$ini->read("torrent-client-$i","comment","").
			'</option>';
		$list_clients[] = $ini->read("torrent-client-$i","comment","");
	}
	
	$ss = explode(',', $ini->read('sections','subsections',''));
	$subsections = '';
	$ssclient = '';
	if(!in_array(null, $ss)){
		foreach($ss as $id){
			if(is_array($list_clients))
				$ssclient = in_array($ini->read("$id","client",""), $list_clients) ? $ini->read("$id","client","") : "";
			$subsections .=
				'<option value="'.$id.'" data="'.
				$id.'|'.
				$ini->read("$id","title","").'|'.
				$ssclient.'|'.
				$ini->read("$id","label","").'|'.
				$ini->read("$id","data-folder","").
				'">'.preg_replace('|.* » (.*)$|', '$1', $ini->read("$id","title","")).
				'</option>';
		}
	}
	
	$rt = $ini->read('sections','rule_topics','3');
	$rr = $ini->read('sections','rule_reports','10');
	$proxy_activate = (($ini->read('proxy','activate',0) == 1)?"checked":"");
	$proxy_type = $ini->read('proxy','type','http');
	$proxy_hostname = $ini->read('proxy','hostname','195.82.146.100');
	$proxy_port = $ini->read('proxy','port','3128');
	$proxy_login = $ini->read('proxy','login','');
	$proxy_paswd = $ini->read('proxy','password','');
	$lg = $ini->read('torrent-tracker','login','');
	$pw = $ini->read('torrent-tracker','password','');
	$bt_key = $ini->read('torrent-tracker','bt_key','');
	$api_key = $ini->read('torrent-tracker','api_key','');
	$api_url = $ini->read('torrent-tracker','api_url','http://api.rutracker.cc');
	$forum_url = $ini->read('torrent-tracker','forum_url','http://rutracker.cr');
	$sd = $ini->read('download','savedir','C:\Temp\\');
	$ssd = (($ini->read('download','savesubdir','') == 1)?"checked":"");
	$retracker = (($ini->read('download','retracker','') == 1)?"checked":"");
	$tor_checked = (($ini->read('tor_status','tor_checked',1) == 1)?"checked":"");
	$tor_not_checked = (($ini->read('tor_status','tor_not_checked','') == 1)?"checked":"");
	$tor_not_decoration = (($ini->read('tor_status','tor_not_decoration','') == 1)?"checked":"");
	$tor_doubtfully = (($ini->read('tor_status','tor_doubtfully',1) == 1)?"checked":"");
	$tor_temporary = (($ini->read('tor_status','tor_temporary','') == 1)?"checked":"");
	
	echo '	
		<html>
			<head>
				<meta charset="utf-8" />
				<title>web-TLO-0.8.2.7</title>
				
				<script src="jquery-ui-1.10.3.custom/js/jquery-1.9.1.js"></script>
				<script src="jquery-ui-1.10.3.custom/js/jquery-ui-1.10.3.custom.js"></script>
				<link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
				<link rel="stylesheet" href="jquery-ui-1.10.3.custom/css/smoothness/jquery-ui-1.10.3.custom.css" />
				<link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
			</head>
			<body>
				<div id="menutabs" class="menu">
					<ul class="menu">
						<li class="menu"><a href="#main" class="menu">Главная</a></li>
						<li class="menu"><a href="#settings" class="menu">Настройки</a></li>
						<li class="menu"><a href="#reports" class="menu">Отчёты</a></li>
						<li class="menu"><a href="#journal" class="menu">Журнал</a></li>
						<a id="help" href="manual.pdf" target="_blank" title="Открыть файл руководства">FAQ</a>
					</ul>
					<div id="content">
						<div id="main" class="content">
							<div id="btn-menu">
								<input id="update" name="update" type="button" class="btn-lock" title="Обновить сведения о раздачах" value="Обновить сведения">
								<input id="startreports" name="startreports" type="button" class="btn-lock" title="Сформировать отчёты для вставки на форум" value="Создать отчёты">
							</div>
							<img id="loading" src="loading.gif" title="Выполняется..."/>
							<div id="topics"></div>
						</div>
						<div id="settings" class="content">
							<form id="config">
								<input id="savecfg" name="savecfg" type="button" value="Сохранить настройки" title="Записать настройки в файл">
								<br/><br/>
								<div class="sub_settings">
									<h2>Настройки авторизации на форуме</h2>
									<div>
										<div>
											<label>
												Используемый адрес форума:
												<select name="forum_url" id="forum_url" class="myinput">
													<option value="http://rutracker.cr"' . ($forum_url == 'http://rutracker.cr' ? "selected" : "") . '>http://rutracker.cr</option>
													<option value="http://rutracker.org"' . ($forum_url == 'http://rutracker.org' ? "selected" : "") . '>http://rutracker.org</option>
													<option value="https://rutracker.cr"' . ($forum_url == 'https://rutracker.cr' ? "selected" : "") . '>https://rutracker.cr</option>
													<option value="https://rutracker.org"' . ($forum_url == 'https://rutracker.org' ? "selected" : "") . '>https://rutracker.org</option>
												</select>
											</label>
										</div>
										<div>
											<label>
												Используемый адрес API:
												<select name="api_url" id="api_url" class="myinput">
													<option value="http://api.rutracker.cc"' . ($api_url == 'http://api.rutracker.cc' ? "selected" : "") . '>http://api.rutracker.cc</option>
													<option value="http://api.rutracker.org"' . ($api_url == 'http://api.rutracker.org' ? "selected" : "") . '>http://api.rutracker.org</option>
													<option value="https://api.rutracker.cc"' . ($api_url == 'https://api.rutracker.cc' ? "selected" : "") . '>https://api.rutracker.cc</option>
													<option value="https://api.rutracker.org"' . ($api_url == 'https://api.rutracker.org' ? "selected" : "") . '>https://api.rutracker.org</option>
												</select>
											</label>
										</div>
										<div>
											<label>
												Логин:
												<input name="TT_login" class="myinput" type="text" size="24" title="Логин на http://rutracker.org" value="'
												. $lg . '">
											</label>
											<label>
												Пароль:
												<input name="TT_password" class="myinput" type="password" size="24" title="Пароль на http://rutracker.org" value="'
												. $pw . '">
											</label>
										</div>																			
										<div>
											<label>
												Ключ bt:
												<input name="bt_key" class="myinput" type="password" size="24" title="Хранительский ключ bt" value="'
												. $bt_key . '">
											</label>
											<label>
												Ключ api:
												<input name="api_key" class="myinput" type="password" size="24" title="Хранительский ключ api" value="'
												. $api_key . '">
											</label>
										</div>
									</div>
									<h2>Настройки прокси-сервера</h2>
									<div>
										<div>
											<input name="proxy_activate" id="proxy_activate" type="checkbox" size="24" title="Использовать при обращении к форуму прокси-сервер, например, для обхода блокировки." ' . $proxy_activate . '>
											<label for="proxy_activate">использовать прокси-сервер (например, для обхода блокировки)</label>											
										</div>
										<div id="proxy_prop">
											<div>
												<label>
													Тип прокси-сервера:
													<select name="proxy_type" id="proxy_type" class="myinput" title="Тип прокси-сервера">
														<option value="http" ' . ($proxy_type == 'http' ? "selected" : "") . '>HTTP</option>
														<option value="socks4" ' . ($proxy_type == 'socks4' ? "selected" : "") . '>SOCKS4</option>
														<option value="socks4a" ' . ($proxy_type == 'socks4a' ? "selected" : "") . '>SOCKS4A</option>
														<option value="socks5" ' . ($proxy_type == 'socks5' ? "selected" : "") . '>SOCKS5</option>
													</select>
												</label>
											</div>
											<div>
												<label>
													IP-адрес/сетевое имя:
													<input name="proxy_hostname" id="proxy_hostname" class="myinput" type="text" size="24" title="IP-адрес или сетевое/доменное имя прокси-сервера." value="'
													. $proxy_hostname . '">
												</label>
												<label>
													Порт:
													<input name="proxy_port" id="proxy_port" class="myinput" type="text" size="24" title="Порт прокси-сервера." value="'
													. $proxy_port . '">
												</label>
											</div>
											<div>
												<label>
													Логин:
													<input name="proxy_login" id="proxy_login" class="myinput" type="text" size="24" title="Имя пользователя для доступа к прокси-серверу (необязательно)." value="'
													. $proxy_login . '">
												</label>
												<label>
													Пароль:
													<input name="proxy_paswd" id="proxy_paswd" class="myinput" type="text" size="24" title="Пароль для доступа к прокси-серверу (необязатально)." value="'
													. $proxy_paswd . '">
												</label>
											</div>
										</div>
									</div>
									<h2>Настройки торрент-клиентов</h2>
									<div>
										<p>
											<input name="add-tc" id="add-tc" type="button" value="Добавить"/>
											<input name="del-tc" id="del-tc" type="button" value="Удалить"/>
										</p>
										
										<div class="block-settings">											
											<select id="list-tcs" size=10>
												<option value=0 data="0" disabled>список торрент-клиентов</option>'
												. $tcs .
											'</select>
										</div>
										<div class="block-settings" id="tc-prop">
											<div>
												<label>
													Название (комментарий)
													<input name="TC_comment" id="TC_comment" class="tc-prop" type="text" size="24" title="" value="">
												</label>
												<label>
													Торрент-клиент
													<select name="TC_client" id="TC_client" class="tc-prop">
														<option value="utorrent">uTorrent</option>
														<option value="transmission">Transmission</option>
														<option value="vuze" title="Web Remote   plugin">Vuze</option>
														<option value="deluge" title="WebUi   plugin">Deluge</option>
														<option value="qbittorrent">qBittorrent</option>
														<option value="ktorrent">KTorrent</option>
													</select>
												</label>
											</div>
											<div>
												<label>
													IP-адрес/сетевое имя:
													<input name="TC_hostname" id="TC_hostname" class="tc-prop" type="text" size="24" title="IP-адрес или сетевое/доменное имя компьютера с запущенным торрент-клиентом." value="">
												</label>
												<label>
													Порт:
													<input name="TC_port" id="TC_port" class="tc-prop" type="text" size="24" title="Порт веб-интерфейса торрент-клиента." value="">
												</label>
											</div>
											<div>
												<label>
													Логин:
													<input name="TC_login" id="TC_login" class="tc-prop" type="text" size="24" title="Логин для доступа к веб-интерфейсу торрент-клиента (необязатально)." value="">
												</label>
												<label>
													Пароль:
													<input name="TC_password" id="TC_password" class="tc-prop" type="password" size="24" title="Пароль для доступа к веб-интерфейсу торрент-клиента (необязатально)." value="">
												</label>
											</div>
										</div>
									</div>
									<h2>Настройки сканируемых подразделов</h2>
									<div>
										<input id="ss-add" class="myinput" type="text" size="100" placeholder="Для добавления подраздела начните вводить его индекс или название" title="Добавление нового подраздела" value="">
										<div class="block-settings">											
											<select name="list-ss" id="list-ss" size=9>
												<option value=0 data="0" disabled>список подразделов</option>'
												. $subsections .
											'</select>
										</div>
										<div class="block-settings" id="ss-prop">
											<label>
												Индекс:
												<input disabled id="ss-id" class="myinput ss-prop" type="text" size="10" title="Индекс подраздела" value="">
											</label>
											<label>
												Название:
												<input disabled id="ss-title" class="myinput ss-prop" type="text" size="70" title="Полное название подраздела" value="">
											</label>
											<label>
												Торрент-клиент:
												<select id="ss-client" class="myinput ss-prop" title="Добавлять раздачи текущего подраздела в торрент-клиент">
													<option value=0 disabled>не выбран</option>'
													. $clients .
												'</select>
											</label>
											<label>
												Метка:
												<input id="ss-label" class="myinput ss-prop" type="text" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только uTorrent и qBittorrent)" value="">
											</label>
											<label>
												Каталог для данных:
												<input id="ss-folder" class="myinput ss-prop" type="text" size="57" title="При добавлении раздачи данные сохранять в каталог (поддерживаются все кроме KTorrent)" value="">
											</label>
										</div>
									</div>
									<h2>Настройки поиска раздач</h2>
									<div>
										<h3>Получать сведения о раздачах только со статусом</h3>
										<div id="tor_status">
											<div>
												<label>
													<input class="tor_status" name="tor_not_checked" type="checkbox" size="24" title="не проверено" '.$tor_not_checked.'>
													не проверено
												</label>
											</div>
											<div>
												<label>
													<input class="tor_status" name="tor_checked" type="checkbox" size="24" title="проверено" '.$tor_checked.'>
													проверено
												</label>
											</div>
											<div>
												<label>
													<input class="tor_status" name="tor_not_decoration" type="checkbox" size="24" title="недооформлено" '.$tor_not_decoration.'>
													недооформлено
												</label>
											</div>
											<div>
												<label>
													<input class="tor_status" name="tor_doubtfully" type="checkbox" size="24" title="сомнительно" '.$tor_doubtfully.'>
													сомнительно
												</label>
											</div>
											<div>
												<label>
													<input class="tor_status" name="tor_temporary" type="checkbox" size="24" title="временная" '.$tor_temporary.'>
													временная
												</label>
											</div>											
										</div>
										<h3>Предлагать для хранения раздачи с кол-вом сидов не более</h3>
										<div>
											<input name="TT_rule_topics" class="myinput" type="text" size="24" title="Укажите числовое значение" value="'
											. $rt . '">
										</div>
										<h3>Вносить в отчёты раздачи с кол-вом сидов не более</h3>
										<div>
											<input name="TT_rule_reports" class="myinput" type="text" size="24" title="Укажите числовое значение" value="'
											. $rr . '">
										</div>
									</div>
									<h2>Настройки загрузки торрент-файлов</h2>
									<div>
										<h3>Каталог для скачиваемых *.torrent файлов</h3>
										<div>
											<input id="savedir" name="savedir" class="myinput" type="text" size="53" title="Каталог, куда будут сохраняться новые *.torrent-файлы." value="'
											. $sd . '">
										</div>
										<label>										
											<input name="savesubdir" type="checkbox" size="24" title="При установленной метке *.torrent-файлы дополнительно будут помещены в подкаталог." '.$ssd.'>
											создавать подкаталоги
										</label>
										<h3>Настройки retracker.local</h3>
										<label>
											<input name="retracker" type="checkbox" size="24" title="Добавлять retracker.local в скачиваемые *.torrent-файлы." '.$retracker.'>
											добавлять retracker.local в скачиваемые *.torrent-файлы
										</label>
									</div>		
								</div>
							</form>
						</div>					
						<div id="reports" class="content"></div>
						<div id="journal" class="content">
							<div id="log"></div>
						</div>
					</div>
				</div>
				
				<!-- скрипты webtlo -->
				<script type="text/javascript" src="js/common.js"></script>
				<script type="text/javascript" src="js/tor_clients.js"></script>
				<script type="text/javascript" src="js/subsections.js"></script>
				<script type="text/javascript" src="js/webtlo.js"></script>
				<script type="text/javascript" src="js/topics.js"></script>
				
			</body>
		</html>
		';
	}
	
?>
