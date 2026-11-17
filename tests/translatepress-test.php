<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_Post {
	public function __construct( public int $ID = 0, public string $post_status = 'publish', public string $post_type = 'post' ) {}
}

$GLOBALS['options']  = [];
$GLOBALS['posts']    = [ 10 => new WP_Post( 10 ), 11 => new WP_Post( 11, 'draft' ), 12 => new WP_Post( 12 ) ];
$GLOBALS['permalinks'] = [ 10 => 'https://example.test/about/', 11 => 'https://example.test/?p=11', 12 => 'https://example.test/' ];
$GLOBALS['switched'] = false;
$GLOBALS['registered'] = 0;
$GLOBALS['updates']  = 0;
$GLOBALS['sanitized_limit'] = 99; // How many languages the (fake) sanitizer keeps.

class Fake_TRP_Languages {
	public function get_languages( string $type = 'english_name' ): array {
		return 'native_name' === $type
			? [ 'en_US' => 'English', 'es_ES' => 'Español', 'fr_FR' => 'Français', 'fr_CA' => 'Français du Canada' ]
			: [ 'en_US' => 'English (United States)', 'es_ES' => 'Spanish', 'fr_FR' => 'French', 'fr_CA' => 'French (Canada)' ];
	}
	public function get_iso_codes( array $codes, bool $map = true ): array {
		$iso = [ 'en_US' => 'en', 'es_ES' => 'es', 'fr_FR' => 'fr', 'fr_CA' => 'fr' ];
		return array_intersect_key( $iso, array_flip( $codes ) );
	}
}
class Fake_TRP_Settings {
	public function register_setting(): void { $GLOBALS['registered']++; $GLOBALS['has_sanitizer'] = true; }
}
class TRP_Translate_Press {
	public static function get_trp_instance(): self { return new self(); }
	public function get_component( string $name ): object { return 'languages' === $name ? new Fake_TRP_Languages() : new Fake_TRP_Settings(); }
}

function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value ): bool {
	$GLOBALS['updates']++;
	if ( 'trp_settings' === $name ) {
		// What TranslatePress' sanitizer does that matters here: keep the default first and drop what the license does not allow.
		$value['translation-languages'] = array_slice( array_values( array_unique( $value['translation-languages'] ) ), 0, $GLOBALS['sanitized_limit'] );
	}
	$GLOBALS['options'][ $name ] = $value;
	return true;
}
function has_filter( string $hook ): bool { return 'sanitize_option_trp_settings' === $hook ? ! empty( $GLOBALS['has_sanitizer'] ) : ! empty( $GLOBALS['wpml'] ); }
function get_locale(): string { return 'en_US'; }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function sanitize_title( string $value ): string { return strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $value ) ); }
function ms_is_switched(): bool { return $GLOBALS['switched']; }
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function get_post( int $id ): ?WP_Post { return $GLOBALS['posts'][ $id ] ?? null; }
function get_permalink( WP_Post $post ): string { return $GLOBALS['permalinks'][ $post->ID ]; }

require_once dirname( __DIR__ ) . '/includes/class-translatepress.php';
require_once dirname( __DIR__ ) . '/includes/class-wpml.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}
function code( mixed $value ): string { return $value instanceof WP_Error ? $value->code : ''; }

$GLOBALS['options']['trp_settings'] = [
	'default-language'      => 'en_US',
	'translation-languages' => [ 'en_US', 'es_ES' ],
	'publish-languages'     => [ 'en_US', 'es_ES' ],
	'url-slugs'             => [ 'en_US' => 'en', 'es_ES' => 'es' ],
	'add-subdirectory-to-default-language' => 'no',
];

// Languages.
$list = CloseHub_TranslatePress::languages();
check( 'en_US' === $list['default_language'], 'The default language comes from trp_settings.' );
check( [ 'en_US', 'es_ES' ] === array_column( $list['languages'], 'code' ), 'Languages are the ones being translated, default first.' );
check( true === $list['languages'][0]['default'] && false === $list['languages'][1]['default'], 'Only the default language is flagged.' );
check( 'Spanish' === $list['languages'][1]['name'] && 'Español' === $list['languages'][1]['native_name'], 'Names come from the TranslatePress catalogue.' );
check( 'es' === $list['languages'][1]['url_slug'] && true === $list['languages'][1]['published'], 'The URL slug and published flag are reported.' );
check( ! isset( $list['available_languages'] ), 'Available languages are opt-in.' );
$available = CloseHub_TranslatePress::languages( true )['available_languages'];
check( [ 'fr_FR', 'fr_CA' ] === array_column( $available, 'code' ), 'Available languages exclude the ones already added.' );

$GLOBALS['options']['trp_settings']['publish-languages'] = [ 'en_US' ];
check( false === CloseHub_TranslatePress::languages()['languages'][1]['published'], 'An unpublished language is reported as such.' );
$GLOBALS['options']['trp_settings']['publish-languages'] = [ 'en_US', 'es_ES' ];

// Adding a language.
check( 'closehub_invalid_language' === code( CloseHub_TranslatePress::add_language( 'fr' ) ), 'WPML-style short codes are not TranslatePress codes.' );
check( 'closehub_invalid_language' === code( CloseHub_TranslatePress::add_language( '' ) ), 'A language is required.' );
check( 0 === $GLOBALS['updates'], 'Invalid languages never write.' );
$same = CloseHub_TranslatePress::add_language( 'es_ES' );
check( false === $same['added'] && 0 === $GLOBALS['updates'], 'Adding an existing language is a no-op.' );

$GLOBALS['switched'] = true;
check( 'closehub_translatepress_other_site' === code( CloseHub_TranslatePress::add_language( 'fr_FR' ) ), 'Writes are refused on a switched site.' );
check( 0 === $GLOBALS['updates'], 'A refused write changes nothing.' );
check( 'en_US' === CloseHub_TranslatePress::languages()['default_language'], 'Reads still work on a switched site.' );
$GLOBALS['switched'] = false;

$added = CloseHub_TranslatePress::add_language( 'fr_FR' );
check( true === $added['added'] && true === $added['published'], 'The language is added and published by default.' );
check( 1 === $GLOBALS['registered'], 'The sanitizer is registered so TranslatePress creates the language tables.' );
$saved = $GLOBALS['options']['trp_settings'];
check( [ 'en_US', 'es_ES', 'fr_FR' ] === $saved['translation-languages'] && [ 'en_US', 'es_ES', 'fr_FR' ] === $saved['publish-languages'], 'The language is added to both lists.' );
check( 'fr' === $saved['url-slugs']['fr_FR'] && 'en' === $saved['url-slugs']['en_US'], 'The slug is the ISO code and other slugs are kept.' );
check( 'fr_FR' === $added['languages'][2]['code'], 'The response lists the new language.' );
CloseHub_TranslatePress::add_language( 'fr_CA', false );
$saved = $GLOBALS['options']['trp_settings'];
check( 'fr_ca' === $saved['url-slugs']['fr_CA'], 'A slug already used by another language falls back to the full code.' );
check( ! in_array( 'fr_CA', $saved['publish-languages'], true ), 'publish=false adds the language without publishing it.' );
check( 1 === $GLOBALS['registered'], 'The sanitizer is registered once.' );

// TranslatePress' sanitizer can drop a language (invalid code, license limit).
$GLOBALS['sanitized_limit'] = 2;
$GLOBALS['options']['trp_settings']['translation-languages'] = [ 'en_US', 'es_ES' ];
check( 'closehub_language_add_failed' === code( CloseHub_TranslatePress::add_language( 'fr_FR' ) ), 'A language TranslatePress did not keep is reported as a failure.' );
$GLOBALS['sanitized_limit'] = 99;

// Default language.
check( 'closehub_invalid_language' === code( CloseHub_TranslatePress::set_default_language( 'de_DE' ) ), 'The default must already be translated.' );
check( false === CloseHub_TranslatePress::set_default_language( 'en_US' )['changed'], 'Setting the current default is a no-op.' );
$updates = $GLOBALS['updates'];
check( 'closehub_translatepress_default_unsupported' === code( CloseHub_TranslatePress::set_default_language( 'es_ES' ) ), 'Changing the default would orphan translations and is refused.' );
check( $updates === $GLOBALS['updates'], 'A refused default change writes nothing.' );

// Post URLs.
$post = CloseHub_TranslatePress::details( 10 );
check( 'en_US' === $post['language'], 'The content language is the default language.' );
check( [ 'en_US' => 'https://example.test/about/', 'es_ES' => 'https://example.test/es/about/', 'fr_FR' => 'https://example.test/fr/about/' ] === $post['language_urls'], 'Every published language has a URL; the default has no prefix; unpublished languages are left out.' );
check( [ 'en_US' => 'https://example.test/', 'es_ES' => 'https://example.test/es/', 'fr_FR' => 'https://example.test/fr/' ] === CloseHub_TranslatePress::details( 12 )['language_urls'], 'The front page gets a bare language path.' );
check( [ 'language' => 'en_US' ] === CloseHub_TranslatePress::details( 11 ), 'A draft has no public URLs.' );
check( [] === CloseHub_TranslatePress::details( 999 ), 'An unknown post has no details.' );
$GLOBALS['options']['trp_settings']['add-subdirectory-to-default-language'] = 'yes';
check( 'https://example.test/en/about/' === CloseHub_TranslatePress::details( 10 )['language_urls']['en_US'], 'The default language is prefixed when TranslatePress is set to do so.' );
$GLOBALS['permalinks'][10] = 'https://other.test/about/';
check( [ 'language' => 'en_US' ] === CloseHub_TranslatePress::details( 10 ), 'A permalink outside the home URL gets no language URLs.' );

// WPML-only operations explain why they do not apply.
$link = CloseHub_WPML::link( 10, 12, 'es_ES' );
check( 'closehub_translatepress_unsupported' === code( $link ), 'Linking translations is explained as unsupported.' );
check( str_contains( $link->message, 'link translations' ), 'The message names the operation.' );
check( 'closehub_translatepress_unsupported' === code( CloseHub_WPML::validate( 'es_ES' ) ), 'Setting a content language is unsupported.' );
check( 'closehub_translatepress_unsupported' === code( CloseHub_WPML::unlink( 10 ) ), 'Unlinking is unsupported.' );
check( 'closehub_translatepress_unsupported' === code( CloseHub_WPML::duplicate( 10, 'es_ES' ) ), 'Duplicating into a language is unsupported.' );
check( '' === CloseHub_WPML::validate( '' ), 'No language requested is still fine.' );

echo "TranslatePress bridge checks passed.\n";
