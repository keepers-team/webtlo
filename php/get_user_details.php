<?php

include dirname(__FILE__) . '/../common.php';

try {
	
	parse_str( $_POST['cfg'] );

	if( empty( $tracker_username ) || empty( $tracker_password ) ) {
		throw new Exception();
	}
	
	// прокси
	$activate_forum = isset( $proxy_activate_forum ) ? 1 : 0;
	$activate_api = isset( $proxy_activate_api ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate_forum, $activate_api, $proxy_type, $proxy_address, $proxy_auth );

	UserDetails::get_details( $forum_url, $tracker_username, $tracker_password );
	
	echo json_encode(
		array(
			'bt_key' => UserDetails::$bt,
			'api_key' => UserDetails::$api,
			'user_id' => UserDetails::$uid,
			'log' => Log::get()
		)
	);

} catch (Exception $e) {
	Log::append( $e->getMessage() );
	echo json_encode(
		array(
			'bt_key' => '',
			'api_key' => '',
			'user_id' => '',
			'log' => Log::get()
		)
	);
}

?>
