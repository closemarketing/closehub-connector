<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	delete_site_option( 'closehub_api_key' );
} else {
	delete_option( 'closehub_api_key' );
}

require_once __DIR__ . '/includes/class-oauth.php';
CloseHub_OAuth::uninstall();
