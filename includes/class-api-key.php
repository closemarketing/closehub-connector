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
	 *
	 * Sites that ran <=1.0.1 on a subsite before multisite support was
	 * added may already have a key in that subsite's wp_options — and
	 * that subsite isn't necessarily the one handling the current
	 * request (e.g. network activation, or a request landing on a
	 * different subsite first). If we find a legacy key on ANY site in
	 * the network and no network-wide key exists yet, migrate it up
	 * instead of generating a new one — otherwise CloseHub keeps using
	 * the old key and every request starts failing with 401 after the
	 * upgrade.
	 */
	private static function stored(): string {
		if ( ! is_multisite() ) {
			return (string) get_option( self::OPTION, '' );
		}

		$network_key = (string) get_site_option( self::OPTION, '' );
		if ( $network_key ) {
			return $network_key;
		}

		$legacy_key = self::find_legacy_key_across_network();
		if ( $legacy_key ) {
			update_site_option( self::OPTION, $legacy_key );
			return $legacy_key;
		}

		return '';
	}

	/** Scans every site in the network for a pre-multisite per-site key. */
	private static function find_legacy_key_across_network(): string {
		$current_blog_id = get_current_blog_id();
		$legacy_key      = (string) get_blog_option( $current_blog_id, self::OPTION, '' );
		if ( $legacy_key ) {
			return $legacy_key;
		}

		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

		foreach ( $site_ids as $site_id ) {
			if ( (int) $site_id === $current_blog_id ) {
				continue;
			}

			$legacy_key = (string) get_blog_option( $site_id, self::OPTION, '' );
			if ( $legacy_key ) {
				return $legacy_key;
			}
		}

		return '';
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
