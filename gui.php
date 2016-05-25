<?php

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
			
			$t = '<br/>[*][url=viewtopic.php?t='.$torrent['id'].']'.$torrent['na'].'[/url] '.convert_bytes($torrent['si']).' -  [color=red]'.round($torrent['avg']).'[/color]';
			
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
	}
	
	$output .= 
		'<br/></span>'.
		'</div>';
	
	// Содержимое вкладок подразделов
	
	foreach($TT_subsections as $subsection)
	{
		$output .= 
		
		'<div id="tabs-wtlo'.$subsection['id'].'" class="report">'.
						
	//--- START REWRITE Your output representation here --- Блок задания формата вывода заголовка отчета (данные, bb коды и прочее)
			
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
function output_topics($forum_url, $TT_torrents, $TT_subsections, $rule_topics, $log){
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
			
			'<div id="tabs-topic_'.$subsection['id'].'" class="report tab-topic" value="'.$subsection['id'].'">
			<div class="btn_cntrl">'. // вывод кнопок управления раздачами
				'<button type="button" class="tor_select" value="select" title="Выделить все раздачи текущего подраздела">Выделить все</button>
				<button type="button" class="tor_unselect" value="unselect" title="Снять выделение всех раздач текущего подраздела">Снять выделение</button>
				<button type="button" class="tor_download" title="Скачать *.torrent файлы выделенных раздач текущего подраздела в каталог"><img class="loading" src="loading.gif" />Скачать</button>
				<button type="button" class="tor_add" title="Добавить выделенные раздачи текущего подраздела в торрент-клиент"><img class="loading" src="loading.gif" />Добавить</button>
				<button type="button" value="remove" class="tor_remove torrent_action" title="Удалить выделенные раздачи текущего подраздела из торрент-клиента"><img class="loading" src="loading.gif" />Удалить</button>
				<button type="button" value="start" class="tor_start torrent_action" title="Запустить выделенные раздачи текущего подраздела в торрент-клиенте"><img class="loading" src="loading.gif" />Старт</button>
				<button type="button" value="stop" class="tor_stop torrent_action" title="Приостановить выделенные раздачи текущего подраздела в торрент-клиенте"><img class="loading" src="loading.gif" />Стоп</button>
				<button type="button" value="set_label" class="tor_label torrent_action" title="Установить метку для выделенных раздач текущего подраздела в торрент-клиенте"><img class="loading" src="loading.gif" />Метка</button>
			</div>
			<form method="post" id="topics_filter_'.$subsection['id'].'">
				<div class="topics_filter" title="Фильтр раздач текущего подраздела">
					<fieldset class="filter_status" title="Статусы">
						<label>
							<input type="radio" name="filter_status" value="1" />
							храню<br />
						</label>
						<label>
							<input type="radio" name="filter_status" value="0" checked />
							не храню<br />
						</label>
						<label>
							<input type="radio" name="filter_status" value="-1" />
							качаю<br />
						</label>
					</fieldset>
					<fieldset class="filter_sort" title="Сортировка">
						<div class="filter_sort_direction">
							<label>
								<input type="radio" name="filter_sort_direction" value="asc" checked />
								по возрастанию<br />
							</label>
							<label>
								<input type="radio" name="filter_sort_direction" value="desc" />
								по убыванию<br />
							</label>
						</div>
						<div class="filter_sort_value">
							<label>
								<input type="radio" name="filter_sort" value="na" checked />
								по названию<br />
							</label>
							<label>
								<input type="radio" name="filter_sort" value="si" />
								по объёму<br />
							</label>
							<label>
								<input type="radio" name="filter_sort" value="avg" />
								по количеству сидов<br />
							</label>
							<label>
								<input type="radio" name="filter_sort" value="rg" />
								по дате регистрации<br />
							</label>
						</div>
					</fieldset>
					<fieldset class="filter_rule" title="Сиды">
						<label title="Использовать интервал сидов">
							<input type="checkbox" name="filter_interval" />
							интервал
						</label>
						<div class="filter_rule_one">
							<div class="filter_rule_direction">
								<label>
									<input type="radio" name="filter_rule_direction" value="<=" checked />
									не более<br />
								</label>
								<label>
									<input type="radio" name="filter_rule_direction" value=">=" />
									не менее<br />
								</label>
							</div>
							<div class="filter_rule">
								<label title="Количество сидов">
									<input type="text" name="filter_rule" size="1" value="'.$rule_topics.'" />
								</label>
							</div>
						</div>
						<div class="filter_rule_interval" style="display: none">
							<label title="Начальное количество сидов">
								от
								<input type="text" name="filter_rule_interval[from]" size="1" value="0" />
							</label>
							<label title="Конечное количество сидов">
								до
								<input type="text" name="filter_rule_interval[to]" size="1" value="'.$rule_topics.'" />
							</label>
						</div>
					</fieldset>
				</div>
			</form>
			<div id="result_'.$subsection['id'].'" class="topics_result">Выбрано раздач: <span id="tp_count_'.$subsection['id'].'" class="rp-header">0</span> (<span id="tp_size_'.$subsection['id'].'">0.00</span>).</div></br>'. // куда выводить результат после скачивания т.-файлов
			'<div class="topics" id="topics_list_'.$subsection['id'].'">';
			$q = 1;
			foreach($TT_torrents as $topic_id => &$param)
			{
				if(($param['dl'] == 0) && ($param['ss'] == $subsection['id']))
				{
					// вывод топиков
					$ratio = isset($param['rt']) ? $param['rt'] : '1';
					$output .=
							'<div id="topic_' . $param['id'] . '"><label>
								<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$param['id'].'" subsection="'.$subsection['id'].'" size="'.$param['si'].'" hash="'.$param['hs'].'">
								<a href="'.$forum_url.'/forum/viewtopic.php?t='.$param['id'].'" target="_blank">'.$param['na'].'</a>'.' ('.convert_bytes($param['si']).')'.' - '.'<span class="seeders" title="средние сиды">'.round($param['avg'], 1).'</span> / <span class="ratio" title="показатель средних сидов">'.$ratio.'</span>
							</label></div>';
				}
			}
			$output .= '</div></div>';
		}
		
		$output .= '</div>';
		echo json_encode(array('topics' => $output, 'log' => $log));
		//~ echo $output;
}
	
?>
