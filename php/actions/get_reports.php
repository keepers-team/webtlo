<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../../reports.php';

try {
	
	// парсим настройки
	if ( isset ( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty ( $_POST['forum_ids'] ) ) {
		throw new Exception( "Error: Не выбраны хранимые подразделы." );
	}
	
	if ( empty ( $tracker_username ) ) {
		throw new Exception( "Error: Не указано имя пользователя на трекере." );
	}
	
	$forum_ids = $_POST['forum_ids'];
	
	// формирование отчётов
	$reports = create_reports( $forum_ids, $tracker_username );
	
	$update_time = Db::query_database(
		"SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN
	);

	$pattern =
		'<h5>Отчёты - ' . date('H:i / d.m.Y', $update_time[0]) . '</h5>'.
		'<div id="reporttabs">'.
			'<ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">%%tabs%%</ul>'.
			'<div class="tab-content">'.
				'<div id="tabs-wtlocommon" class="tab-pane fade show active" role="tabpanel">'.
					str_replace('[br]', '', $reports['common']).
				'</div>'.
				'%%content%%'.
			'</div>'.
		'</div>';

	unset( $reports['common'] );
	
	$content = array();

	$tabs[] = '<li class="nav-item"><a href="#tabs-wtlocommon" class="nav-link active" id="pills-home-tab" data-toggle="pill" role="tab">Сводный отчёт</a></li>';
	$tabs[] = '<li class="nav-item dropdown">
						<a class="nav-link dropdown-toggle" data-toggle="dropdown" href="#">Подразделы</a>
						<div class="dropdown-menu">';

	foreach ( $reports as $report ) {
		if ( ! isset ( $report['messages'] ) ) {
			continue;
		}
		$tabs[] = '<a class="dropdown-item" href="#tabs-wtlo'.$report['id'].'" role="tab" data-toggle="pill">№ '. $report['id'].' - '.mb_substr($report['na'],mb_strrpos($report['na'], ' » ') + 3).'</a>';
		$header = str_replace( '%%nick%%', $tracker_username, $report['header'] );
		$header = str_replace( '%%count%%', 1, $header );
		$header = str_replace( '%%dlqt%%', $report['dlqt'], $header );
		$header = str_replace( '%%dlsi%%', convert_bytes( $report['dlsi'] ), $header );
		$content[ $report['id'] ] =
			'<div class="tab-pane fade" id="tabs-wtlo'.$report['id'].'" role="tabpanel">'.
				str_replace('[br]', '', $header) .
				'<div class="sub_settings" id="accordion-wtlo'.$report['id'].'" role="tablist">'.
					'%%msg'.$report['id'].'%%'.
				'</div>'.
			'</div>';
		$q = 1;
		foreach ( $report['messages'] as $message ) {
			$msg[ $report['id'] ][] = '<div class="card">
											<div class="card-header" role="tab" data-toggle="collapse" data-parent="accordion-wtlo'.$report['id'].'" data-target="#tab' . $report['id'] . '-' . $q . '">
												<h6 class="mb-0">
													<a href="#tab' . $report['id'] . '-' . $q . '">Сообщение ' . $q . '</a>
												</h6>
											</div>
											<div id="tab' . $report['id'] . '-' . $q . '" class="collapse" role="tabpanel">
												<div class="card-body">'.
			                          '<div class="report_message" title="Выполните двойной клик для выделения всего сообщения">'.
			                          str_replace('[br]', '', $message['text']) .
			                          '</div>
												</div>
											</div>
										</div>';
			$q++;
		}
		$content[ $report['id'] ] = str_replace( '%%msg'.$report['id'].'%%', implode( '', $msg[ $report['id'] ] ), $content[ $report['id'] ] );
	}
	$output = str_replace( '%%tabs%%', implode( '', $tabs ), $pattern );
	$output = str_replace( '%%content%%', implode( '', $content ), $output );
	
	echo json_encode(array(
		'report' => $output,
		'log' => Log::get()
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode(array(
		'log' => Log::get(),
		'report' => "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />"
	));
}

?>
