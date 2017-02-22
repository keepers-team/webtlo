<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';

try {
	
	if ( empty ( $_GET['term'] ) ) return;
	$pattern = is_array( $_GET['term'] )
		? $_GET['term']
		: array( $_GET['term'] );
	
	$q = Db::query_database ( "SELECT COUNT() FROM Forums", array(), true, PDO::FETCH_COLUMN );
	
	if ( empty ( $q[0] ) ) {
		$cfg = get_settings ();
		$webtlo = new Webtlo ( $cfg['api_url'], $cfg['api_key'] );
		$webtlo->get_cat_forum_tree ();
	}
	
	$subsections = array();
	
	foreach( $pattern as $pattern ) {
		$pattern = '%' . str_replace ( ' ', '%', $pattern ) . '%';
		$data = Db::query_database (
			"SELECT id AS value, na AS label FROM Forums WHERE id LIKE :term OR na LIKE :term ORDER BY na",
			array( 'term' => $pattern ), true
		);
		$subsections = array_merge_recursive( $subsections, $data );
	}
	
	echo json_encode ( $subsections );
	
} catch (Exception $e) {
	echo json_encode(array(
		array('label' => $e->getMessage(), 'value' => -1)
	));
}

?>
