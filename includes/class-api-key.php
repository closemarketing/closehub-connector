<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_API_Key {

	const OPTION      = 'closehub_api_key';
	const USER_OPTION = 'closehub_api_key_user';

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
	 * Verify an MCP Bearer token and return the WordPress user it acts as.
	 *
	 * @return int|null User ID, or null when the token is not the API key.
	 */
	public static function authenticate( string $candidate ): ?int {
		if ( ! self::verify( $candidate ) ) {
			return null;
		}
		$user_id = self::get_user_id();
		return $user_id > 0 ? $user_id : null;
	}

	/**
	 * User the API key acts as over MCP: whoever last generated it, or the
	 * first administrator (super admin on multisite) if that user is gone.
	 */
	public static function get_user_id(): int {
		$user_id = (int) ( is_multisite() ? get_site_option( self::USER_OPTION, 0 ) : get_option( self::USER_OPTION, 0 ) );
		if ( $user_id > 0 && get_user_by( 'id', $user_id ) ) {
			return $user_id;
		}

		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					return (int) $user->ID;
				}
			}
			return 0;
		}

		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
		return $admins ? (int) $admins[0] : 0;
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
		$user_id = get_current_user_id();
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $key );
			if ( $user_id > 0 ) {
				update_site_option( self::USER_OPTION, $user_id );
			}
		} else {
			update_option( self::OPTION, $key, false );
			if ( $user_id > 0 ) {
				update_option( self::USER_OPTION, $user_id, false );
			}
		}
	}

	private static function generate(): string {
		return 'chk_' . bin2hex( random_bytes( 24 ) );
	}
}
