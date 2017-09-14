<?php

include dirname(__FILE__) . '/../../common.php';

mb_regex_encoding('UTF-8');

$cfg = get_settings();

$subsections_ids = [];
if(isset($cfg['subsections'])){
	foreach($cfg['subsections'] as $id => &$ss){
		$subsections_ids[]      = $id;
		$subsections_names[$id] = $ss['na'];
	}
	$subsections_ids = implode(', ', $subsections_ids);
} else $subsections_ids = '';
$subsections_stored_ids = implode(", ", array_keys($cfg['subsections']));

try {
	$start = $_POST['start'];
	$length = $_POST['length'] != -1 ? $_POST['length'] : null;

	$forum_id = $_POST['forum_id'];
	parse_str( $_POST['config'] );
	parse_str( $_POST['filter'] );
	$filter_by_name = $_POST['filter_by_name'];
	$filter_by_keeper = $_POST['filter_by_keeper'];
	$filter_date_release_from = $_POST['filter_date_release_from'];
	$filter_date_release_until = $_POST['filter_date_release_until'];

	$filter_seeders_from = $_POST['filter_seeders_from'] != '' ? $_POST['filter_seeders_from'] : null;
	$filter_seeders_to = $_POST['filter_seeders_to'] != '' ? $_POST['filter_seeders_to'] : null;

	// некорретный ввод значений сидов
	//if(/* !is_numeric($filter_rule) || */!is_numeric($filter_seeders_from) || !is_numeric($filter_seeders_to) )
	//	throw new Exception( "В фильтре введено некорректное значение сидов." );
		
	//if(/* !is_numeric($filter_rule) || */!is_numeric($filter_seeders_from) || !is_numeric($filter_seeders_to) )
	//	throw new Exception( "Значение сидов в фильтре должно быть больше 0." );
	if (isset($filter_seeders_from) && isset($filter_seeders_to)) {
		if( $filter_seeders_from > $filter_seeders_to )
			throw new Exception( "Начальное значение сидов в фильтре должно быть меньше или равно конечному значению." );
	}

	// некорректный период средних сидов
	if( !is_numeric($avg_seeders_period) )
		throw new Exception( "В фильтре введено некорректное значение для периода средних сидов." );
	
	// некорректная дата
	$date_release_from = DateTime::createFromFormat( "d.m.Y", $filter_date_release_from );
	$date_release_until = DateTime::createFromFormat( "d.m.Y", $filter_date_release_until );
	
	// если включены средние сиды
	if( isset($avg_seeders) ) {
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
	if (isset($is_keepers)){
		if ($is_keepers == -1 ) {
			$kp = 'AND Keepers.topic_id IS NULL';
		} elseif ($is_keepers == 1) {
			$kp = 'AND Keepers.topic_id IS NOT NULL';
		} else {
			$kp = '';
		}
	} else {
		$kp = '';
	}

	$ds = isset($avg_seeders_complete) && isset($avg_seeders)
		? $avg_seeders_period
		: 0;

	// статус раздач на трекере
	$tor_status = isset( $filter_tor_status ) && is_array( $filter_tor_status )
		? implode( ',', $filter_tor_status )
		: "";

	if( $forum_id < 1 ) {
		switch( $forum_id ) {
			case 0:
				$where = "dl = -2 AND Blacklist.topic_id IS NULL";
				$param = array();
				break;
			case -2:
				$where = "Blacklist.topic_id IS NOT NULL";
				$param = array();
				break;
			case -3:
				$where = "dl = $filter_status AND ss IN ($subsections_ids) AND st IN ($tor_status) AND Blacklist.topic_id IS NULL $kp";
				$param = array();
				break;
		}
	} else {
		$where = "dl = :dl AND ss = :forum_id AND st IN ($tor_status) AND Blacklist.topic_id IS NULL $kp";
		$param = array( 'dl' => $filter_status, 'forum_id' => $forum_id );
	}
	
	// данные о раздачах
	$topics = Db::query_database(
		"SELECT Topics.id,ss,na,hs,si,st,rg,dl,cl,$qt as ds,$avg as avg ".
		"FROM Topics LEFT JOIN Seeders on Seeders.id = Topics.id ".
		"LEFT JOIN (SELECT * FROM Keepers GROUP BY topic_id) Keepers ON Topics.id = Keepers.topic_id ".
		"LEFT JOIN (SELECT * FROM Blacklist GROUP BY topic_id) Blacklist ON Topics.id = Blacklist.topic_id ".
		"WHERE $where",
		$param, true, PDO::FETCH_ASSOC
	);
	if ( $_POST['order'][0]['dir'] == 'asc' ) {
		$filter_sort_direction = 1;
	} else {
		$filter_sort_direction = - 1;
	};

	$columns_names = array(
		2 => 'st',
		3 => 'rg',
		4 => 'si',
		5 => 'avg',
		6 => 'na',
		8 => 'ss'
	);

	$torrents_statuses = array(
		0  => '<span style="color: #C71585;">*</span>',
		1  => '<span style="color: #FF4500;">x</span>',
		2  => '<span style="color: #008000;">√</span>',
		3  => '<span style="color: red;">?</span>',
		4  => '<span style="color: #FF4500;">!</span>',
		5  => '<span style="color: blue;">D</span>',
		7  => '<span style="color: #D26900;">∑</span>',
		8  => '<span style="color: #008000;">#</span>',
		9  => '<span style="color: #2424FF;">%</span>',
		10 => '<span style="color: blue;">T</span>',
		11 => '<span style="color: blue;">∏</span>'
	);

	$filter_sort = $columns_names[$_POST['order'][0]['column']];

	// сортировка раздач
	$topics = natsort_field( $topics, $filter_sort, $filter_sort_direction );

	// данные о других хранителях
	$keepers = Db::query_database(
		"SELECT topic_id,nick FROM Keepers WHERE topic_id IN (SELECT id FROM Topics WHERE ss IN ($subsections_stored_ids))",
		array(), true, PDO::FETCH_COLUMN|PDO::FETCH_GROUP
	);
	
	$q = 1;

	$output = "";
	$filtered_topics_count = 0;
	$filtered_topics_size = 0;
	
	foreach( $topics as $topic_id => $topic ) {

		// фильтрация по дате релиза
		if ($date_release_from) {
			if( $topic['rg'] < $date_release_from->format('U') ) continue;
		}
		if ($date_release_until) {
			$date_release_until->setTime(23, 59, 59);
			if( $topic['rg'] > $date_release_until->format('U') ) continue;
		}

		// фильтрация по количеству сидов
		//~ if( $forum_id > 0 ) {
		if ((!isset($filter_seeders_from)) && (isset($filter_seeders_to))) {
			if( $filter_seeders_to < $topic['avg'] ) {
				continue;
			}
		} elseif ((isset($filter_seeders_from)) && (!isset($filter_seeders_to))) {
			if( $filter_seeders_from > $topic['avg'] ) {
				continue;
			}
		} elseif ((isset($filter_seeders_from)) && (isset($filter_seeders_to))) {
			if( $filter_seeders_from > $topic['avg'] || $filter_seeders_to < $topic['avg'] ) {
				continue;
			}
		}

		// фильтрация по статусу "зелёные"
		if( $topic['ds'] < $ds ) continue;
		//~ }

		// список других хранителей
		$keeper = isset( $keepers[$topic['id']] )
			? '<span data-toggle="tooltip" title="' . implode( ',', $keepers[$topic['id']] ) . '"><span class="keeper">' . implode( '</span>, <span class="keeper">', $keepers[$topic['id']] ) . '</span></span>'
			: '';

		// фильтрация по фразе
		if (!empty($filter_by_name)) {
			if( !mb_eregi($filter_by_name, $topic['na']) ) continue;
		}

		if (!empty($filter_by_keeper)) {
			if( !mb_eregi($filter_by_keeper, $keeper) ) continue;
		}

		$icons = ($topic['ds'] >= $avg_seeders_period || !isset($avg_seeders) ? 'green' : ($topic['ds'] >= $avg_seeders_period / 2 ? 'yellow' : 'red'));

		// подготовка строки поиска альтернативных раздач

		$alternatives_name = $topic['na'];
		if ( ! empty( $alternatives_name ) ) {
			$t1 = strpos( $alternatives_name, '/' );
			if ( strpos( $alternatives_name, ']' ) > $t1 && $t1 !== false ) {
				$alternatives_name_pieces = explode( '/', $alternatives_name );
				$search_string            = $alternatives_name_pieces[0];
			} elseif ( strpos( $alternatives_name, ']' ) < $t1
			           && $t1 !== false && strpos( $alternatives_name, ']' ) !== false ) {
				$alternatives_name_pieces = explode( '/', $alternatives_name );
				$alternatives_name_pieces = explode( ']',
					$alternatives_name_pieces[0] );
				$search_string            = $alternatives_name_pieces[1];
			} elseif ( $t1 === false && strpos( $alternatives_name, '[' ) < 5
			           && strpos( $alternatives_name, '[' ) !== false ) {
				$alternatives_name_pieces = explode( ']', $alternatives_name );
				$alternatives_name_pieces = explode( '[',
					$alternatives_name_pieces[1] );
				$search_string            = $alternatives_name_pieces[0];
			} elseif ( $t1 === false
			           && strpos( $alternatives_name, '[' ) !== false ) {
				$alternatives_name_pieces = explode( '[', $alternatives_name );
				$search_string            = $alternatives_name_pieces[0];
			} else {
				$alternatives_name_pieces = explode( '[', $alternatives_name );
				$search_string            = $alternatives_name_pieces[0];
			}
			$t1 = ( $t1 !== false ) ? $t1 : 0;
			$t2 = strpos( $alternatives_name, '[', $t1 );
			if ( $t2 < 5 ) {
				$t2 = strpos( $alternatives_name, ']' ) + 1;
				$t2 = strpos( $alternatives_name, '[', $t2 );
			}
			$t2   = ( $t2 === false ) ? 0 : ( $t2 + 1 );
			$year = mb_substr( $alternatives_name, $t2 );
			if ( ! empty( $year ) ) {
				$pattern = '/(,|\s|])/';
				$years   = preg_split( $pattern, $year );
				$year    = $years[0];
			}
			if ( ! empty( $year ) ) {
				$search_string = $search_string . " " . $year;
			}
		}

		$data[] = [
			"checkbox"        => "<input type='checkbox' class='topic' tag='{$q}'
			                     id='{$topic['id']}' subsection='{$topic['ss']}'
			                     size='{$topic['si']}' hash='{$topic['hs']}' client='{$topic['cl']}' >",
			"color"           => "<img title='{$topic['ds']}' src='img/{$icons}.png'>",
			"torrents_status" => $torrents_statuses[ $topic['st'] ],
			"reg_date"        => date( 'd.m.Y', $topic['rg'] ),
			"size"            => convert_bytes( $topic['si'] ),
			"seeders"         => '<span class="seeders" title="Значение сидов">'
			                     . round( $topic['avg'], 2 ) . '</span>',
			"name"            => "<a href='{$forum_url}/forum/viewtopic.php?t={$topic['id']}'
			                     target='_blank' title='{$topic['na']}'>{$topic['na']}</a>",
			"alternatives"    => "<a href='{$forum_url}/forum/tracker.php?f={$topic['ss']}&nm={$search_string}' target='_blank'>>>></a>",
			"keepers"         => $keeper,
			"subsection"      => "<span data-toggle='tooltip' title='{$subsections_names[$topic['ss']]}'>{$topic['ss']}</span>",
		];
		$filtered_topics_count++ ;
		$filtered_topics_size += $topic['si'];
		$q++;
	}
	$part_of_data = array_slice($data, $start, $length);
	$part_of_data = !empty($part_of_data) ? $part_of_data : 0;

	$output = array(
		"draw" => (int) $_POST["draw"],
		"recordsTotal" => count($topics),
		"recordsFiltered" => count($data),
		"data" => $part_of_data,
		"count" => $filtered_topics_count,
		"size" => convert_bytes($filtered_topics_size)
	);
	echo json_encode($output);
	
} catch (Exception $e) {
	echo json_encode( array('error' => $e->getMessage()) );
}

?>
