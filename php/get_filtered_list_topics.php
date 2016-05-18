<?php

include dirname(__FILE__) . '/../common.php';

if(!isset($_POST['topics_filter'])) return;

parse_str($_POST['topics_filter']);
$forum_url = $_POST['forum_url'];
$subsec = $_POST['subsec'];
$where = (isset($filter_interval) ? "`avg` >= :from AND `avg` <= :to" : "`avg` $filter_rule_direction :se") . " AND `dl` = :dl AND `ss` = :ss";
$cast = ($filter_sort == 'na' ? 'text' : ($filter_sort == 'avg' ? 'real' : 'int'));

try {
	
	$db = new PDO('sqlite:' . dirname(__FILE__) . '/../webtlo.db');
	$query = $db->prepare(
		"SELECT `id`,`ss`,`na`,`hs`,`si`,`st`,`rg`,`dl`,`rt`,(`se` * 1.) / `rt` as `avg`
		FROM `Topics` WHERE $where ORDER BY CAST(`$filter_sort` as $cast) $filter_sort_direction"
	);
	if($db->errorCode() != '0000') {
		$db_error = $db->errorInfo();
		throw new Exception(get_now_datetime() . 'SQL ошибка: ' . $db_error[2] . '<br />');
	}
	$query->bindValue(':dl', $filter_status, PDO::PARAM_INT);
	$query->bindValue(':ss', $subsec, PDO::PARAM_INT);
	if(isset($filter_interval)){
		$query->bindValue(':from', $filter_rule_interval['from'], PDO::PARAM_INT);
		$query->bindValue(':to', $filter_rule_interval['to'], PDO::PARAM_INT);
	} else {
		$query->bindValue(':se', $filter_rule, PDO::PARAM_INT);
	}
	$query->execute();
	$topics = $query->fetchAll(PDO::FETCH_ASSOC);
	
	$q = 1;
	$output = "";
	
	foreach($topics as $topic_id => $topic){
		$ratio = isset($topic['rt']) ? $topic['rt'] : '1';
		$output .=
			'<div id="topic_' . $topic['id'] . '"><label>
				<input type="checkbox" class="topic" tag="'.$q++.'" id="'.$topic['id'].'" subsection="'.$topic['ss'].'" size="'.$topic['si'].'" hash="'.$topic['hs'].'">
				<a href="'.$forum_url.'/forum/viewtopic.php?t='.$topic['id'].'" target="_blank">'.$topic['na'].'</a>'.' ('.convert_bytes($topic['si']).')'.' - '.'<span class="seeders" title="средние сиды">'.round($topic['avg'], 1).'</span> / <span class="ratio" title="показатель средних сидов">'.$ratio.'</span>
			</label></div>';
	}
	
	//~ echo $output;
	echo json_encode(array('log' => null, 'topics' => $output));
	
} catch (Exception $e) {
	$log = $e->getMessage() . '<br />';
	//~ echo $log;
	echo json_encode(array('log' => $log, 'topics' => null));
}

?>
