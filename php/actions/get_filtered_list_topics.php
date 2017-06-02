<?php

include dirname(__FILE__) . '/../../common.php';

mb_regex_encoding('UTF-8');

try {
	
	$forum_id = $_POST['forum_id'];
	parse_str( $_POST['config'] );
	parse_str( $_POST['filter'] );
	
	// некорретный ввод значений сидов
	if( !is_numeric($filter_rule) || !is_numeric($filter_rule_interval['from']) || !is_numeric($filter_rule_interval['to']) )
		throw new Exception( "В фильтре введено некорректное значение сидов." );
		
	if( !is_numeric($filter_rule) || !is_numeric($filter_rule_interval['from']) || !is_numeric($filter_rule_interval['to']) )
		throw new Exception( "Значение сидов в фильтре должно быть больше 0." );
	
	if( $filter_rule_interval['from'] > $filter_rule_interval['to'] )
		throw new Exception( "Начальное значение сидов в фильтре должно быть меньше или равно конечному значению." );
	
	// некорректный период средних сидов
	if( !is_numeric($avg_seeders_period) )
		throw new Exception( "В фильтре введено некорректное значение для периода средних сидов." );
	
	// некорректная дата
	$date_release = DateTime::createFromFormat( "d.m.Y", $filter_date_release );
	if( !$date_release )
		throw new Exception( "В фильтре введена некорректная дата создания релиза." );
	$date_release->setTime(23, 59, 59);
	
	// если включены средние сиды
	if( $avg_seeders ) {
		// жёсткое ограничение на 30 дней для средних сидов
		$avg_seeders_period = $avg_seeders_period > 0
			? $avg_seeders_period > 30
				? 30
				: $avg_seeders_period
			: 1;
		for( $i = 0; $i < $avg_seeders_period; $i++ ) {
			$avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
			$avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
			$avg['qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE 1 END";
		}
		$qt = implode( '+', $avg['qt'] );
		$sum_qt = implode( '+', $avg['sum_qt'] );
		$sum_se = implode( '+', $avg['sum_se'] );
		$avg = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END";
	} else {
		$qt = "ds";
		$avg = "se";
	}
	
	// подготовка запроса
	$kp = !isset($not_keepers)
		? isset($is_keepers)
			? 'AND Keepers.topic_id IS NOT NULL'
			: ''
		: 'AND Keepers.topic_id IS NULL';
	
	$ds = isset($avg_seeders_complete) && isset($avg_seeders)
		? $avg_seeders_period
		: 0;
	
	if( $forum_id < 1 ) {
		switch( $forum_id ) {
			case 0:
				$where = "dl = -2";
				$param = array();
				break;
		}
	} else {
		$where = "dl = :dl AND ss = :forum_id $kp";
		$param = array( 'dl' => $filter_status, 'forum_id' => $forum_id );
	}
	
	// данные о раздачах
	$topics = Db::query_database(
		"SELECT Topics.id,ss,na,hs,si,st,rg,dl,cl,$qt as ds,$avg as avg ".
		"FROM Topics LEFT JOIN Seeders on Seeders.id = Topics.id ".
		"LEFT JOIN (SELECT * FROM Keepers GROUP BY topic_id) Keepers ON Topics.id = Keepers.topic_id ".
		"WHERE $where",
		$param, true, PDO::FETCH_ASSOC
	);
	
	// сортировка раздач
	$topics = natsort_field( $topics, $filter_sort, $filter_sort_direction );
	
	// данные о других хранителях
	$keepers = Db::query_database(
		"SELECT topic_id,nick FROM Keepers WHERE topic_id IN (SELECT id FROM Topics WHERE ss = :forum_id)",
		array( 'forum_id' => $forum_id ), true, PDO::FETCH_COLUMN|PDO::FETCH_GROUP
	);
	
	$q = 1;
	$output = "";
	
	foreach( $topics as $topic_id => $topic ) {
		
		// фильтрация по дате релиза
		if( $topic['rg'] > $date_release->format('U') ) continue;
		
		// фильтрация по количеству сидов
		//~ if( $forum_id > 0 ) {
			if( isset($filter_interval) ) {
				if( $filter_rule_interval['from'] > $topic['avg'] || $filter_rule_interval['to'] < $topic['avg'] ) continue;
			} else {
				if( $filter_rule_direction ) {
					if( $filter_rule < $topic['avg'] ) continue;
				} else {
					if( $filter_rule > $topic['avg'] ) continue;
				}
			}
			
			// фильтрация по статусу "зелёные"
			if( $topic['ds'] < $ds ) continue;
		//~ }
		
		// список других хранителей
		$keeper = isset( $keepers[$topic['id']] )
			? ' ~> <span title="Хранители" class="bold"><span class="keeper">' . implode( '</span>, <span class="keeper">', $keepers[$topic['id']] ) . '</span></span>'
			: '';
		
		// фильтрация по фразе
		if( !empty($filter_phrase) ) {
			if( empty($filter_by_phrase) ) {
				if( !mb_eregi($filter_phrase, $keeper) ) continue;
			} else {
				if( !mb_eregi($filter_phrase, $topic['na']) ) continue;
			}
		}
		
		$icons = ($topic['ds'] >= $avg_seeders_period || !$avg_seeders ? 'green' : ($topic['ds'] >= $avg_seeders_period / 2 ? 'yellow' : 'red'));
		
		$output .=
			'<div id="topic_' . $topic['id'] . '"><label>
				<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$topic['id'].'" subsection="'.$topic['ss'].'" size="'.$topic['si'].'" hash="'.$topic['hs'].'" client="'.$topic['cl'].'" >
				<img title="" src="img/'.$icons.'.png" />
				<span>[' . date( 'd.m.Y', $topic['rg'] ) . ']</span>
				<a href="'.$forum_url.'/forum/viewtopic.php?t='.$topic['id'].'" target="_blank">'.$topic['na'].'</a>'.' ('.convert_bytes($topic['si']).')'.' - '.'<span class="seeders" title="Значение сидов">'.round($topic['avg'], 2).'</span>'.$keeper.
			'</label></div>';
		
	}
	
	echo json_encode( array('log' => Log::get(), 'topics' => $output) );
	
} catch (Exception $e) {
	echo json_encode( array('log' => $e->getMessage(), 'topics' => null) );
}

?>
