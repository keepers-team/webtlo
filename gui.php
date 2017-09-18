<?php

// вывод отчётов на главной странице
function output_reports($subsections, $login){
	$update_time = Db::query_database( "SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN );
	$pattern =
		'<h5>Отчёты - ' . date('H:i / d.m.Y', $update_time[0]) . '</h5>'.
		'<div id="reporttabs">'.
			'<ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">%%tabs%%</ul>'.
			'<div class="tab-content">'.
				'<div id="tabs-wtlocommon" class="tab-pane fade show active" role="tabpanel">'.
					str_replace('[br]', '', $subsections['common']).
				'</div>'.
			'%%content%%'.
		'</div></div>';
	unset($subsections['common']);
	$content = array();
	$tabs[] = '<li class="nav-item"><a href="#tabs-wtlocommon" class="nav-link active" id="pills-home-tab" data-toggle="pill" role="tab">Сводный отчёт</a></li>';
	$tabs[] = '<li class="nav-item dropdown">
						<a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#">Подразделы</a>
						<div class="dropdown-menu">';
	foreach($subsections as $subsection){
		if(!isset($subsection['messages'])) continue;
		$tabs[] = '<a class="dropdown-item" href="#tabs-wtlo'.$subsection['id'].'" role="tab" data-toggle="pill">№ '. $subsection['id'].' - '.mb_substr($subsection['na'],mb_strrpos($subsection['na'], ' » ')+3).'</a>';
		$header = str_replace('%%nick%%', $login, $subsection['header']);
		$header = str_replace('%%count%%', 1, $header);
		$header = str_replace('%%dlqt%%', $subsection['dlqt'], $header);
		$header = str_replace('%%dlsi%%', convert_bytes($subsection['dlsi']), $header);
		$content[$subsection['id']] =
			'<div class="tab-pane fade" id="tabs-wtlo'.$subsection['id'].'" role="tabpanel">'.
				str_replace('[br]', '', $header) .
				'<div class="sub_settings" id="accordion-wtlo'.$subsection['id'].'" role="tablist">'.
					'%%msg'.$subsection['id'].'%%'.
				'</div>'.
			'</div>';
		$q = 1;
		foreach($subsection['messages'] as $message){
			$msg[$subsection['id']][] = '<div class="card">
											<div class="card-header" role="tab" data-toggle="collapse" data-parent="accordion-wtlo'.$subsection['id'].'" data-target="#tab' . $subsection['id'] . '-' . $q . '">
												<h6 class="mb-0">
													<a href="#tab' . $subsection['id'] . '-' . $q . '">Сообщение ' . $q . '</a>
												</h6>
											</div>
											<div id="tab' . $subsection['id'] . '-' . $q . '" class="collapse" role="tabpanel">
												<div class="card-body">'.
													'<div title="Выполните двойной клик для выделения всего сообщения">'.
														str_replace('[br]', '', $message['text']) .
													'</div>
												</div>
											</div>
										</div>';
			$q++;
		}
		$content[$subsection['id']] = str_replace('%%msg'.$subsection['id'].'%%', implode('', $msg[$subsection['id']]), $content[$subsection['id']]);
	}
	$tabs[] = '</div></li>';
	$output = str_replace('%%tabs%%', implode('', $tabs), $pattern);
	$output = str_replace('%%content%%', implode('', $content), $output);
	
	//~ echo $output;
	echo json_encode(array('report' => $output, 'log' => Log::get()));
}

?>
