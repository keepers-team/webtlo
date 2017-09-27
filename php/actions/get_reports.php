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
		'<h2>Отчёты - ' . date( 'H:i / d.m.Y', $update_time[0] ) . '</h2>'.
		'<div id="reporttabs" class="report">'.
			'<ul class="report">%%tabs%%</ul><br />'.
			'<div id="tabs-wtlocommon" class="report">'.
				str_replace( '[br]', '', $reports['common'] ).
			'</div>'.
			'%%content%%'.
		'</div>';
		
	unset( $reports['common'] );
	
	$content = array();
	
	$tabs[] = '<li class="report"><a href="#tabs-wtlocommon" class="report">Сводный отчёт</a></li>';
	
	foreach ( $reports as $report ) {
		if ( ! isset ( $report['messages'] ) ) {
			continue;
		}
		$tabs[] = '<li class="report"><a href="#tabs-wtlo'.$report['id'].'" class="report"><span class="rp-header">№ '. $report['id'].'</span> - '.mb_substr( $report['na'], mb_strrpos( $report['na'], ' » ' ) + 3 ).'</a></li>';
		$header = str_replace( '%%nick%%', $tracker_username, $report['header'] );
		$header = str_replace( '%%count%%', 1, $header );
		$header = str_replace( '%%dlqt%%', $report['dlqt'], $header );
		$header = str_replace( '%%dlsi%%', convert_bytes( $report['dlsi'] ), $header );
		$content[ $report['id'] ] =
			'<div id="tabs-wtlo'.$report['id'].'" class="report">'.
				str_replace( '[br]', '', $header ) . '<br /><br />'.
				'<div id="accordion-wtlo'.$report['id'].'" class="report acc">'.
					'%%msg'.$report['id'].'%%'.
				'</div>'.
			'</div>';
		$q = 1;
		foreach ( $report['messages'] as $message ) {
			$msg[ $report['id'] ][] = '<h3>Сообщение ' . $q . '</h3>'.
			'<div title="Выполните двойной клик для выделения всего сообщения">'.
				str_replace( '[br]', '', $message['text'] ) .
			'</div>';
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
