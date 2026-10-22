<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID = 0, public string $post_type = 'post' ) {}
}

// Fake icl_translations: element_id => [ trid, language, source language ].
$GLOBALS['rows'] = [
	4732 => [ 5561, 'es', null ],
	5789 => [ 5901, 'en', null ],
	6000 => [ 5561, 'en', 'es' ],   // existing en translation of 4732
	7000 => [ 7100, 'es', null ],   // page (other post type)
	8000 => [ 8100, 'es', null ],   // original with its own fr translation
	8001 => [ 8100, 'fr', 'es' ],
	9000 => [ 9100, 'en', null ],
];
$GLOBALS['types'] = [ 7000 => 'page', 9999 => 'nolang' ];
$GLOBALS['actions'] = [];

class Fake_WPDB {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$args ): array { return [ $query, is_array( $args[0] ?? null ) ? $args[0] : $args ]; }
	public function get_results( array $q ): array {
		$out = [];
		foreach ( $GLOBALS['rows'] as $id => [ $trid, $lang, $src ] ) {
			$wanted = str_contains( $q[0], 'trid IN' ) ? array_slice( $q[1], 1 ) : [ $q[1][0] ];
			if ( in_array( $trid, $wanted, true ) ) {
				$out[] = (object) [ 'trid' => $trid, 'element_id' => $id, 'language_code' => $lang, 'source_language_code' => $src ];
			}
		}
		return $out;
	}
	public function get_var( string $q ): int { return max( array_column( $GLOBALS['rows'], 0 ) ) + 1; }
}
$wpdb = new Fake_WPDB();

function post_type_of( int $id ): string { return $GLOBALS['types'][ $id ] ?? 'post'; }
function has_filter( string $hook ): bool { return in_array( $hook, [ 'wpml_active_languages', 'wpml_element_language_details', 'wpml_is_translated_post_type' ], true ); }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	switch ( $hook ) {
		case 'wpml_active_languages': return [ 'en' => [], 'es' => [], 'fr' => [] ];
		case 'wpml_element_type': return 'post_' . $value;
		case 'wpml_is_translated_post_type': return 'nolang' !== $args[0];
		case 'wpml_element_language_details':
			$row = $GLOBALS['rows'][ (int) $args[0]['element_id'] ] ?? null;
			return $row ? (object) [ 'trid' => $row[0], 'language_code' => $row[1], 'source_language_code' => $row[2] ] : null;
	}
	return $value;
}
function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['actions'][] = $args[0];
	if ( 'wpml_set_element_language_details' === $hook ) {
		$GLOBALS['rows'][ $args[0]['element_id'] ] = [ $args[0]['trid'], $args[0]['language_code'], $args[0]['source_language_code'] ];
	}
}
function get_post( int $id ): ?WP_Post { return $id > 0 && ( isset( $GLOBALS['rows'][ $id ] ) || isset( $GLOBALS['types'][ $id ] ) ) ? new WP_Post( $id, post_type_of( $id ) ) : null; }
function get_post_status( int $id ): string { return 'publish'; }
function clean_post_cache( int $id ): void {}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require_once dirname( __DIR__ ) . '/includes/class-wpml.php';

function check( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
function code( mixed $result ): string { return $result instanceof WP_Error ? $result->code : ''; }

// Rejections change nothing.
$before = $GLOBALS['rows'];
check( 'closehub_translation_conflict' === code( CloseHub_WPML::link( 5789, 4732, 'en' ) ), 'Existing translation in that language must be rejected.' );
check( 'closehub_language_mismatch' === code( CloseHub_WPML::link( 5789, 4732, 'fr' ) ), 'Language mismatch must be rejected.' );
check( 'closehub_post_type_mismatch' === code( CloseHub_WPML::link( 7000, 4732, 'es' ) ), 'Post type mismatch must be rejected.' );
check( 'closehub_translation_source_not_original' === code( CloseHub_WPML::link( 9000, 6000, 'en' ) ), 'A translation cannot be used as the source.' );
check( 'closehub_post_type_not_translatable' === code( CloseHub_WPML::link( 9999, 4732, 'en' ) ) || 'closehub_post_type_mismatch' === code( CloseHub_WPML::link( 9999, 4732, 'en' ) ), 'Non-translatable or mismatched type must be rejected.' );
check( 'closehub_invalid_translation_source' === code( CloseHub_WPML::link( 5789, 4732, 'es' ) ), 'Same language as source must be rejected.' );
check( 'closehub_invalid_language' === code( CloseHub_WPML::link( 5789, 4732, '' ) ), 'language is required.' );
check( 'closehub_invalid_translation_link' === code( CloseHub_WPML::link( 4732, 4732, 'es' ) ), 'A post cannot translate itself.' );
check( 'closehub_translation_has_dependents' === code( CloseHub_WPML::link( 8000, 9000, 'es' ) ), 'Moving a source that has translations needs force.' );
check( 'closehub_post_not_found' === code( CloseHub_WPML::link( 123456, 4732, 'en' ) ), 'Missing post must be reported.' );
check( $before === $GLOBALS['rows'], 'Rejected links must not change WPML data.' );

// Real case: 5789 (en, own trid) becomes the en translation of 4732, replacing 6000 with force.
$result = CloseHub_WPML::link( 5789, 4732, 'en', true );
check( ! is_wp_error( $result ), 'Forced link should succeed.' );
check( [ 5561, 'en', 'es' ] === $GLOBALS['rows'][5789], 'Post must join the original trid with the original as source language.' );
check( 5561 === $result['translation_group'] && 5901 === $result['previous_translation_group'] && 6000 === $result['detached_post_id'] && true === $result['changed'], 'Link result must report groups and detached post.' );
check( null === $GLOBALS['rows'][6000][2] && 5561 !== $GLOBALS['rows'][6000][0], 'Conflicting translation must be detached into its own group.' );
check( [ 4732, 5789 ] === array_column( $result['translations'], 'post_id' ) || [ 5789, 4732 ] === array_column( $result['translations'], 'post_id' ), 'Group must contain exactly both posts.' );
check( in_array( 6000, $result['affected_post_ids'], true ) && in_array( 4732, $result['affected_post_ids'], true ), 'Affected posts must include the whole group for cache purge.' );

// Repeating is a no-op.
$again = CloseHub_WPML::link( 5789, 4732, 'en' );
check( ! is_wp_error( $again ) && false === $again['changed'], 'Linking twice must be a no-op.' );

// Unlink.
check( 'closehub_translation_is_source' === code( CloseHub_WPML::unlink( 4732 ) ), 'Original with translations cannot be unlinked.' );
check( 'closehub_translation_not_linked' === code( CloseHub_WPML::unlink( 6000 ) ), 'A standalone post cannot be unlinked.' );
$unlinked = CloseHub_WPML::unlink( 5789 );
check( ! is_wp_error( $unlinked ) && 5561 === $unlinked['previous_translation_group'] && 5561 !== $unlinked['translation_group'] && null === $GLOBALS['rows'][5789][2], 'Unlink must give the post its own group.' );
check( [ 4732 ] === array_column( CloseHub_WPML::translations( 4732 ), 'post_id' ), 'Original must be left alone in its group.' );

// Batch prefetch returns the same data as the per-post path.
CloseHub_WPML::prefetch( [ get_post( 8000 ) ] );
check( [ 8000, 8001 ] === array_column( CloseHub_WPML::translations( 8000 ), 'post_id' ), 'Prefetched group must list every language.' );

echo "WPML link translations checks passed.\n";
