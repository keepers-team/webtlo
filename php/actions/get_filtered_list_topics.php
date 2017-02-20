<?php

include dirname(__FILE__) . '/../../common.php';

try {
	
	$forum_id = $_POST['forum_id'];
	parse_str( $_POST['filter'] );
	parse_str( $_POST['config'] );
	
	// если введено не число
	if( !is_numeric($filter_rule) || !is_numeric($filter_rule_interval['from']) || !is_numeric($filter_rule_interval['to']) )
		throw new Exception( "Получено некорректное значение сидов." );
	
	// если включены средние сиды
	if( $avg_seeders ) {
		// жёсткое ограничение на 30 дней для средних сидов
		$avg_seeders_period = $avg_seeders_period != 0
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
	
	// данные о других хранителях
	$keepers = Db::query_database(
		"SELECT topic_id,nick FROM Keepers WHERE topic_id IN (SELECT id FROM Topics WHERE ss = :forum_id)",
		array( 'forum_id' => $forum_id ), true, PDO::FETCH_COLUMN|PDO::FETCH_GROUP
	);
	
	// сортировка раздач
	uasort( $topics, function( $a, $b ) use ( $filter_sort, $filter_sort_direction ) {
		$a[$filter_sort] = strtoupper($a[$filter_sort]);
		$b[$filter_sort] = strtoupper($b[$filter_sort]);
		return $a[$filter_sort] != $b[$filter_sort]
			? $a[$filter_sort] < $b[$filter_sort]
				? $filter_sort_direction == 'asc'
					? -1 : 1
				: ( $filter_sort_direction == 'asc' ? 1 : -1 )
			: 0;
	});
	
	
	$q = 1;
	$output = "";
	
	foreach( $topics as $topic_id => $topic ) {
		
		//~ if( $forum_id > 0 ) {
			if( isset($filter_interval) ) {
				if( $filter_rule_interval['from'] > $topic['avg'] || $filter_rule_interval['to'] < $topic['avg'] )
					continue;
			} else {
				if( $filter_rule_direction ) {
					if( $filter_rule < $topic['avg'] )
						continue;
				} else {
					if( $filter_rule > $topic['avg'] )
						continue;
				}
			}
			if( $topic['ds'] < $ds ) continue;
		//~ }
		
		$icons = ($topic['ds'] >= $avg_seeders_period || !$avg_seeders ? 'green' : ($topic['ds'] >= $avg_seeders_period / 2 ? 'yellow' : 'red'));
		$keeper = isset($keepers[$topic['id']]) ? ' ~> <span title="Хранители" class="bold">'.implode(', ', $keepers[$topic['id']]).'</span>' : "";
		$output .=
			'<div id="topic_' . $topic['id'] . '"><label>
				<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$topic['id'].'" subsection="'.$topic['ss'].'" size="'.$topic['si'].'" hash="'.$topic['hs'].'" client="'.$topic['cl'].'" >
				<img title="" src="img/'.$icons.'.png" />
				<a href="'.$forum_url.'/forum/viewtopic.php?t='.$topic['id'].'" target="_blank">'.$topic['na'].'</a>'.' ('.convert_bytes($topic['si']).')'.' - '.'<span class="seeders" title="Значение сидов">'.round($topic['avg'], 2).'</span>'.$keeper.
			'</label></div>';
		
	}
	
	echo json_encode( array('log' => Log::get(), 'topics' => $output) );
	
} catch (Exception $e) {
	Log::append( $e->getMessage() );
	$output = '<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />';
	echo json_encode( array('log' => Log::get(), 'topics' => $output) );
}

?>
