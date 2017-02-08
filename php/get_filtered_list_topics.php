<?php

include dirname(__FILE__) . '/../common.php';

try {
	
	if( !isset( $_POST['topics_filter'] ) )
		throw new Exception();
	
	parse_str($_POST['topics_filter']);
	$forum_url = $_POST['forum_url'];
	$subsec = $_POST['subsec'];
	$kp = isset($not_keepers) ? 'AND Keepers.topic_id IS NULL' : (isset($is_keepers) ? 'AND Keepers.topic_id IS NOT NULL' : '');
	
	// средние сиды
	$avg_seeders = ($_POST['avg'] == 'true');
	$time = $_POST['time'];
	$time = $time == 0 ? 1 : ($time > 30 ? 30 : $time); // жёсткое ограничение на 30 дн.
	$ds = isset($avg_seeders_complete) && $avg_seeders ? $time : 0;
	if( $avg_seeders ) {
		for( $i = 0; $i <= $time - 1; $i++ ) {
			$avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
			$avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
			$avg['qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE 1 END";
		}
		$sum_qt = implode( '+', $avg['sum_qt'] );
		$sum_se = implode( '+', $avg['sum_se'] );
		$qt = implode( '+', $avg['qt'] );
		$avg = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END";
	} else {
		$qt = "ds";
		$avg = "se";
	}
	
	// подготовка запроса
	$where = (isset($filter_interval) ? "`avg` >= CAST(:from as REAL) AND `avg` <= CAST(:to as REAL)" : "`avg` $filter_rule_direction CAST(:se as REAL)") . " AND `dl` = :dl AND `ss` = :ss AND $qt >= CAST(:ds as INT) $kp";
	$cast = ($filter_sort == 'na' ? 'text' : ($filter_sort == 'avg' ? 'real' : 'int'));
	$param = array('dl' => $filter_status, 'ss' => $subsec, 'ds' => $ds);
	$param += isset($filter_interval) ? array('from' => $filter_rule_interval['from'], 'to' => $filter_rule_interval['to']) : array('se' => $filter_rule);
	if($subsec == 0){
		$param = array();
		$where = "`ss` = 0 AND `dl` = -2";
	}
	
	$db = new PDO('sqlite:' . dirname(__FILE__) . '/../webtlo.db');
	
	$sth = $db->prepare("
		SELECT
			Topics.id,ss,na,hs,si,st,rg,dl,qt,cl,$qt as ds,$avg as avg
		FROM
			Topics
		LEFT JOIN
			Seeders ON Topics.id = Seeders.id
		LEFT JOIN
			(SELECT * FROM Keepers GROUP BY topic_id)
			Keepers ON Topics.id = Keepers.topic_id
		WHERE $where
		ORDER BY CAST(`$filter_sort` as $cast) $filter_sort_direction
	");
	if($db->errorCode() != '0000') {
		$db_error = $db->errorInfo();
		throw new Exception( 'SQL ошибка: ' . $db_error[2] );
	}
	$sth->execute($param);
	$topics = $sth->fetchAll(PDO::FETCH_ASSOC);
	
	$sth = $db->prepare("SELECT topic_id,nick FROM `Keepers`");
	if($db->errorCode() != '0000') {
		$db_error = $db->errorInfo();
		throw new Exception( 'SQL ошибка: ' . $db_error[2] );
	}
	$sth->execute();
	$keepers = $sth->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP);
	
	$q = 1;
	$output = "";
	
	foreach($topics as $topic_id => $topic){
		$icons = ($topic['ds'] >= $time || !$avg_seeders ? 'green' : ($topic['ds'] >= $time / 2 ? 'yellow' : 'red'));
		$keeper = isset($keepers[$topic['id']]) ? ' ~> <span title="Хранители" class="bold">'.implode(', ', $keepers[$topic['id']]).'</span>' : "";
		$output .=
			'<div id="topic_' . $topic['id'] . '"><label>
				<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$topic['id'].'" subsection="'.$topic['ss'].'" size="'.$topic['si'].'" hash="'.$topic['hs'].'" client="'.$topic['cl'].'" >
				<img title="" src="img/'.$icons.'.png" />
				<a href="'.$forum_url.'/forum/viewtopic.php?t='.$topic['id'].'" target="_blank">'.$topic['na'].'</a>'.' ('.convert_bytes($topic['si']).')'.' - '.'<span class="seeders" title="Значение сидов">'.round($topic['avg'], 2).'</span>'.$keeper.
			'</label></div>';
	}
	
	//~ echo $output;
	echo json_encode(array('log' => null, 'topics' => $output));
	
} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	//~ echo $log;
	echo json_encode(array('log' => Log::get(), 'topics' => null));
}

?>
