<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_API_Key {

	const OPTION = 'closehub_api_key';

	/** Generate a key if none exists yet. Called on activation. */
	public static function maybe_generate(): void {
		if ( ! self::stored() ) {
			self::persist( self::generate() );
		}
	}

	/** Regenerate and persist a new key. */
	public static function regenerate(): string {
		$key = self::generate();
		self::persist( $key );
		return $key;
	}

	/** Return the current key, or generate one on the fly if missing. */
	public static function get(): string {
		$key = self::stored();
		if ( ! $key ) {
			$key = self::regenerate();
		}
		return $key;
	}

	/** Constant-time comparison to prevent timing attacks. */
	public static function verify( string $candidate ): bool {
		return hash_equals( self::get(), $candidate );
	}

	/**
	 * On a multisite network the key lives in the network-wide options
	 * (wp_sitemeta), so every site in the network shares the exact same
	 * key. On a single-site install it lives in that site's wp_options,
	 * exactly as before.
	 */
	private static function stored(): string {
		return (string) ( is_multisite() ? get_site_option( self::OPTION, '' ) : get_option( self::OPTION, '' ) );
	}

	private static function persist( string $key ): void {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $key );
		} else {
			update_option( self::OPTION, $key, false );
		}
	}

	private static function generate(): string {
		return 'chk_' . bin2hex( random_bytes( 24 ) );
	}
}
