<?php

defined( 'ABSPATH' ) || exit;

/** Manage the fixed Bearer token used by shared MCP connectors. */
final class CloseHub_MCP_Token {
	public const PREFIX = 'chm_';

	private const OPTION = 'closehub_mcp_shared_token';

	/**
	 * Generate a new token for a WordPress user and store only its hash.
	 *
	 * @return string|false The plaintext token, once, or false on failure.
	 */
	public static function generate( int $user_id ): string|false {
		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		$token = self::PREFIX . bin2hex( random_bytes( 32 ) );
		$data  = [
			'hash'       => hash( 'sha256', $token ),
			'user_id'    => $user_id,
			'created_at' => time(),
		];

		return self::persist( $data ) ? $token : false;
	}

	/** Return whether a usable shared token is configured. */
	public static function is_configured(): bool {
		$user_id = self::get_user_id();
		return $user_id > 0 && false !== get_user_by( 'id', $user_id );
	}

	/** Return the WordPress user associated with the shared token. */
	public static function get_user_id(): int {
		$data = self::stored();
		return is_array( $data ) && isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
	}

	/**
	 * Verify a candidate token and return its WordPress user ID.
	 *
	 * @return int|null
	 */
	public static function authenticate( string $candidate ): ?int {
		if ( 1 !== preg_match( '/^' . self::PREFIX . '[a-f0-9]{64}$/', $candidate ) ) {
			return null;
		}

		$data = self::stored();
		if ( ! is_array( $data ) || ! isset( $data['hash'], $data['user_id'] ) || ! is_string( $data['hash'] ) ) {
			return null;
		}

		$user_id = (int) $data['user_id'];
		if ( $user_id <= 0 || ! hash_equals( $data['hash'], hash( 'sha256', $candidate ) ) || ! get_user_by( 'id', $user_id ) ) {
			return null;
		}

		return $user_id;
	}

	/** Immediately revoke the configured shared token. */
	public static function revoke(): void {
		if ( is_multisite() ) {
			delete_site_option( self::OPTION );
			return;
		}

		delete_option( self::OPTION );
	}

	private static function stored(): mixed {
		return is_multisite() ? get_site_option( self::OPTION, [] ) : get_option( self::OPTION, [] );
	}

	private static function persist( array $data ): bool {
		return is_multisite() ? update_site_option( self::OPTION, $data ) : update_option( self::OPTION, $data, false );
	}
}
