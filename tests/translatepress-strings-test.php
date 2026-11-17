<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT_K', 'OBJECT_K' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID = 0, public string $post_status = 'publish' ) {}
}

// Fake TranslatePress tables: originals (id => text), post_parent_id meta, and one dictionary per language.
$GLOBALS['originals']  = [ 1 => 'Menu item', 2 => 'Post title', 3 => 'First part with', 4 => 'bold', 5 => 'Other post text' ];
$GLOBALS['meta']       = [ 2 => 10, 3 => 10, 4 => 10, 5 => 11 ];
$GLOBALS['dictionary'] = [
	'fr_FR' => [ 1 => [ 'id' => 101, 'original_id' => 1, 'original' => 'Menu item', 'translated' => 'Élément', 'status' => 2, 'block_type' => 0 ], 2 => [ 'id' => 102, 'original_id' => 2, 'original' => 'Post title', 'translated' => '', 'status' => 0, 'block_type' => 0 ], 3 => [ 'id' => 103, 'original_id' => 3, 'original' => 'First part with', 'translated' => 'Première partie avec', 'status' => 1, 'block_type' => 0 ] ],
	'de_DE' => [],
];
$GLOBALS['updates']  = [];
$GLOBALS['inserted'] = [];
$GLOBALS['switched'] = false;
$GLOBALS['actions']  = [];
$GLOBALS['next_row'] = 500;

class Fake_TRP_Query {
	public function get_table_name_for_original_strings(): string { return 'wp_trp_original_strings'; }
	public function get_table_name_for_original_meta(): string { return 'wp_trp_original_meta'; }
	public function get_string_rows( $ids, $originals, $language, $output = OBJECT_K, $by_original_id = false ): array {
		$out = [];
		foreach ( $GLOBALS['dictionary'][ $language ] ?? [] as $row ) {
			if ( ( $by_original_id && in_array( $row['original_id'], $ids, true ) ) || in_array( $row['original'], $originals, true ) ) {
				$out[ $by_original_id ? $row['original_id'] : $row['id'] ] = (object) $row;
			}
		}
		return $out;
	}
	public function insert_strings( array $strings, string $language ): void {
		foreach ( $strings as $string ) {
			$original_id = array_search( $string, $GLOBALS['originals'], true );
			if ( false === $original_id ) {
				$original_id                             = max( array_keys( $GLOBALS['originals'] ) ) + 1;
				$GLOBALS['originals'][ $original_id ] = $string;
			}
			$GLOBALS['dictionary'][ $language ][ $original_id ] = [ 'id' => $GLOBALS['next_row']++, 'original_id' => $original_id, 'original' => $string, 'translated' => '', 'status' => 0, 'block_type' => 0 ];
			$GLOBALS['inserted'][] = [ $language, $string ];
		}
	}
	public function update_strings( array $rows, string $language, array $columns ): void {
		$GLOBALS['updates'][] = [ $language, $rows, $columns ];
		foreach ( $rows as $row ) {
			foreach ( $GLOBALS['dictionary'][ $language ] as $original_id => $existing ) {
				if ( $existing['id'] === $row['id'] ) {
					$GLOBALS['dictionary'][ $language ][ $original_id ]['translated'] = $row['translated'];
					$GLOBALS['dictionary'][ $language ][ $original_id ]['status']     = $row['status'];
				}
			}
		}
	}
	public function remove_possible_duplicates( array $rows, string $language, string $type ): void {}
}
class TRP_Translate_Press {
	public static function get_trp_instance(): self { return new self(); }
	public function get_component( string $name ): object { return new Fake_TRP_Query(); }
}

class Fake_WPDB {
	public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	public function prepare( string $query, mixed ...$args ): array { return [ $query, is_array( $args[0] ?? null ) ? $args[0] : $args ]; }
	public function get_results( array $q ): array {
		[ $sql, $params ] = $q;
		$out = [];
		if ( str_contains( $sql, 'WHERE id IN' ) ) {
			foreach ( $params as $id ) {
				if ( isset( $GLOBALS['originals'][ $id ] ) ) { $out[] = (object) [ 'id' => $id, 'original' => $GLOBALS['originals'][ $id ] ]; }
			}
			return $out;
		}
		$post   = str_contains( $sql, 'post_parent_id' ) ? (int) array_shift( $params ) : 0;
		$search = str_contains( $sql, 'LIKE' ) ? trim( (string) array_shift( $params ), '%' ) : '';
		foreach ( $GLOBALS['originals'] as $id => $text ) {
			if ( ( ! $post || ( $GLOBALS['meta'][ $id ] ?? 0 ) === $post ) && ( '' === $search || str_contains( $text, $search ) ) ) {
				$out[] = (object) [ 'id' => $id, 'original' => $text ];
			}
		}
		return $out;
	}
}
$wpdb = new Fake_WPDB();

function get_option( string $name, mixed $default = false ): mixed {
	return 'trp_settings' === $name ? [ 'default-language' => 'es_ES', 'translation-languages' => [ 'es_ES', 'fr_FR', 'de_DE' ], 'publish-languages' => [ 'es_ES', 'fr_FR', 'de_DE' ], 'url-slugs' => [ 'es_ES' => 'es', 'fr_FR' => 'fr', 'de_DE' => 'de' ] ] : $default;
}
function get_locale(): string { return 'es_ES'; }
function get_post( int $id ): ?WP_Post { return in_array( $id, [ 10, 11 ], true ) ? new WP_Post( $id ) : null; }
function absint( mixed $v ): int { return abs( (int) $v ); }
function is_wp_error( mixed $v ): bool { return $v instanceof WP_Error; }
function ms_is_switched(): bool { return $GLOBALS['switched']; }
function trp_sanitize_string( string $s ): string { return strip_tags( $s, '<strong><em><a><br>' ); }
function do_action( string $hook, mixed ...$args ): void { $GLOBALS['actions'][] = $hook; }
function apply_filters( string $hook, mixed $value ): mixed { return $value; }
function current_user_can( string $cap ): bool { return 'manage_options' === $cap && ! empty( $GLOBALS['admin'] ); }

require_once dirname( __DIR__ ) . '/includes/class-translatepress.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}
function code( mixed $value ): string { return $value instanceof WP_Error ? $value->code : ''; }

// Permission follows TranslatePress' translating capability.
check( false === CloseHub_TranslatePress::can_translate(), 'Translating needs manage_options by default.' );
$GLOBALS['admin'] = true;
check( true === CloseHub_TranslatePress::can_translate(), 'Administrators can translate.' );

// Listing.
check( 'closehub_strings_scope_required' === code( CloseHub_TranslatePress::strings( [] ) ), 'A post or a search is required.' );
check( 'closehub_post_not_found' === code( CloseHub_TranslatePress::strings( [ 'post_id' => 99 ] ) ), 'Unknown posts are rejected.' );
check( 'closehub_invalid_language' === code( CloseHub_TranslatePress::strings( [ 'post_id' => 10, 'languages' => [ 'es_ES' ] ] ) ), 'The default language cannot be listed for translation.' );

$all = CloseHub_TranslatePress::strings( [ 'post_id' => 10 ] );
check( [ 'fr_FR', 'de_DE' ] === $all['languages'], 'Every translation language is listed by default.' );
check( [ 2, 3, 4 ] === array_column( $all['strings'], 'original_id' ), 'Only the strings of the post are listed.' );
check( 'Première partie avec' === $all['strings'][1]['translations']['fr_FR']['translated'] && 'machine_translated' === $all['strings'][1]['translations']['fr_FR']['status'], 'Translations and their status are reported.' );
check( 'not_translated' === $all['strings'][2]['translations']['de_DE']['status'], 'A string without a row is not translated.' );

$fr = CloseHub_TranslatePress::strings( [ 'post_id' => 10, 'languages' => [ 'fr_FR' ], 'only_missing' => true ] );
check( [ 2, 4 ] === array_column( $fr['strings'], 'original_id' ), 'only_missing hides strings translated in every requested language.' );

$search = CloseHub_TranslatePress::strings( [ 'search' => 'Menu', 'only_missing' => false ] );
check( [ 1 ] === array_column( $search['strings'], 'original_id' ), 'search finds site-wide strings without a post.' );

$paged = CloseHub_TranslatePress::strings( [ 'post_id' => 10, 'per_page' => 2, 'page' => 2 ] );
check( 3 === $paged['total'] && 2 === $paged['total_pages'] && [ 4 ] === array_column( $paged['strings'], 'original_id' ), 'Results are paginated.' );

$empty = CloseHub_TranslatePress::strings( [ 'post_id' => 11, 'search' => 'nothing' ] );
check( 0 === $empty['total'] && isset( $empty['notice'] ), 'A post without registered strings explains how to render it.' );

// Saving.
check( 'closehub_invalid_language' === code( CloseHub_TranslatePress::save_strings( 'es_ES', [ [ 'original_id' => 2, 'translated' => 'x' ] ] ) ), 'The default language cannot be saved.' );
check( 'closehub_translations_required' === code( CloseHub_TranslatePress::save_strings( 'fr_FR', [] ) ), 'Translations are required.' );
check( 'closehub_too_many_translations' === code( CloseHub_TranslatePress::save_strings( 'fr_FR', array_fill( 0, 501, [ 'original_id' => 2, 'translated' => 'x' ] ) ) ), 'At most 500 translations per call.' );

$saved = CloseHub_TranslatePress::save_strings( 'fr_FR', [
	[ 'original_id' => 2, 'translated' => 'Titre <script>x</script>' ],
	[ 'original' => 'bold', 'translated' => 'gras' ],
	[ 'original_id' => 404, 'translated' => 'perdu' ],
	[ 'original_id' => 3, 'translated' => '   ' ],
] );
check( 2 === $saved['saved'] && 1 === $saved['created'], 'Existing rows are updated and a missing row is created first.' );
check( [ [ 'index' => 2, 'original_id' => 404 ] ] === $saved['not_found'], 'Unknown original IDs are reported.' );
check( [ 3 ] === $saved['skipped_empty'], 'Empty translations are skipped, never saved as blank.' );
check( 'Première partie avec' === $GLOBALS['dictionary']['fr_FR'][3]['translated'], 'A skipped string keeps its translation.' );
check( 'Titre x' === $GLOBALS['dictionary']['fr_FR'][2]['translated'] && 2 === $GLOBALS['dictionary']['fr_FR'][2]['status'], 'Translations are sanitized and saved as human reviewed.' );
check( 'gras' === $GLOBALS['dictionary']['fr_FR'][4]['translated'], 'A string can be named by its original text.' );
check( [ 'id', 'translated', 'status', 'block_type' ] === $GLOBALS['updates'][0][2], 'Only the translation columns are written.' );
check( in_array( 'trp_save_editor_translations_regular_strings', $GLOBALS['actions'], true ), 'TranslatePress add-ons are notified like after an editor save.' );

$none = CloseHub_TranslatePress::save_strings( 'de_DE', [ [ 'original_id' => 404, 'translated' => 'x' ] ] );
check( 0 === $none['saved'] && 1 === count( $GLOBALS['updates'] ), 'Nothing is written when no string matches.' );

$GLOBALS['switched'] = true;
check( 'closehub_translatepress_other_site' === code( CloseHub_TranslatePress::strings( [ 'post_id' => 10 ] ) ), 'Reads are refused on a switched site.' );
check( 'closehub_translatepress_other_site' === code( CloseHub_TranslatePress::save_strings( 'fr_FR', [ [ 'original_id' => 2, 'translated' => 'x' ] ] ) ), 'Writes are refused on a switched site.' );

echo "TranslatePress string translation checks passed.\n";
