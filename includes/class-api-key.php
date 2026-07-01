<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_API_Key {

	const OPTION         = 'closehub_api_key';
	const NETWORK_OPTION = 'closehub_network_api_key';

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

	/** Generate a network-wide key if none exists yet. Called on network activation. */
	public static function maybe_generate_network(): void {
		if ( ! get_site_option( self::NETWORK_OPTION ) ) {
			update_site_option( self::NETWORK_OPTION, self::generate() );
		}
	}

	/** Regenerate and persist a new network-wide key. */
	public static function regenerate_network(): string {
		$key = self::generate();
		update_site_option( self::NETWORK_OPTION, $key );
		return $key;
	}

	/** Return the current network-wide key, or generate one on the fly if missing. */
	public static function get_network(): string {
		$key = get_site_option( self::NETWORK_OPTION, '' );
		if ( ! $key ) {
			$key = self::regenerate_network();
		}
		return $key;
	}

	/** Constant-time comparison to prevent timing attacks. */
	public static function verify_network( string $candidate ): bool {
		return hash_equals( self::get_network(), $candidate );
	}

	private static function generate(): string {
		return 'chk_' . bin2hex( random_bytes( 24 ) );
	}
}
