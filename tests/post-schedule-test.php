<?php
/**
 * Scheduling posts through create-post / update-post (date, date_gmt, status=future).
 *
 * Standalone like the rest of the suite: run with `php tests/post-schedule-test.php`.
 * wp_insert_post()/wp_update_post() are stubbed in memory and mimic the two core
 * behaviours that matter here: dates on drafts are ignored without edit_date, and
 * status=publish with a future post_date_gmt becomes status=future.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_Post {
	public int $ID = 0;
	public string $post_type = 'post';
	public string $post_status = 'draft';
	public string $post_date = '0000-00-00 00:00:00';
	public string $post_date_gmt = '0000-00-00 00:00:00';
}
class WP_REST_Request {
	private array $params = [];
	public function __construct( array|string $params = [], string $route = '' ) { $this->params = is_array( $params ) ? $params : []; }
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
	public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
}
$GLOBALS['posts']  = [];
$GLOBALS['tz']     = 'Europe/Madrid';
$GLOBALS['caps']   = [ 'edit_post' => true, 'publish_posts' => true, 'create_posts' => true, 'edit_posts' => true ];
$GLOBALS['now']    = strtotime( '2026-11-21 10:00:00 UTC' );

function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
function has_filter( string $hook ): bool { return false; }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return $value; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_status( int $id ): string|false { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_status : false; }
function get_post_type( int $id ): string|false { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : false; }
function get_permalink( int $id ): string { return 'https://example.test/?p=' . $id; }
function wp_get_post_parent_id( int $id ): int { return 0; }
function get_post_thumbnail_id( int $id ): int { return 0; }
function is_post_type_hierarchical( string $type ): bool { return false; }
function is_object_in_taxonomy( string $type, string $taxonomy ): bool { return true; }
function get_post_type_object( string $type ): object { return (object) [ 'show_ui' => true, 'public' => true ]; }
function current_user_can( string $cap, int $id = 0 ): bool { return $GLOBALS['caps'][ $cap ] ?? false; }
function get_current_user_id(): int { return 1; }
function wp_delete_post( int $id, bool $force = false ): bool { unset( $GLOBALS['posts'][ $id ] ); return true; }
function get_date_from_gmt( string $date, string $format = 'Y-m-d H:i:s' ): string {
	return ( new DateTime( $date, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $GLOBALS['tz'] ) )->format( $format );
}
function get_gmt_from_date( string $date, string $format = 'Y-m-d H:i:s' ): string {
	return ( new DateTime( $date, new DateTimeZone( $GLOBALS['tz'] ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
}

/** Core-like save: ignores the date on drafts without edit_date, turns publish+future date into future. */
function closehub_test_save( array $args, ?WP_Post $post = null ): int {
	$post ??= new WP_Post();
	$post->ID = $post->ID ?: count( $GLOBALS['posts'] ) + 100;
	foreach ( [ 'post_status', 'post_type' ] as $key ) {
		if ( isset( $args[ $key ] ) ) { $post->$key = $args[ $key ]; }
	}
	if ( ! empty( $args['edit_date'] ) && isset( $args['post_date_gmt'] ) ) {
		$post->post_date     = $args['post_date'];
		$post->post_date_gmt = $args['post_date_gmt'];
	}
	if ( 'publish' === $post->post_status && '0000-00-00 00:00:00' !== $post->post_date_gmt && $post->post_date_gmt > gmdate( 'Y-m-d H:i:s', $GLOBALS['now'] ) ) {
		$post->post_status = 'future';
	}
	$GLOBALS['posts'][ $post->ID ] = $post;
	return $post->ID;
}
function wp_insert_post( array $args, bool $wp_error = false ): int { return closehub_test_save( $args ); }
function wp_update_post( array $args, bool $wp_error = false ): int { return closehub_test_save( $args, $GLOBALS['posts'][ $args['ID'] ] ); }

require_once dirname( __DIR__ ) . '/includes/class-wpml.php';
require_once dirname( __DIR__ ) . '/includes/class-translatepress.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-api.php';
require_once dirname( __DIR__ ) . '/includes/class-network.php';
require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$api    = new CloseHub_REST_API();
$create = new ReflectionMethod( $api, 'create_post_data' );
$update = new ReflectionMethod( $api, 'update_post_data' );
$sched  = new ReflectionMethod( $api, 'schedule_fields' );
$create->setAccessible( true );
$update->setAccessible( true );
$sched->setAccessible( true );

// schedule_fields() reads the clock; keep the fixed "now" in sync by using far-future dates only.
$make   = static fn( array $params ) => new WP_REST_Request( $params + [ 'title' => 'T', 'content' => 'C' ] );
$fail   = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };
$expect = static function ( mixed $result, string $code, string $label ) use ( $fail ): void {
	if ( ! $result instanceof WP_Error || $code !== $result->code ) { $fail( "Expected {$code} for {$label}." ); }
};

// 1. A draft scheduled with a future local date: status future, local and GMT dates stored.
$out = $create->invoke( $api, $make( [ 'status' => 'future', 'date' => '2030-07-01T09:00:00' ] ) );
if ( 'future' !== $out['status'] || '2030-07-01 09:00:00' !== $out['date'] || '2030-07-01 07:00:00' !== $out['date_gmt'] ) { $fail( 'create-post must schedule with correct local and GMT dates: ' . json_encode( $out ) ); }
if ( 'future' !== $GLOBALS['posts'][ $out['id'] ]->post_status ) { $fail( 'The stored post must be future.' ); }

// 2. status=future without a date, or with a past date, is an error.
$expect( $create->invoke( $api, $make( [ 'status' => 'future' ] ) ), 'closehub_schedule_date_required', 'future without date' );
$expect( $create->invoke( $api, $make( [ 'status' => 'future', 'date' => '2020-01-01T09:00:00' ] ) ), 'closehub_schedule_date_not_future', 'future with a past date' );
$expect( $create->invoke( $api, $make( [ 'status' => 'future', 'date_gmt' => '2020-01-01T09:00:00' ] ) ), 'closehub_schedule_date_not_future', 'future with a past date_gmt' );
foreach ( [ '2030-02-30T09:00:00', '2030-07-01 09:00:00', '2030-07-01', 'tomorrow', '2030-13-01T09:00:00' ] as $bad ) {
	$expect( $create->invoke( $api, $make( [ 'status' => 'future', 'date' => $bad ] ) ), 'closehub_invalid_date', "invalid date {$bad}" );
}

// 3. publish with a future date is left to WordPress, which makes it future; the response says so.
$out = $create->invoke( $api, $make( [ 'status' => 'publish', 'date' => '2030-07-01T09:00:00' ] ) );
if ( 'future' !== $out['status'] || empty( $out['notice'] ) ) { $fail( 'publish with a future date must end as future with a notice.' ); }

// date_gmt wins over date.
$out = $create->invoke( $api, $make( [ 'status' => 'future', 'date' => '2000-01-01T00:00:00', 'date_gmt' => '2030-01-15T08:00:00' ] ) );
if ( '2030-01-15 09:00:00' !== $out['date'] || '2030-01-15 08:00:00' !== $out['date_gmt'] ) { $fail( 'date_gmt must win over date: ' . json_encode( $out ) ); }

// 4. Unschedule: status=draft on a future post returns it to draft and keeps the date.
$id  = $out['id'];
$out = $update->invoke( $api, $make( [ 'id' => $id, 'status' => 'draft' ] ) );
if ( 'draft' !== $out['status'] || '2030-01-15 08:00:00' !== $out['date_gmt'] ) { $fail( 'Unscheduling must return to draft and keep the date: ' . json_encode( $out ) ); }
// Reschedule an existing draft.
$out = $update->invoke( $api, $make( [ 'id' => $id, 'status' => 'future', 'date' => '2030-03-31T12:00:00' ] ) );
if ( 'future' !== $out['status'] || '2030-03-31 10:00:00' !== $out['date_gmt'] ) { $fail( 'A draft must be schedulable via update-post: ' . json_encode( $out ) ); }

// 6. Europe/Madrid conversion around daylight saving time (CET +1, CEST +2).
foreach ( [
	'2030-01-15T09:00:00' => '2030-01-15 08:00:00', // winter
	'2030-07-01T09:00:00' => '2030-07-01 07:00:00', // summer
	'2030-03-31T01:59:59' => '2030-03-31 00:59:59', // last second before the spring-forward
	'2030-03-31T03:00:00' => '2030-03-31 01:00:00', // first second after it
	'2030-10-27T01:59:59' => '2030-10-26 23:59:59', // still CEST
	'2030-10-27T03:00:00' => '2030-10-27 02:00:00', // back on CET
] as $local => $gmt ) {
	$fields = $sched->invoke( $api, $make( [ 'date' => $local ] ), 'future' );
	if ( $gmt !== ( $fields['post_date_gmt'] ?? null ) ) { $fail( "Madrid {$local} must be {$gmt} UTC, got " . json_encode( $fields ) ); }
	$back = $sched->invoke( $api, $make( [ 'date_gmt' => str_replace( ' ', 'T', $gmt ) ] ), 'future' );
	if ( str_replace( 'T', ' ', $local ) !== ( $back['post_date'] ?? null ) ) { $fail( "UTC {$gmt} must be Madrid {$local}, got " . json_encode( $back ) ); }
}
$GLOBALS['tz'] = 'UTC';
$fields = $sched->invoke( $api, $make( [ 'date' => '2030-07-01T09:00:00' ] ), 'future' );
if ( '2030-07-01 09:00:00' !== $fields['post_date_gmt'] ) { $fail( 'A UTC site must not shift the date.' ); }
$GLOBALS['tz'] = 'Europe/Madrid';

// Backward compatibility: no date means no schedule fields and the same response keys plus status/date.
if ( [] !== $sched->invoke( $api, $make( [ 'status' => 'publish' ] ), 'publish' ) ) { $fail( 'Calls without date must not touch dates.' ); }
$out = $create->invoke( $api, $make( [ 'status' => 'draft' ] ) );
if ( 'draft' !== $out['status'] || null !== $out['date_gmt'] ) { $fail( 'A plain draft keeps date_gmt empty.' ); }

// 5. Permissions: scheduling needs publish_posts, like publishing.
$GLOBALS['caps']['publish_posts'] = false;
if ( CloseHub_Content_Abilities::can_create_post( [ 'status' => 'future', 'date' => '2030-07-01T09:00:00' ] ) ) { $fail( 'create-post with status=future needs publish_posts.' ); }
if ( CloseHub_Content_Abilities::can_create_post( [ 'status' => 'publish' ] ) ) { $fail( 'create-post with status=publish needs publish_posts.' ); }
if ( ! CloseHub_Content_Abilities::can_create_post( [ 'status' => 'draft', 'date' => '2030-07-01T09:00:00' ] ) ) { $fail( 'A draft with a date does not need publish_posts.' ); }
$GLOBALS['posts'][1] = new WP_Post();
$GLOBALS['posts'][1]->ID = 1;
if ( CloseHub_Content_Abilities::can_edit_post( [ 'post_id' => 1, 'status' => 'future', 'date' => '2030-07-01T09:00:00' ] ) ) { $fail( 'update-post with status=future needs publish_posts.' ); }
$GLOBALS['posts'][2] = new WP_Post();
$GLOBALS['posts'][2]->ID = 2;
$GLOBALS['posts'][2]->post_status = 'future';
if ( CloseHub_Content_Abilities::can_edit_post( [ 'post_id' => 2, 'date' => '2031-01-01T09:00:00' ] ) ) { $fail( 'Rescheduling a scheduled post needs publish_posts.' ); }
if ( ! CloseHub_Content_Abilities::can_edit_post( [ 'post_id' => 1, 'title' => 'x' ] ) ) { $fail( 'A plain edit must stay allowed.' ); }
$GLOBALS['caps']['publish_posts'] = true;

// 7. The MCP abilities pass status=future and date through, and re-check permission.
$out = CloseHub_Content_Abilities::create_post( [ 'title' => 'T', 'content' => 'C', 'status' => 'future', 'date' => '2030-07-01T09:00:00' ] );
if ( is_wp_error( $out ) || 'future' !== $out['status'] || '2030-07-01 07:00:00' !== $out['date_gmt'] ) { $fail( 'closehub/create-post must schedule: ' . json_encode( $out ) ); }
$out = CloseHub_Content_Abilities::update_post( [ 'post_id' => $out['id'], 'status' => 'draft' ] );
if ( is_wp_error( $out ) || 'draft' !== $out['status'] ) { $fail( 'closehub/update-post must unschedule: ' . json_encode( $out ) ); }
$GLOBALS['caps']['publish_posts'] = false;
$expect( CloseHub_Content_Abilities::update_post( [ 'post_id' => $out['id'], 'status' => 'future', 'date' => '2030-07-01T09:00:00' ] ), 'closehub_forbidden', 'update-post scheduling without publish_posts' );
$GLOBALS['caps']['publish_posts'] = true;

echo "Post scheduling checks passed.\n";
