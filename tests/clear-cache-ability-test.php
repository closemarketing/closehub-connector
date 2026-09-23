<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

$GLOBALS['closehub_test_caps']       = [];
$GLOBALS['closehub_test_posts']      = [ 10, 11 ];
$GLOBALS['closehub_test_rocket_on']  = false;
$GLOBALS['closehub_test_rocket_log'] = [];

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_test_caps'], true ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_post( int $id ) { return in_array( $id, $GLOBALS['closehub_test_posts'], true ) ? (object) [ 'ID' => $id ] : null; }
function apply_filters( string $hook, $value ) {
	if ( 'closehub_clear_cache_providers' === $hook && ! $GLOBALS['closehub_test_rocket_on'] ) {
		// Simulate WP Rocket not being loaded by making its detector fail.
		$value['wp-rocket']['is_active'] = static fn(): bool => false;
	}
	return $value;
}

// WP Rocket's public API, stubbed to record calls.
function rocket_clean_domain( string $lang = '' ): bool { $GLOBALS['closehub_test_rocket_log'][] = 'domain'; return true; }
function rocket_clean_post( int $post_id ): bool { $GLOBALS['closehub_test_rocket_log'][] = "post:$post_id"; return true; }
function rocket_clean_minify( $extensions = [ 'js', 'css' ] ): void { $GLOBALS['closehub_test_rocket_log'][] = 'minify'; }

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

function closehub_fail( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); }

// ── Permissions: rocket_purge_cache or manage_options ─────────────────────────
$GLOBALS['closehub_test_caps'] = [ 'edit_posts' ];
if ( CloseHub_Site_Abilities::can_clear_cache() ) { closehub_fail( 'A user without rocket_purge_cache/manage_options should not clear the cache.' ); }
$GLOBALS['closehub_test_caps'] = [ 'rocket_purge_cache' ];
if ( ! CloseHub_Site_Abilities::can_clear_cache() ) { closehub_fail( 'rocket_purge_cache should allow clearing the cache.' ); }
$GLOBALS['closehub_test_caps'] = [ 'manage_options' ];
if ( ! CloseHub_Site_Abilities::can_clear_cache() ) { closehub_fail( 'manage_options should allow clearing the cache.' ); }

// ── No provider active: 503 ────────────────────────────────────────────────────
$no_provider = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'all' ] );
if ( ! $no_provider instanceof WP_Error || 'closehub_cache_no_provider' !== $no_provider->code || 503 !== $no_provider->data['status'] ) { closehub_fail( 'Missing cache plugin should return closehub_cache_no_provider (503).' ); }
if ( ! str_contains( $no_provider->message, 'WP Rocket' ) ) { closehub_fail( 'No-provider error should list WP Rocket as supported.' ); }
if ( $GLOBALS['closehub_test_rocket_log'] ) { closehub_fail( 'No WP Rocket function should run when the provider is inactive.' ); }

$GLOBALS['closehub_test_rocket_on'] = true;

// ── Input validation ───────────────────────────────────────────────────────────
$no_ids = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'posts' ] );
if ( ! $no_ids instanceof WP_Error || 400 !== $no_ids->data['status'] ) { closehub_fail( 'scope=posts without post_ids should return 400.' ); }
$bad_scope = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'everything' ] );
if ( ! $bad_scope instanceof WP_Error || 400 !== $bad_scope->data['status'] ) { closehub_fail( 'An unknown scope should return 400.' ); }
$all_invalid = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'posts', 'post_ids' => [ 999 ] ] );
if ( ! $all_invalid instanceof WP_Error || 404 !== $all_invalid->data['status'] || [ 999 ] !== $all_invalid->data['invalid_post_ids'] ) { closehub_fail( 'Only invalid post_ids should return 404 listing them.' ); }
if ( $GLOBALS['closehub_test_rocket_log'] ) { closehub_fail( 'Invalid input should not purge anything.' ); }

// ── scope=all clears the whole domain (default scope) ──────────────────────────
$all = CloseHub_Site_Abilities::clear_cache( [] );
if ( [ 'provider' => 'wp-rocket', 'scope' => 'all', 'cleared' => true, 'post_ids' => [] ] !== $all ) { closehub_fail( 'scope=all returned an unexpected result: ' . var_export( $all, true ) ); }
if ( [ 'domain' ] !== $GLOBALS['closehub_test_rocket_log'] ) { closehub_fail( 'scope=all should call rocket_clean_domain() only.' ); }

// ── scope=posts clears only the given posts and reports invalid ones ───────────
$GLOBALS['closehub_test_rocket_log'] = [];
$posts = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'posts', 'post_ids' => [ 10, 999, 11, 10 ] ] );
if ( is_wp_error( $posts ) || [ 10, 11 ] !== $posts['post_ids'] || [ 999 ] !== $posts['invalid_post_ids'] || true !== $posts['cleared'] ) { closehub_fail( 'scope=posts returned an unexpected result: ' . var_export( $posts, true ) ); }
if ( [ 'post:10', 'post:11' ] !== $GLOBALS['closehub_test_rocket_log'] ) { closehub_fail( 'scope=posts should call rocket_clean_post() once per valid post and never rocket_clean_domain().' ); }

// ── minify=true also clears minified CSS/JS ────────────────────────────────────
$GLOBALS['closehub_test_rocket_log'] = [];
$minify = CloseHub_Site_Abilities::clear_cache( [ 'scope' => 'all', 'minify' => true ] );
if ( is_wp_error( $minify ) || true !== $minify['minify_cleared'] ) { closehub_fail( 'minify=true should report minify_cleared.' ); }
if ( [ 'domain', 'minify' ] !== $GLOBALS['closehub_test_rocket_log'] ) { closehub_fail( 'minify=true should also call rocket_clean_minify().' ); }

echo "Clear cache ability checks passed.\n";
