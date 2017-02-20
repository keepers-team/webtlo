<?php

// вывод отчётов на главной странице
function output_reports($subsections, $login){
	$update_time = Db::query_database( "SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN );
	$pattern =
		'<h2>Отчёты - ' . date('H:i / d.m.Y', $update_time[0]) . '</h2>'.
		'<div id="reporttabs" class="report">'.
			'<ul class="report">%%tabs%%</ul><br />'.
			'<div id="tabs-wtlocommon" class="report">'.
				str_replace('[br]', '', $subsections['common']).
			'</div>'.
			'%%content%%'.
		'</div>';
	unset($subsections['common']);
	$content = array();
	$tabs[] = '<li class="report"><a href="#tabs-wtlocommon" class="report">Сводный отчёт</a></li>';
	foreach($subsections as $subsection){
		if(!isset($subsection['messages'])) continue;
		$tabs[] = '<li class="report"><a href="#tabs-wtlo'.$subsection['id'].'" class="report"><span class="rp-header">№ '. $subsection['id'].'</span> - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a></li>';
		$header = str_replace('%%nick%%', $login, $subsection['header']);
		$header = str_replace('%%count%%', 1, $header);
		$header = str_replace('%%dlqt%%', $subsection['dlqt'], $header);
		$header = str_replace('%%dlsi%%', convert_bytes($subsection['dlsi']), $header);
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
	echo json_encode(array('report' => $output, 'log' => Log::get()));
}

?>
