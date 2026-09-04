<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	delete_site_option( 'closehub_api_key' );
} else {
	delete_option( 'closehub_api_key' );
}

require_once __DIR__ . '/includes/class-oauth.php';

if ( is_multisite() ) {
	// Each site's OAuth tables live under its own $wpdb->prefix — this
	// uninstall entry point only runs in one blog's context, so every other
	// site's clients/codes/tokens would otherwise survive plugin deletion.
	$original_blog_id = get_current_blog_id();
	foreach ( get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		CloseHub_OAuth::uninstall();
	}
	switch_to_blog( $original_blog_id );
} else {
	CloseHub_OAuth::uninstall();
}
