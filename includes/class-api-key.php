<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_API_Key {

	const OPTION = 'closehub_api_key';

	/** Generate a key if none exists yet. Called on activation. */
	public static function maybe_generate(): void {
		if ( ! get_option( self::OPTION ) ) {
			update_option( self::OPTION, self::generate(), false );
		}
	}

	/** Regenerate and persist a new key. */
	public static function regenerate(): string {
		$key = self::generate();
		update_option( self::OPTION, $key, false );
		return $key;
	}

	/** Return the current key, or generate one on the fly if missing. */
	public static function get(): string {
		$key = get_option( self::OPTION, '' );
		if ( ! $key ) {
			$key = self::regenerate();
		}
		return $key;
	}

	/** Constant-time comparison to prevent timing attacks. */
	public static function verify( string $candidate ): bool {
		return hash_equals( self::get(), $candidate );
	}

	private static function generate(): string {
		return 'chk_' . bin2hex( random_bytes( 24 ) );
	}
}
