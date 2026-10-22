<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID = 0, public string $post_type = 'post', public string $post_title = 'T', public string $post_content = 'C', public string $post_excerpt = '', public int $post_author = 1 ) {}
}

// Fake icl_translations: element_id => [ trid, language, source language ].
$GLOBALS['rows']   = [ 100 => [ 1, 'es', null ], 101 => [ 1, 'en', 'es' ], 200 => [ 2, 'es', null ] ];
$GLOBALS['next_id'] = 300;
$GLOBALS['active']  = [ 'es', 'en' ];
$GLOBALS['default'] = 'es';
$GLOBALS['native']  = true;

class Fake_SitePress {
	public function get_languages( $a = false, $b = false, $c = false ): array {
		return [ 'es' => [ 'english_name' => 'Spanish' ], 'en' => [ 'english_name' => 'English' ], 'fr' => [ 'english_name' => 'French' ], 'de' => [ 'english_name' => 'German' ] ];
	}
	public function set_active_languages( array $codes ): bool { $GLOBALS['active'] = $codes; return true; }
	public function get_active_languages( bool $refresh = false ): array { return array_flip( $GLOBALS['active'] ); }
	public function set_default_language( string $code ): void { $GLOBALS['default'] = $code; }
	public function make_duplicate( int $id, string $lang ): int|WP_Error {
		if ( ! $GLOBALS['native'] ) { return 0; }
		if ( ! empty( $GLOBALS['native_error'] ) ) { return new WP_Error( 'wpml_boom', 'WPML exploded.' ); }
		$new = $GLOBALS['next_id']++;
		$GLOBALS['rows'][ $new ] = [ $GLOBALS['rows'][ $id ][0], ! empty( $GLOBALS['native_wrong_language'] ) ? 'de' : $lang, $GLOBALS['rows'][ $id ][1] ];
		$GLOBALS['status'][ $new ] = 'publish';
		return $new;
	}
}
$sitepress = new Fake_SitePress();

class Fake_WPDB {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$args ): array { return [ $query, is_array( $args[0] ?? null ) ? $args[0] : $args ]; }
	public function get_results( array $q ): array {
		$out = [];
		foreach ( $GLOBALS['rows'] as $id => [ $trid, $lang, $src ] ) {
			if ( $trid === $q[1][0] ) { $out[] = (object) [ 'trid' => $trid, 'element_id' => $id, 'language_code' => $lang, 'source_language_code' => $src ]; }
		}
		return $out;
	}
	public function get_var( string $q ): int { return max( array_column( $GLOBALS['rows'], 0 ) ) + 1; }
}
$wpdb = new Fake_WPDB();

function has_filter( string $hook ): bool { return true; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	switch ( $hook ) {
		case 'wpml_active_languages':
			$out = [];
			foreach ( $GLOBALS['active'] as $code ) { $out[ $code ] = [ 'translated_name' => strtoupper( $code ), 'native_name' => $code, 'default_locale' => $code . '_X' ]; }
			return $out;
		case 'wpml_default_language': return $GLOBALS['default'];
		case 'wpml_element_type': return 'post_' . $value;
		case 'wpml_is_translated_post_type': return true;
		case 'wpml_object_id': return (int) $value + 100;
		case 'wpml_element_language_details':
			$row = $GLOBALS['rows'][ (int) $args[0]['element_id'] ] ?? null;
			return $row ? (object) [ 'trid' => $row[0], 'language_code' => $row[1], 'source_language_code' => $row[2] ] : null;
	}
	return $value;
}
function do_action( string $hook, mixed ...$args ): void {
	if ( 'wpml_set_element_language_details' === $hook ) {
		$GLOBALS['rows'][ $args[0]['element_id'] ] = [ $args[0]['trid'] ?: ( max( array_column( $GLOBALS['rows'], 0 ) ) + 1 ), $args[0]['language_code'], $args[0]['source_language_code'] ];
	}
}
function get_post( int $id ): ?WP_Post { return isset( $GLOBALS['rows'][ $id ] ) || in_array( $id, $GLOBALS['copied'] ?? [], true ) ? new WP_Post( $id ) : null; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
function clean_post_cache( int $id ): void {}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_insert_post( array $args, bool $wp_error = false ): int { $id = $GLOBALS['next_id']++; $GLOBALS['copied'][] = $id; return $id; }
function get_post_meta( int $id ): array { return [ 'k' => [ 'v' ], '_icl_lang_duplicate_of' => [ '1' ] ]; }
function add_post_meta( int $id, string $k, mixed $v ): void { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function wp_slash( mixed $v ): mixed { return $v; }
function maybe_unserialize( mixed $v ): mixed { return $v; }
function wp_delete_post( int $id, bool $force ): void { $GLOBALS['deleted'][] = $id; }
function get_post_status( int $id ): string { return $GLOBALS['status'][ $id ] ?? 'draft'; }
function get_current_blog_id(): int { return 1; }
function wp_update_post( array $args ): int { $GLOBALS['status'][ $args['ID'] ] = $args['post_status']; return $args['ID']; }
function get_object_taxonomies( string $type ): array { return [ 'category' ]; }
function wp_get_object_terms( int $id, string $tax, array $args ): array { return [ 4, 5 ]; }
function wp_set_object_terms( int $id, array $terms, string $tax ): void { $GLOBALS['terms'][ $id ][ $tax ] = $terms; }

require_once dirname( __DIR__ ) . '/includes/class-wpml.php';

function check( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
function code( mixed $result ): string { return $result instanceof WP_Error ? $result->code : ''; }

// list-languages
$list = CloseHub_WPML::languages( true );
check( 'es' === $list['default_language'] && [ 'es', 'en' ] === array_column( $list['languages'], 'code' ) && true === $list['languages'][0]['default'], 'Active languages and default must be listed.' );
check( [ 'fr', 'de' ] === array_column( $list['available_languages'], 'code' ), 'Available languages exclude the active ones.' );
check( ! isset( CloseHub_WPML::languages()['available_languages'] ), 'Available languages only on request.' );

// add-language
check( 'closehub_invalid_language' === code( CloseHub_WPML::add_language( 'xx' ) ), 'Unsupported language must be rejected.' );
$added = CloseHub_WPML::add_language( 'fr' );
check( true === $added['added'] && in_array( 'fr', $GLOBALS['active'], true ), 'Language must be activated.' );
check( false === CloseHub_WPML::add_language( 'fr' )['added'], 'Adding an active language is a no-op.' );

// set-default-language
check( 'closehub_invalid_language' === code( CloseHub_WPML::set_default_language( 'de' ) ), 'An inactive language cannot become the default.' );
$def = CloseHub_WPML::set_default_language( 'en' );
check( true === $def['changed'] && 'es' === $def['previous_default'] && 'en' === $GLOBALS['default'], 'Default language must change.' );
check( false === CloseHub_WPML::set_default_language( 'en' )['changed'], 'Setting the same default is a no-op.' );

// duplicate (native, linked)
check( 'closehub_translation_conflict' === code( CloseHub_WPML::duplicate( 100, 'en' ) ), 'An existing translation must be rejected.' );
check( 'closehub_translation_source_not_original' === code( CloseHub_WPML::duplicate( 101, 'fr' ) ), 'A translation cannot be duplicated.' );
check( 'closehub_invalid_language' === code( CloseHub_WPML::duplicate( 100, 'de' ) ), 'An inactive target language must be rejected.' );
$dup = CloseHub_WPML::duplicate( 100, 'fr' );
check( ! is_wp_error( $dup ) && 'fr' === $dup['language'] && 1 === $dup['translation_group'] && true === $dup['linked'], 'Native duplicate must be linked in the target language.' );

check( 'draft' === ( $GLOBALS['status'][ $dup['post_id'] ] ?? '' ), 'A native duplicate of a published post must be forced back to draft.' );

// WPML errors from the native duplication are returned, not cast to post ID 1
$GLOBALS['native_error'] = true;
check( 'wpml_boom' === code( CloseHub_WPML::duplicate( 200, 'fr' ) ), 'A WP_Error from make_duplicate must be propagated.' );
$GLOBALS['native_error'] = false;

// A copy that fails verification is removed, not left behind
$GLOBALS['native_wrong_language'] = true;
$GLOBALS['deleted']               = [];
check( 'closehub_duplicate_failed' === code( CloseHub_WPML::duplicate( 200, 'fr' ) ) && 1 === count( $GLOBALS['deleted'] ), 'A failed duplicate must be deleted.' );
$GLOBALS['native_wrong_language'] = false;

// duplicate (unlinked copy, own group, WPML duplicate flag not copied)
$copy = CloseHub_WPML::duplicate( 200, 'en', false );
check( ! is_wp_error( $copy ) && 'en' === $copy['language'] && 2 !== $copy['translation_group'] && false === $copy['linked'], 'link=false must create an independent copy in its own group.' );
check( isset( $GLOBALS['meta'][ $copy['post_id'] ]['k'] ) && ! isset( $GLOBALS['meta'][ $copy['post_id'] ]['_icl_lang_duplicate_of'] ), 'Copy keeps meta but not WPML internals.' );

// duplicate (fallback copy when WPML has no make_duplicate result, linked)
$GLOBALS['native'] = false;
$fallback = CloseHub_WPML::duplicate( 200, 'fr' );
check( ! is_wp_error( $fallback ) && 2 === $fallback['translation_group'] && 'fr' === $fallback['language'], 'Fallback copy must be linked to the original.' );
check( [ 104, 105 ] === ( $GLOBALS['terms'][ $fallback['post_id'] ]['category'] ?? [] ), 'Fallback copy must carry the (translated) taxonomy terms.' );

// WPML inactive
unset( $GLOBALS['sitepress'] );
$sitepress = null;
check( 'closehub_wpml_unavailable' === code( CloseHub_WPML::languages() ), 'Language management needs WPML.' );

echo "WPML language management checks passed.\n";
