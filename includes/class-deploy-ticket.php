<?php

defined( 'ABSPATH' ) || exit;

/**
 * Single-use upload tickets for the /packages/install route.
 *
 * A user who can install plugins or themes asks for a ticket through the
 * closehub/create-deploy-ticket ability (over the MCP connection they already
 * use). The ticket is bound to that user, one type and one slug, expires after
 * TTL seconds and is burned on first use, so no long-lived credential such as
 * an application password ever has to exist for deploys. Only its SHA-256
 * hash is stored.
 */
class CloseHub_Deploy_Ticket {
	public const HEADER = 'X-CloseHub-Deploy-Ticket';
	public const TTL    = 900;

	private const PREFIX = 'closehub_deploy_ticket_';

	/** @return array{ticket: string, expires_at: string, expires_in: int} */
	public static function issue( int $user_id, string $type, string $slug ): array {
		$ticket = bin2hex( random_bytes( 32 ) );
		$expiry = time() + self::TTL;
		set_transient( self::key( $ticket ), [ 'user_id' => $user_id, 'type' => $type, 'slug' => $slug, 'expires' => $expiry ], self::TTL );
		return [ 'ticket' => $ticket, 'expires_at' => gmdate( 'c', $expiry ), 'expires_in' => self::TTL ];
	}

	/**
	 * Burn the ticket and return the user it was issued to. A ticket sent for
	 * another type or slug is burned too, so it cannot be retried.
	 */
	public static function redeem( string $ticket, string $type, string $slug ): int|WP_Error {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $ticket ) ) {
			return new WP_Error( 'closehub_deploy_ticket_missing', sprintf( 'Send a deploy ticket from the closehub/create-deploy-ticket ability in the %s header.', self::HEADER ), [ 'status' => 401 ] );
		}
		$key  = self::key( $ticket );
		$data = get_transient( $key );
		// Only the request that actually deletes it may use it, so two parallel uploads cannot share one ticket.
		if ( ! is_array( $data ) || ! delete_transient( $key ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
			return new WP_Error( 'closehub_deploy_ticket_invalid', 'The deploy ticket is invalid, expired or already used. Create a new one.', [ 'status' => 401 ] );
		}
		if ( $type !== $data['type'] || $slug !== $data['slug'] ) {
			return new WP_Error( 'closehub_deploy_ticket_mismatch', sprintf( 'The deploy ticket was issued for %1$s "%2$s", not %3$s "%4$s".', $data['type'], $data['slug'], $type, $slug ), [ 'status' => 403 ] );
		}
		return (int) $data['user_id'];
	}

	private static function key( string $ticket ): string {
		return self::PREFIX . hash( 'sha256', $ticket );
	}
}
