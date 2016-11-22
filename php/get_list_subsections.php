<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';

try {
	
	if ( empty ( $_GET['term'] ) ) return;
	$pattern = '%' . str_replace ( ' ', '%', $_GET['term'] ) . '%';
	
	$q = Db::query_database ( "SELECT COUNT() FROM Forums", array(), true, PDO::FETCH_COLUMN );
	
	if ( empty ( $q[0] ) ) {
		$cfg = get_settings ();
		Proxy::options ( true, $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth'] );
		$webtlo = new Webtlo ( $cfg['api_url'], $cfg['api_key'] );
		$webtlo->get_cat_forum_tree ();
	}
	
	$subsections = Db::query_database (
		"SELECT id AS value, na AS label FROM Forums WHERE id LIKE :term OR na LIKE :term ORDER BY na",
		array( 'term' => $pattern ), true
	);
	
	echo json_encode ( $subsections );
	
} catch (Exception $e) {
	//~ Log::append ( $e );
	//~ echo json_encode ( array ( $e ) );
}

?>
