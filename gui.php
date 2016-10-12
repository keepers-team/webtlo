<?php

// вывод отчётов на главной странице
function output_reports($subsections, $login, $log){
	$pattern =
		'<h2>Отчёты - ' . date('H:i / d.m.Y') . '</h2>'.
		'<div id="reporttabs" class="report">'.
			'<ul class="report">%%tabs%%</ul><br />'.
			'<div id="tabs-wtlocommon" class="report">'.
				str_replace('[br]', '', $subsections['common']).
			'</div>'.
			'%%content%%'.
		'</div>';
	unset($subsections['common']);
	$tabs[] = '<li class="report"><a href="#tabs-wtlocommon" class="report">Сводный отчёт</a></li>';
	foreach($subsections as $subsection){
		$tabs[] = '<li class="report"><a href="#tabs-wtlo'.$subsection['id'].'" class="report"><span class="rp-header">№ '. $subsection['id'].'</span> - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a></li>';
		$header = str_replace('%%nick%%', $login, $subsection['header']);
		$content[$subsection['id']] =
			'<div id="tabs-wtlo'.$subsection['id'].'" class="report">'.
				str_replace('[br]', '', $header) . '<br /><br />'.
				'<div id="accordion-wtlo'.$subsection['id'].'" class="report acc">'.
					'%%msg'.$subsection['id'].'%%'.
				'</div>'.
			'</div>';
		$q = 1;
		foreach($subsection['messages'] as $message){
			$msg[$subsection['id']][] = '<h3>Сообщение ' . $q . '</h3>'.
			'<div title="Выполните двойной клик для выделения всего сообщения">'.
				str_replace('[br]', '', $message['text']) .
			'</div>';
			$q++;
		}
		$content[$subsection['id']] = str_replace('%%msg'.$subsection['id'].'%%', implode('', $msg[$subsection['id']]), $content[$subsection['id']]);
	}
	$output = str_replace('%%tabs%%', implode('', $tabs), $pattern);
	$output = str_replace('%%content%%', implode('', $content), $output);
	
	//~ echo $output;
	echo json_encode(array('report' => $output, 'log' => $log));
}

// вывод топиков на главной странице
function output_topics($forum_url, $TT_torrents, $TT_subsections, $rule_topics, $time, $avg_seeders, $log){
		// заголовки вкладок
		$output = '<div id="topictabs" class="report">'.
			'<ul class="report">';
		
		foreach($TT_subsections as $subsection)
		{
			$output .= '<li class="report"><a href="#tabs-topic_'.$subsection['id'].'" class="report"><span class="rp-header">№ '.$subsection['id'].'</span> - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a></li>';
		};
		
		$output .= '<li class="report"><a href="#tabs-topic_0" class="report">Другое</a></li></ul>';
		
		// содержимое вкладок подразделов
		$TT_subsections[]['id'] = 0;
		foreach($TT_subsections as $subsection)
		{
			$output .= 
			
			'<div id="tabs-topic_'.$subsection['id'].'" class="report tab-topic" value="'.$subsection['id'].'">
			<div class="btn_cntrl">'. // вывод кнопок управления раздачами
				'<button type="button" class="tor_select" value="select" title="Выделить все раздачи текущего подраздела">Выделить все</button>
				<button type="button" class="tor_unselect" value="unselect" title="Снять выделение всех раздач текущего подраздела">Снять выделение</button>
				<button type="button" class="tor_download" title="Скачать *.torrent файлы выделенных раздач текущего подраздела в каталог"><img class="loading" src="img/loading.gif" />Скачать</button>
				<button type="button" class="tor_add" title="Добавить выделенные раздачи текущего подраздела в торрент-клиент"><img class="loading" src="img/loading.gif" />Добавить</button>
				<button type="button" value="remove" class="tor_remove torrent_action" title="Удалить выделенные раздачи текущего подраздела из торрент-клиента"><img class="loading" src="img/loading.gif" />Удалить</button>
				<button type="button" value="start" class="tor_start torrent_action" title="Запустить выделенные раздачи текущего подраздела в торрент-клиенте"><img class="loading" src="img/loading.gif" />Старт</button>
				<button type="button" value="stop" class="tor_stop torrent_action" title="Приостановить выделенные раздачи текущего подраздела в торрент-клиенте"><img class="loading" src="img/loading.gif" />Стоп</button>
				<button type="button" value="set_label" class="tor_label torrent_action" title="Установить метку для выделенных раздач текущего подраздела в торрент-клиенте (удерживайте Ctrl для установки произвольной метки)"><img class="loading" src="img/loading.gif" />Метка</button>
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
						<br />
						<label title="Отображать только раздачи, для которых информация о сидах содержится за весь период, указанный в настройках (при использовании алгоритма нахождения среднего значения количества сидов)">
							<input type="checkbox" name="avg_seeders_complete" />
							"зелёные"
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
								<input type="radio" name="filter_sort" value="na" />
								по названию<br />
							</label>
							<label>
								<input type="radio" name="filter_sort" value="si" />
								по объёму<br />
							</label>
							<label>
								<input type="radio" name="filter_sort" value="avg" checked />
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
				if(($param['dl'] == 0 || $param['dl'] == -2) && $param['ss'] == $subsection['id'])
				{
					// вывод топиков
					$icons = ($param['ds'] >= $time || !$avg_seeders ? 'green' : ($param['ds'] >= $time / 2 ? 'yellow' : 'red'));
					$output .=
							'<div id="topic_' . $param['id'] . '"><label>
								<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$param['id'].'" subsection="'.$subsection['id'].'" size="'.$param['si'].'" hash="'.$param['hs'].'" client="'.$param['cl'].'">
								<img title="" src="img/'.$icons.'.png" />
								<a href="'.$forum_url.'/forum/viewtopic.php?t='.$param['id'].'" target="_blank">'.$param['na'].'</a>'.' ('.convert_bytes($param['si']).')'.' - '.'<span class="seeders" title="Значение сидов">'.round($param['avg'], 1).'</span>
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
