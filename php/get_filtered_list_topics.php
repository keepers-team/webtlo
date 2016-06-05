<?php

include dirname(__FILE__) . '/../common.php';

try {
	
	if(!isset($_POST['topics_filter'])) throw new Exception();
	
	parse_str($_POST['topics_filter']);
	$forum_url = $_POST['forum_url'];
	$subsec = $_POST['subsec'];
	
	// средние сиды
	$avg_seeders = ($_POST['avg'] == 'true');
	$time = $_POST['time'];
	$time = $time == 0 ? 1 : ($time > 30 ? 30 : $time); // жёсткое ограничение на 30 дн.
	$ds = isset($avg_seeders_complete) && $avg_seeders ? $time : 0;
	for($i = 0; $i <= $time - 1; $days_fields[] = 'd'.$i++);
	$avg = '(' . implode ( '+', preg_replace('|^(.*)$|', 'CASE WHEN $1 IS "" OR $1 IS NULL THEN 0 ELSE $1 END', $days_fields )) . ' + (`se` * 1.) / `rt` ) /
		(' . implode('+', preg_replace('|^(.*)$|', 'CASE WHEN $1 IS "" OR $1 IS NULL THEN 0 ELSE 1 END', $days_fields )) . ' + 1 )';
	
	// подготовка запроса
	$where = (isset($filter_interval) ? "`avg` >= CAST(:from as REAL) AND `avg` <= CAST(:to as REAL)" : "`avg` $filter_rule_direction CAST(:se as REAL)");
	$cast = ($filter_sort == 'na' ? 'text' : ($filter_sort == 'avg' ? 'real' : 'int'));
	$param = array('dl' => $filter_status, 'ss' => $subsec, 'ds' => $ds);
	$param += isset($filter_interval) ? array('from' => $filter_rule_interval['from'], 'to' => $filter_rule_interval['to']) : array('se' => $filter_rule);
	
	$db = new PDO('sqlite:' . dirname(__FILE__) . '/../webtlo.db');
	
	$sth = $db->prepare("
		SELECT
			`Topics`.`id`,`ss`,`na`,`hs`,`si`,`st`,`rg`,`dl`,`rt`,`ds`,`ud`,
			CASE
				WHEN `ds` IS 0
				THEN (`se` * 1.) / `rt`
				ELSE $avg
			END as `avg`
		FROM
			`Topics`
			INNER JOIN
			`Seeders`
				ON `Topics`.`id` = `Seeders`.`id`
			INNER JOIN `Other`
		WHERE $where AND `dl` = :dl AND `ss` = :ss AND `ds` >= CAST(:ds as INT)
		ORDER BY CAST(`$filter_sort` as $cast) $filter_sort_direction
	");
	if($db->errorCode() != '0000') {
		$db_error = $db->errorInfo();
		throw new Exception(get_now_datetime() . 'SQL ошибка: ' . $db_error[2] . '<br />');
	}
	$sth->execute($param);
	$topics = $sth->fetchAll(PDO::FETCH_ASSOC);
	
	$q = 1;
	$output = "";
	
	foreach($topics as $topic_id => $topic){
		$icons = ($topic['ds'] >= $time || !$avg_seeders ? 'green' : ($topic['ds'] >= $time / 2 ? 'yellow' : 'red'));
		$output .=
			'<div id="topic_' . $topic['id'] . '"><label>
				<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$topic['id'].'" subsection="'.$topic['ss'].'" size="'.$topic['si'].'" hash="'.$topic['hs'].'">
				<img title="" src="img/'.$icons.'.png" />
				<a href="'.$forum_url.'/forum/viewtopic.php?t='.$topic['id'].'" target="_blank">'.$topic['na'].'</a>'.' ('.convert_bytes($topic['si']).')'.' - '.'<span class="seeders" title="Значение сидов">'.round($topic['avg'], 1).'</span>
			</label></div>';
	}
	
	//~ echo $output;
	echo json_encode(array('log' => null, 'topics' => $output));
	
} catch (Exception $e) {
	$log = $e->getMessage();
	//~ echo $log;
	echo json_encode(array('log' => $log, 'topics' => null));
}

?>
