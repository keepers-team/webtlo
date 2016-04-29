<?php

	$db = new PDO('sqlite:' . dirname(dirname(__FILE__)) . '/webtlo.db');
	$query = $db->query('SELECT * FROM `Forums`');
	if($db->errorCode() == '0000') {
		$subsections = $query->fetchAll(PDO::FETCH_ASSOC);
		$subsections = array_map(function($subsection) {
		    return array(
		        'value' => $subsection['id'],
		        'label' => $subsection['na']
		    );
		}, $subsections);
	} else {
		$ch = curl_init();
		curl_setopt_array($ch, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_ENCODING => 'gzip',
		    CURLOPT_URL => 'http://api.rutracker.cc/v1/static/cat_forum_tree',
		    CURLOPT_PROXYTYPE => 0,
			CURLOPT_PROXY => '195.82.146.100:3128'
	    ));
		$json = curl_exec($ch);
		$data = json_decode($json, true);
		curl_close($ch);
		$subsections = array();
		foreach($data['result']['c'] as $cat_id => $cat_title){
		    foreach($data['result']['tree'][$cat_id] as $forum_id => $subforum){
		        foreach($subforum as $subforum_id){
		            $subsections[$subforum_id]['value'] = $subforum_id;
		            $subsections[$subforum_id]['label'] = $cat_title.' » '.$data['result']['f'][$forum_id].' » '.$data['result']['f'][$subforum_id];
		        }
		    }
		}
	}
	
	if(!empty($_GET['term']))       
    {
		//~ $pattern = '/'.preg_quote($_GET['term']).'/iu';
		$pattern = '/'.$_GET['term'].'/iu';
		$result = array();
		foreach($subsections as $key => $subsection){
		    foreach($subsection as $subkey => $value){
		        if(preg_match($pattern, $value)){
		            $result[$key] = $subsection;
		            break;
		        }
		    }
		}
		echo json_encode($result);
		//~ echo $_GET['term'];
    }
	
?>
