<?php

defined( 'ABSPATH' ) || exit;

/**
 * Optional TranslatePress bridge. It has no effect when TranslatePress is not active.
 *
 * TranslatePress translates strings of one post into every language instead of keeping one post per language
 * like WPML does. So there is nothing to link, duplicate or create per language: this bridge only manages
 * the site languages and reports the URL each published language serves a post on.
 */
class CloseHub_TranslatePress {
	public static function active(): bool {
		return class_exists( 'TRP_Translate_Press' );
	}

	/** Why a WPML-style operation cannot run on TranslatePress. $action completes "it cannot ...". */
	public static function unsupported( string $action ): WP_Error {
		return new WP_Error( 'closehub_translatepress_unsupported', sprintf( 'TranslatePress translates one post into every language instead of keeping one post per language, so it cannot %s. Use WPML for per-language content.', $action ), [ 'status' => 400 ] );
	}

	// TranslatePress loads its settings once per request, for the site the request started on. These methods read the
	// trp_settings option of the current site instead, so they stay correct after CloseHub_Network switches site.

	/** @return array<string, mixed> */
	private static function settings(): array {
		$settings = get_option( 'trp_settings', [] );
		return is_array( $settings ) ? $settings : [];
	}

	public static function default_language(): string {
		$default = (string) ( self::settings()['default-language'] ?? '' );
		return '' !== $default ? $default : (string) get_locale();
	}

	/** @return string[] Languages that are being translated, the default one first. */
	private static function language_codes(): array {
		$codes   = array_map( 'strval', array_values( (array) ( self::settings()['translation-languages'] ?? [] ) ) );
		$default = self::default_language();
		return array_values( array_unique( array_merge( [ $default ], $codes ) ) );
	}

	/** @return string[] Languages visitors can see. The default language is always one of them. */
	private static function published_codes(): array {
		$settings  = self::settings();
		$published = array_map( 'strval', array_values( (array) ( $settings['publish-languages'] ?? [] ) ) );
		return array_values( array_unique( array_merge( [ self::default_language() ], $published ) ) );
	}

	private static function slug( string $code ): string {
		$slug = (string) ( ( (array) ( self::settings()['url-slugs'] ?? [] ) )[ $code ] ?? '' );
		return '' !== $slug ? $slug : $code;
	}

	private static function languages_component(): ?object {
		$trp       = TRP_Translate_Press::get_trp_instance();
		$component = is_object( $trp ) ? $trp->get_component( 'languages' ) : null;
		return is_object( $component ) ? $component : null;
	}

	/** @return array<string, string> Every language TranslatePress supports (code => name). */
	private static function catalog( string $name_type ): array {
		$component = self::languages_component();
		return $component && method_exists( $component, 'get_languages' ) ? array_map( 'strval', (array) $component->get_languages( $name_type ) ) : [];
	}

	/** Languages being translated (code, name, native name, default flag, whether visitors see it, URL slug) and, with $include_available, the supported ones not yet added. */
	public static function languages( bool $include_available = false ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$english   = self::catalog( 'english_name' );
		$native    = self::catalog( 'native_name' );
		$default   = self::default_language();
		$published = self::published_codes();
		$codes     = self::language_codes();
		$out       = [];
		foreach ( $codes as $code ) {
			$out[] = [
				'code'        => $code,
				'name'        => $english[ $code ] ?? $code,
				'native_name' => $native[ $code ] ?? ( $english[ $code ] ?? $code ),
				'locale'      => $code,
				'default'     => $code === $default,
				'published'   => in_array( $code, $published, true ),
				'url_slug'    => self::slug( $code ),
			];
		}
		$result = [ 'default_language' => $default, 'languages' => $out ];
		if ( $include_available ) {
			$available = [];
			foreach ( $english as $code => $name ) {
				if ( ! in_array( (string) $code, $codes, true ) ) {
					$available[] = [ 'code' => (string) $code, 'name' => $name, 'native_name' => $native[ $code ] ?? $name, 'locale' => (string) $code ];
				}
			}
			$result['available_languages'] = $available;
		}
		return $result;
	}

	/**
	 * Add a language to translate into. TranslatePress codes are WordPress locales ("fr_FR"), not WPML's "fr".
	 * The value is saved through TranslatePress' own settings sanitizer, which creates the language's translation
	 * tables and downloads its WordPress language pack, exactly as saving the TranslatePress settings page does.
	 */
	public static function add_language( string $code, bool $publish = true ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$code      = sanitize_text_field( $code );
		$supported = self::catalog( 'english_name' );
		if ( '' === $code || ! isset( $supported[ $code ] ) ) {
			return new WP_Error( 'closehub_invalid_language', 'The language code is not supported by TranslatePress. TranslatePress uses locale codes such as "fr_FR"; use list-languages with include_available to see the valid codes.', [ 'status' => 400 ] );
		}
		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			return new WP_Error( 'closehub_translatepress_other_site', 'TranslatePress has loaded the settings of another site of this network in this request, so changing languages here could overwrite this site\'s settings. Change this site\'s languages from its own TranslatePress settings.', [ 'status' => 409 ] );
		}
		$codes = self::language_codes();
		if ( in_array( $code, $codes, true ) ) {
			return [ 'language' => $code, 'added' => false ] + (array) self::languages();
		}

		$settings                          = self::settings();
		$settings['default-language']      = self::default_language();
		$settings['translation-languages'] = array_merge( $codes, [ $code ] );
		$settings['publish-languages']     = $publish ? array_merge( self::published_codes(), [ $code ] ) : self::published_codes();
		$slugs                             = (array) ( $settings['url-slugs'] ?? [] );
		if ( empty( $slugs[ $code ] ) ) {
			$component = self::languages_component();
			$isos      = $component && method_exists( $component, 'get_iso_codes' ) ? (array) $component->get_iso_codes( [ $code ], false ) : [];
			$iso       = (string) ( $isos[ $code ] ?? '' );
			$slug      = sanitize_title( strtolower( '' !== $iso ? $iso : $code ) );
			// Two languages can share an ISO code (fr_FR, fr_CA): TranslatePress would reset every slug to its full code.
			$slugs[ $code ] = in_array( $slug, $slugs, true ) ? sanitize_title( strtolower( $code ) ) : $slug;
		}
		$settings['url-slugs'] = $slugs;

		// The sanitizer is registered on admin_init, which API and MCP requests never reach.
		$component = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' );
		if ( is_object( $component ) && method_exists( $component, 'register_setting' ) && ! has_filter( 'sanitize_option_trp_settings' ) ) {
			$component->register_setting();
		}
		update_option( 'trp_settings', $settings );

		if ( ! in_array( $code, self::language_codes(), true ) ) {
			return new WP_Error( 'closehub_language_add_failed', 'TranslatePress did not keep the language. Its settings sanitizer rejected it or the license does not allow another language.', [ 'status' => 500 ] );
		}
		return [ 'language' => $code, 'added' => true, 'published' => in_array( $code, self::published_codes(), true ) ] + (array) self::languages();
	}

	/** TranslatePress keeps its translations per default-to-target language pair, so changing the default would orphan every translation. */
	public static function set_default_language( string $code ): array|WP_Error {
		if ( ! self::active() ) {
			return new WP_Error( 'closehub_translatepress_unavailable', 'TranslatePress must be active to manage languages.', [ 'status' => 400 ] );
		}
		$code = sanitize_text_field( $code );
		if ( '' === $code || ! in_array( $code, self::language_codes(), true ) ) {
			return new WP_Error( 'closehub_invalid_language', 'The language must be a language TranslatePress already translates. Add it first with add-language.', [ 'status' => 400 ] );
		}
		$default = self::default_language();
		if ( $default === $code ) {
			return [ 'language' => $code, 'previous_default' => $default, 'changed' => false ];
		}
		return new WP_Error( 'closehub_translatepress_default_unsupported', 'TranslatePress stores its translations per default and target language pair, so changing the default language would orphan every existing translation. Change it from the TranslatePress settings if you really need to.', [ 'status' => 400 ] );
	}

	/**
	 * The URL a published post has in each published language, as { language, language_urls }.
	 * "language" is the language the content is written in, the default one. Only published posts get URLs.
	 */
	public static function details( int $post_id ): array {
		if ( ! self::active() ) {
			return [];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}
		$details = [ 'language' => self::default_language() ];
		$urls    = 'publish' === $post->post_status ? self::language_urls( (string) get_permalink( $post ) ) : [];
		return $urls ? $details + [ 'language_urls' => $urls ] : $details;
	}

	/**
	 * TranslatePress serves a language under /{slug}/ after the home URL. The default language has no prefix
	 * unless "add subdirectory to default language" is on.
	 *
	 * @return array<string, string> language code => URL
	 */
	private static function language_urls( string $permalink ): array {
		$home = home_url( '/' );
		if ( '' === $permalink || 0 !== strpos( $permalink, $home ) ) {
			return [];
		}
		$rest      = substr( $permalink, strlen( $home ) );
		$default   = self::default_language();
		$prefix_it = 'yes' === ( self::settings()['add-subdirectory-to-default-language'] ?? 'no' );
		$urls      = [];
		foreach ( self::published_codes() as $code ) {
			$urls[ $code ] = $code === $default && ! $prefix_it ? $permalink : $home . self::slug( $code ) . '/' . $rest;
		}
		return $urls;
	}
}
