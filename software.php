<?php
/**
 * Helper Functions
 */

function wc_edd_email_purchase_receipt( $payment_id, $download_id ) {

	$payment_data = edd_get_payment_meta( $payment_id );

	$download = get_post( $download_id );

	$license = edd_software_licensing()->get_license_by_purchase( $payment_id, $download_id );

	$from_name    = edd_get_option( 'from_name', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$from_name    = apply_filters( 'edd_purchase_from_name', $from_name, $payment_id, $payment_data );

	$from_email   = edd_get_option( 'from_email', get_bloginfo( 'admin_email' ) );
	$from_email   = apply_filters( 'edd_purchase_from_address', $from_email, $payment_id, $payment_data );

	$to_email     = edd_get_payment_user_email( $payment_id );

	$subject      = edd_get_option( 'purchase_subject', __( 'New License Key', 'edd' ) );
	$subject      = apply_filters( 'edd_purchase_subject', wp_strip_all_tags( $subject ), $payment_id );
	$subject      = edd_do_email_tags( $subject, $payment_id );

	$message = "Dear " . edd_email_tag_first_name( $payment_id ) . ",\n\n";
	$message .= "As you have updated " . $download->post_title . ", please use following new license key to continue getting future updates: \n\n";
	$message .= "License Key : " . edd_software_licensing()->get_license_key( $license->ID ) . "\n\n";
	$message .= "Sorry for inconvenience.";

	$emails = EDD()->emails;

	$emails->__set( 'from_name', $from_name );
	$emails->__set( 'from_email', $from_email );
	$emails->__set( 'heading', __( 'Purchase Receipt', 'edd' ) );


	$headers = apply_filters( 'edd_receipt_headers', $emails->get_headers(), $payment_id, $payment_data );
	$emails->__set( 'headers', $headers );

	$emails->send( $to_email, $subject, $message, array() );

	if ( $admin_notice && ! edd_admin_notices_disabled( $payment_id ) ) {
		do_action( 'edd_admin_sale_notice', $payment_id, $payment_data );
	}
}

function wc_edd_decrypt( $string ) {

	if ( empty( $string ) ) {
		return $string;
	}

	$nonce_salt = ( defined( 'NONCE_SALT' ) ) ? NONCE_SALT : ']K4eR{$@^@.Cb*P6+i0 jg&qEa8+V H-@N>:WuL/pW^z9nEte j|]{w!i!B~|saD';

	$key = md5( DB_NAME . DB_USER . DB_PASSWORD . $nonce_salt );

	if ( stripos( $string, '$Api_Manager$ENC1$' ) !== FALSE ) {
		$string = str_ireplace( '$Api_Manager$ENC1$', '', $string );
		$result = '';
		$string = base64_decode( $string );
		for ( $i = 0; $i < strlen( $string ); $i ++ ) {
			$char    = substr( $string, $i, 1 );
			$keychar = substr( $key, ( $i % strlen( $key ) ) - 1, 1 );
			$char    = chr( ord( $char ) - ord( $keychar ) );
			$result .= $char;
		}

		return $result;
	}

	if ( function_exists( 'mcrypt_encrypt' ) && stripos( $string, '$Api_Manager$RIJNDAEL$' ) !== FALSE) {
		$string = str_ireplace( '$Api_Manager$RIJNDAEL$', '', $string );

		return rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, md5( $key ), base64_decode( $string ), MCRYPT_MODE_CBC, md5( md5( $key ) ) ), "\0" );
	}

	return $string;
}

function wc_edd_format_secure_s3_url( $url, $expire = false ) {

	if ( ! empty( $url ) ) {

		$secret_key = wc_edd_decrypt( get_option( 'woocommerce_api_manager_amazon_s3_secret_access_key' ) );

		if ( $expire === false ) {

			$expire = time() + ( get_option( 'woocommerce_api_manager_url_expire' ) * MINUTE_IN_SECONDS );

		}

		$objectpath = parse_url( $url, PHP_URL_PATH );

		$signature = utf8_encode( "GET\n\n\n$expire\n" . $objectpath );

		$hashed_signature = base64_encode( hash_hmac( 'sha1' ,$signature , $secret_key , true ) );

		$query_string = array(
			'AWSAccessKeyId'	=> get_option( 'woocommerce_api_manager_amazon_s3_access_key_id' ),
			'Expires'			=> $expire,
			'Signature'			=> $hashed_signature
		);

		return $url . '?' . http_build_query( $query_string, '', '&' );

	}

	return '';

}

function wc_edd_find_amazon_s3_in_url( $url ) {
	$result = preg_match( '!\b(amazonaws.com)\b!', $url );

	if ( $result == 1 ) {
		return true;
	}

	return false;
}

function wc_edd_get_download_url( $post_id ) {

	$file_path = get_post_meta( $post_id, '_downloadable_files', true );

	if ( is_array( $file_path ) ) {
		foreach ( $file_path as $key => $value ) {
			$path[] = $value;
		}
	}

	if ( empty( $path[0] ) ) {
		return false;
	}

	if ( empty( $path[0]['file'] ) ) {
		return false;
	}

	return $path[0]['file'];
}

function wc_edd_get_downloadable_data( $order_key, $activation_email, $product_id, $download_id = '' ) {
	global $wpdb;

	$sql = "
			SELECT product_id,order_id,downloads_remaining,user_id,download_count,access_expires,download_id
			FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
			WHERE user_email = %s
			AND order_key = %s
			AND product_id = %s
			AND download_id = %s";

	$args = array(
		$activation_email,
		$order_key,
		$product_id,
		$download_id
	);

	// Returns an Object
	$result = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );

	if ( is_object( $result ) ) {
		return $result;
	}

	return false;

}

function wc_edd_get_download_id( $post_id ) {

	$file_path = get_post_meta( $post_id, '_downloadable_files', true );

	if ( is_array( $file_path ) ) {
		foreach ( $file_path as $key => $value ) {
			$path[] = $key;
		}
	}

	if ( empty( $path[0] )  ) {
		return false;
	}

	return $path[0];
}

function wc_edd_get_download_count( $order_id, $order_key ) {
	global $wpdb;

	$download_count = $wpdb->get_var( $wpdb->prepare( "
			SELECT download_count FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
			WHERE order_id = %s
			AND order_key = %s
			LIMIT 1
		", $order_id, $order_key ) );

	if ( isset( $download_count ) ) {

		return $download_count;

	}

	return false;
}

function wc_edd_send_api_data( $request, $plugin_name, $version, $order_id, $api_key, $activation_email, $post_id, $order_key, $user ) {

	$debug_log = "REQUEST : ".var_export($request, true)."\n\n";
	$debug_log .= "PLUGIN NAME : ".var_export($plugin_name,true)."\n\n";
	$debug_log .= "VERSION : ".var_export($version, true)."\n\n";
	$debug_log .= "WC ORDER ID : ".var_export($order_id,true)."\n\n";
	$debug_log .= "WC API KEY : ".var_export($api_key,true)."\n\n";
	$debug_log .= "EMAIL : ".var_export($activation_email, true)."\n\n";
	$debug_log .= "WC Product ID : ".var_export($post_id, true)."\n\n";
	$debug_log .= "WC Order Key : ".var_export($order_key,true)."\n\n";
	$debug_log .= "User ID: ".var_export($user->ID,true)."\n\n";

	// The download ID is needed for the order specific download URL
	$download_id = wc_edd_get_download_id( $post_id );

	$debug_log .= "DOWNLOAD ID : ".var_export($download_id,true)."\n\n";

	$downloadable_data = wc_edd_get_downloadable_data( $order_key, $activation_email, $post_id, $download_id );

	$debug_log .= "DOWNLOADABLE DATA : ".var_export($downloadable_data,true)."\n\n";

	$downloads_remaining 	= $downloadable_data->downloads_remaining;
	$download_count 		= $downloadable_data->download_count;
	$access_expires 		= $downloadable_data->access_expires;
	$product_id             = $downloadable_data->product_id;
	$user_id                = $downloadable_data->user_id;
	$order_id               = $downloadable_data->order_id;

	$edd_order = new WP_Query(
		array(
			'post_type' => 'edd_payment',
		    'post_status' => 'any',
		    'nopaging' => true,
		    'meta_query' => array(
			    array(
				    'key' => '_wc_order_id',
			        'value' => $order_id,
			    )
		    ),
		)
	);

	$debug_log .= "EDD ORDER QUERY : ".var_export($edd_order->request,true)."\n\n";

	if( empty( $edd_order->posts ) ) {
		wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'download_revoked' => 'no order found' ) );
	}

	$edd_order = $edd_order->posts;
	$edd_order = $edd_order[0];

	$edd_order_id = $edd_order->ID;

	if ( get_post_type( $product_id ) == 'product_variation' ) {
		$variation = get_post( $product_id );
		$edd_product = new WP_Query(
			array(
				'post_type' => 'download',
				'post_status' => 'any',
				'nopaging' => true,
				'meta_query' => array(
					array(
						'key' => '_wc_product_id',
						'value' => $variation->post_parent,
					),
				),
			)
		);
	} else {
		$edd_product = new WP_Query(
			array(
				'post_type' => 'download',
				'post_status' => 'any',
				'nopaging' => true,
				'meta_query' => array(
					array(
						'key' => '_wc_product_id',
						'value' => $product_id,
					),
				),
			)
		);
	}

	$debug_log .= "EDD PRODUCT QUERY : ".var_export($edd_product->request,true)."\n\n";

	if ( empty( $edd_product->posts ) ) {
		wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'download_revoked' => 'no product found' ) );
	}

	$edd_product = $edd_product->posts;
	$edd_product = $edd_product[0];

	$edd_product_id = $edd_product->ID;

	// Get the API data in an array
	$api_data = get_post_custom( $post_id );

	/**
	 * Check for Amazon S3 URL
	 * @since 1.3.2
	 */

	// Build the order specific download URL

	$download_name 	= get_the_title( $edd_product_id );
	$file_key 		= get_post_meta( $edd_product_id, '_edd_sl_upgrade_file_key', true );

	$debug_log .= "DOWNLOAD NAME : ".var_export($download_name,true)."\n\n";
	$debug_log .= "FILE KEY : ".var_export($file_key,true)."\n\n";

	$hash = md5( $download_name . $file_key . $edd_product_id );

	$download_link = add_query_arg( array(
		'edd_action' 	=> 'package_download',
		'id' 			=> $edd_product_id,
		'key' 			=> $hash,
		'expires'		=> rawurlencode( base64_encode( strtotime( '+1 hour' ) ) ),
	), trailingslashit( home_url() ) );

	$debug_log .= "DOWNLOAD LINK : ".var_export($download_link,true)."\n\n";

	if ( $download_link === false || empty( $download_link ) ) {

		wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'download_revoked' => 'download link false' ) );

	}

	$new_version = get_post_meta( $edd_product_id, '_edd_sl_version', true );

	$debug_log .= "NEW VERSION : ".var_export($new_version,true)."\n\n";

	/**
	 * Prepare pages for display in upgrade "View version details" screen
	 */
	$desc_obj 		= get_post( $api_data['_api_description'][0] );
	$install_obj 	= get_post( $api_data['_api_installation'][0] );
	$faq_obj 		= get_post( $api_data['_api_faq'][0] );
	$screen_obj 	= get_post( $api_data['_api_screenshots'][0] );
	$change_obj 	= get_post( $api_data['_api_changelog'][0] );
	$notes_obj 		= get_post( $api_data['_api_other_notes'][0] );

	// Instantiate $response object
	$response = new stdClass();

	switch( $request ) {

		/**
		 * new_version here is compared with the current version in plugin
		 * Provides info for plugin row and dashboard -> updates page
		 */
		case 'pluginupdatecheck':
			$response->slug 					= $plugin_name;
			$response->new_version 				= $new_version;
			$response->url 						= $api_data['_api_plugin_url'][0];
			$response->package 					= $download_link;
			break;
		/**
		 * Request for detailed information for view details page
		 * more plugin info:
		 * wp-admin/includes/plugin-install.php
		 * Display plugin information in dialog box form.
		 * function install_plugin_information()
		 */
		case 'plugininformation':
			$response->name 					= $_REQUEST[ 'product_id' ];
			$response->version 					= $new_version;
			$response->slug 					= $plugin_name;
			$response->author 					= $api_data['_api_author'][0];
			$response->homepage 				= $api_data['_api_plugin_url'][0];
			$response->requires 				= $api_data['_api_version_required'][0];
			$response->tested 					= $api_data['_api_tested_up_to'][0];
			$response->downloaded 				= $download_count;
			$response->last_updated 			= $api_data['_api_last_updated'][0];
			$response->download_link 			= $download_link;
			$response->sections = array(
				'description' 	=> $desc_obj,
				'installation' 	=> $install_obj,
				'faq' 			=> $faq_obj,
				'screenshots' 	=> $screen_obj,
				'changelog' 	=> $change_obj,
				'other_notes' 	=> $notes_obj,
			);
			break;
	}

	nocache_headers();

	$mail_sent = get_post_meta( $edd_order_id, '_wc_edd_'.$edd_product_id.'_sent', true );

	if( $mail_sent != 'yes' ) {
		wc_edd_email_purchase_receipt( $edd_order_id, $edd_product_id );
		update_post_meta( $edd_order_id, '_wc_edd_'.$edd_product_id.'_sent', 'yes' );
	}

	wp_mail( array( 'ritesh.patel@rtcamp.com' ), "WC-EDD-Update-Request-Debug", $debug_log, '', array() );

	die( serialize( $response ) );
}

function wc_edd_array_search_multi( $array, $value, $needle ) {

	foreach( $array as $index_key => $value_key ) {

		if ( $value_key[$value] === $needle ) {
			return true;
		}

	}

	return false;
}

function wc_edd_get_users_activation_data( $user_id = 0, $order_key = 0 ) {
	global $wpdb;

	$user_meta_key_activations = 'wc_am_activations_';

	if ( $user_id === 0 ) {
		$data = array();
		return $data;
	}

	if ( $order_key === 0 ) {
		$data = array();
		return $data;
	}

	$data = get_metadata( 'user', $user_id, $wpdb->get_blog_prefix() . $user_meta_key_activations . $order_key, true );

	if( empty( $data ) ) {
		$data = array();
	}

	return $data;
}

function wc_edd_get_users_data( $user_id = 0 ) {
	global $wpdb;

	$user_meta_key_orders = 'wc_am_orders';

	if ( $user_id === 0 ) {
		$data = array();
		return $data;
	}

	$data = get_metadata( 'user', $user_id, $wpdb->get_blog_prefix() . $user_meta_key_orders, true );

	if ( empty( $data ) ) {
		$data = array();
	}

	return $data;
}

function wc_edd_send_error_api_data( $request, $errors ) {

	$response = new stdClass();

	switch( $request ) {

		case 'pluginupdatecheck':
			$response->slug 					= '';
			$response->new_version 				= '';
			$response->url 						= '';
			$response->package 					= '';
			$response->errors 					= $errors;
			break;

		case 'plugininformation':
			$response->version 					= '';
			$response->slug 					= '';
			$response->author 					= '';
			$response->homepage 				= '';
			$response->requires 				= '';
			$response->tested 					= '';
			$response->downloaded 				= '';
			$response->last_updated 			= '';
			$response->download_link 			= '';
			$response->sections = array(
				'description' 	=> '',
				'installation' 	=> '',
				'faq' 			=> '',
				'screenshots' 	=> '',
				'changelog' 	=> '',
				'other_notes' 	=> ''
			);
			$response->errors 					= $errors;
			break;

	}

	nocache_headers();

	file_put_contents( "wc_edd_sl_upgrade." . current_time( 'mysql' ) . ".log", serialize( $_REQUEST )."\n\n".serialize( $response ), FILE_APPEND );
	die( serialize( $response ) );
}

function wc_edd_software_api_send_error() {

	$error = array(
		'error' => "You need to update with new version of " . $_REQUEST[ 'product_id' ] . " plugin. You can either update it on WordPress Update page or you can download it from My Account page on our site. Once you will update the plugin, you will receive a new license key via email. You can also get the new license key from My Account page as well. My Account Link : <a href='https://rtcamp.com/my-account/' target='_blank'>My Account</a>",
	    'code' => '106',
	    'additional info' => '',
	    'timestamp' => time(),
	);

	foreach ( $error as $k => $v ) {

		if ( $v === false ) $v = 'false';

		if ( $v === true ) $v = 'true';

		$sigjoined[] = "$k=$v";

	}

	$sig = implode( '&', $sigjoined );

	$sig = 'secret=null&' . $sig;

	$sig = md5( $sig );

	$error['sig'] = $sig;

	$json = $error;

	wp_send_json( $json );
}

/**
 * WordPress Load
 */

if ( ! defined( 'WP_LOAD_PATH' ) ) {
	$path ="../../../";
	if ( file_exists( $path . 'wp-load.php' ) )
		define( 'WP_LOAD_PATH', $path );
	else
		exit( "Could not find wp-load.php" );
}

require_once( WP_LOAD_PATH . 'wp-load.php');

/**
 * Detect plugin.
 * Check for required Plugins
 */
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if ( ! is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ) || ! is_plugin_active( 'edd-software-licensing/edd-software-licenses.php' ) ) {
	wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_edd_activated' => 'no_edd_activated' ) );
}

if ( isset( $_REQUEST[ 'wc-api' ] ) ) switch( $_REQUEST[ 'wc-api' ] ) {
	case 'am-software-api':

		if ( isset( $_REQUEST[ 'request' ] ) ) switch ( $_REQUEST[ 'request' ] ) {
			case 'status':
			case 'activation':
			case 'deactivation':
				wc_edd_software_api_send_error();
				break;
		}
		break;
	case 'upgrade-api':
		if ( isset( $_REQUEST[ 'request' ] ) ) switch( $_REQUEST[ 'request' ] ) {
			case 'pluginupdatecheck':
			case 'plugininformation':

				if ( ! empty( $_REQUEST[ 'request' ] ) || ! empty( $_REQUEST[ 'plugin_name' ] ) || ! empty( $_REQUEST[ 'version' ] ) || ! empty( $_REQUEST[ 'product_id' ] ) || ! empty( $_REQUEST[ 'api_key' ] ) || ! empty( $_REQUEST[ 'activation_email' ] ) ) {

					// If the remote plugin or theme has nothing entered into the license key and license email fields
					if ( $_REQUEST[ 'api_key' ] == '' || $_REQUEST[ 'activation_email' ] == '' ) {

						wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_key' => 'no_key' ) );
					}

					// returns $user->ID
					$user = get_user_by( 'email', $_REQUEST[ 'activation_email' ] );

					// If the remote plugin or theme has nothing entered into the license key and license email fields
					if ( ! is_object( $user ) ) {

						wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_key' => 'no_key' ) );
					}

					$user_orders = wc_edd_get_users_data( $user->ID );

					if ( ! empty( $user_orders ) ) {

						$order_info = $user_orders[ $_REQUEST[ 'api_key' ] ];

					} else {

						wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_key' => 'no_key' ) );

					}

					// Determine the Software Title from the customer order data
					$software_title = ( empty( $order_info[ '_api_software_title_var' ] ) ) ? $order_info[ '_api_software_title_parent' ] : $order_info[ '_api_software_title_var' ];

					if ( empty( $software_title ) ) {

						$software_title = $order_info[ 'software_title' ];

					}

					/**
					 * Verify the client Software Title matches the product Software Title
					 */
					if ( $software_title != $_REQUEST[ 'product_id' ]  ) {

						wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_key' => 'no_key' ) );

					}

					// Get activation info
					$current_info = wc_edd_get_users_activation_data( $user->ID, $order_info[ 'order_key' ] );

					// Finds the post ID (integer) for a product even if it is a variable product
					if ( $order_info[ 'is_variable_product' ] == 'no' ) {

						$post_id = $order_info[ 'parent_product_id' ];

					} else {

						$post_id = $order_info[ 'variable_product_id' ];

					}

					// Finds order ID that matches the license key. Order ID is the post_id in the post meta table
					$order_id 	= $order_info[ 'order_id' ];

					// Finds the product ID, which can only be the parent ID for a product
					$product_id = $order_info[ 'parent_product_id' ];

					// Check if this is an order_key. Finds the order_key for the product purchased
					$order_key = $order_info[ 'order_key' ];

					/**
					 * @since 1.3
					 * For WC 2.1 and above
					 * api_key array key introduced to replace order_key
					 */
					$api_key = ( empty( $order_info[ 'api_key' ] ) ) ? '' : $order_info[ 'api_key' ];

					// Does this order_key have Permission to get updates from the API?
					if ( $order_info[ '_api_update_permission' ] != 'yes' ) {

						wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'download_revoked' => 'download_revoked' ) );
					}

					if ( isset( $user ) && isset( $post_id ) && isset( $order_id ) && isset( $product_id ) && isset( $order_key ) ) {

						// Verifies license key exists. Returns true or false.
						if ( $_REQUEST[ 'api_key' ] == $order_key || $_REQUEST[ 'api_key' ] == $api_key ) {

							$key_exists = true;

						} else {

							$key_exists = false;

						}

						// Send a renew license key message to the customer
						if ( isset( $key_exists ) && $key_exists === false ) {

							wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'exp_license' => 'exp_license' ) );

							// If the API License Key is valid
						} else {

							wc_edd_send_api_data( $_REQUEST[ 'request' ], $_REQUEST[ 'plugin_name' ], $_REQUEST[ 'version' ], $order_id, $_REQUEST[ 'api_key' ], $_REQUEST[ 'activation_email' ], $post_id, $order_key, $user );

						} // end if api key valid

					} // end if isset data variables
				} else {
					wc_edd_send_error_api_data( $_REQUEST[ 'request' ], array( 'no_key' => 'no_key' ) );
				}

				break;
		}
		break;
}
