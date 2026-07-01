<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'closehub_api_key' );

if ( is_multisite() ) {
	delete_site_option( 'closehub_network_api_key' );

	foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
		delete_blog_option( (int) $site->blog_id, 'closehub_api_key' );
	}
}
