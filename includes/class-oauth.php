<?php

defined( 'ABSPATH' ) || exit;

/** OAuth 2.1 server for the CloseHub MCP endpoint. */
class CloseHub_OAuth {
	private const NS = 'closehub-oauth/v1';
	private const SCOPE = 'mcp:tools';

	public static function init(): void {
		add_action( 'init', [ self::class, 'well_known' ], 1 );
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_filter( 'rest_authentication_errors', [ self::class, 'authenticate' ], 5 );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table( 'clients' ) . " (client_id varchar(191) NOT NULL, client_name varchar(191) NOT NULL, redirect_uris longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (client_id)) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'codes' ) . " (code_hash char(64) NOT NULL, client_id varchar(80) NOT NULL, user_id bigint(20) unsigned NOT NULL, redirect_uri text NOT NULL, challenge varchar(128) NOT NULL, expires_at datetime NOT NULL, used tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (code_hash), KEY expires_at (expires_at)) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::table( 'tokens' ) . " (access_hash char(64) NOT NULL, refresh_hash char(64) NOT NULL, client_id varchar(80) NOT NULL, user_id bigint(20) unsigned NOT NULL, expires_at datetime NOT NULL, refresh_expires_at datetime NOT NULL, revoked tinyint(1) NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY (access_hash), UNIQUE KEY refresh_hash (refresh_hash), KEY user_id (user_id)) {$charset};" );
	}

	public static function uninstall(): void {
		global $wpdb;
		foreach ( [ 'tokens', 'codes', 'clients' ] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public static function routes(): void {
		$public = [ 'permission_callback' => '__return_true' ];
		register_rest_route( self::NS, '/resource-metadata', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ self::class, 'resource_metadata' ] ] + $public );
		register_rest_route( self::NS, '/server-metadata', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ self::class, 'server_metadata' ] ] + $public );
		register_rest_route( self::NS, '/register', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ self::class, 'register_client' ] ] + $public );
		register_rest_route( self::NS, '/authorize', [ [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ self::class, 'authorize_get' ] ] + $public, [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ self::class, 'authorize_post' ] ] + $public ] );
		register_rest_route( self::NS, '/token', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ self::class, 'token' ] ] + $public );
		register_rest_route( self::NS, '/revoke', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ self::class, 'revoke' ] ] + $public );
	}

	public static function resource_metadata(): WP_REST_Response { return new WP_REST_Response( self::resource_data() ); }
	public static function server_metadata(): WP_REST_Response { return new WP_REST_Response( self::server_data() ); }

	public static function well_known(): void {
		$uri = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
		$base = (string) ( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '' );
		if ( '' !== $base && str_starts_with( $uri, $base ) ) { $uri = substr( $uri, strlen( $base ) ); }
		if ( '/.well-known/oauth-protected-resource' === $uri ) { wp_send_json( self::resource_data() ); }
		if ( '/.well-known/oauth-authorization-server' === $uri ) { wp_send_json( self::server_data() ); }
	}

	public static function register_client( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) { return self::error( 'invalid_client_metadata', 'Client metadata must be JSON.' ); }
		$metadata_client_id = esc_url_raw( (string) ( $data['client_id'] ?? '' ) );
		if ( '' !== $metadata_client_id ) {
			if ( ! str_starts_with( $metadata_client_id, 'https://' ) ) { return self::error( 'invalid_client_metadata', 'client_id metadata must use HTTPS.' ); }
			$response = wp_safe_remote_get( $metadata_client_id, [ 'timeout' => 10, 'redirection' => 0, 'headers' => [ 'Accept' => 'application/json' ] ] );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return self::error( 'invalid_client_metadata', 'Could not retrieve the Client ID metadata document.' ); }
			$metadata = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $metadata ) || ! isset( $metadata['client_id'] ) || ! hash_equals( $metadata_client_id, (string) $metadata['client_id'] ) ) { return self::error( 'invalid_client_metadata', 'The Client ID metadata document is invalid.' ); }
			$data = array_merge( $metadata, $data );
		}
		$name = sanitize_text_field( (string) ( $data['client_name'] ?? '' ) ); $uris = $data['redirect_uris'] ?? [];
		if ( '' === $name || ! is_array( $uris ) || [] === $uris || count( $uris ) > 20 ) { return self::error( 'invalid_client_metadata', 'client_name and redirect_uris are required.' ); }
		$uris = array_values( array_unique( array_map( 'esc_url_raw', $uris ) ) );
		foreach ( $uris as $uri ) { if ( ! self::valid_redirect_uri( $uri ) ) { return self::error( 'invalid_redirect_uri', 'Redirect URIs must use HTTPS or localhost HTTP.' ); } }
		$id = '' !== $metadata_client_id ? $metadata_client_id : 'chc_' . bin2hex( random_bytes( 24 ) ); global $wpdb;
		if ( false === $wpdb->insert( self::table( 'clients' ), [ 'client_id' => $id, 'client_name' => $name, 'redirect_uris' => wp_json_encode( $uris ), 'created_at' => current_time( 'mysql', true ) ], [ '%s', '%s', '%s', '%s' ] ) ) { return self::error( 'server_error', 'Could not register the client.', 500 ); }
		return new WP_REST_Response( [ 'client_id' => $id, 'client_name' => $name, 'redirect_uris' => $uris, 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ], 'token_endpoint_auth_method' => 'none' ], 201 );
	}

	public static function authorize_get( WP_REST_Request $request ) {
		$params = self::params( $request ); $client = self::valid_authorize( $params ); if ( is_wp_error( $client ) ) { return $client; }
		self::restore_user();
		if ( ! is_user_logged_in() ) { wp_safe_redirect( wp_login_url( rest_url( self::NS . '/authorize' ) . '?' . http_build_query( $params ) ) ); exit; }
		self::consent_page( $client, $params ); exit;
	}

	public static function authorize_post( WP_REST_Request $request ) {
		$params = self::params( $request ); $client = self::valid_authorize( $params ); if ( is_wp_error( $client ) ) { return $client; }
		self::restore_user();
		if ( ! is_user_logged_in() || ! wp_verify_nonce( (string) $request->get_param( 'closehub_oauth_nonce' ), 'closehub_oauth_authorize' ) ) { return self::error( 'invalid_request', 'Authorization could not be verified.', 403 ); }
		if ( 'approve' !== $request->get_param( 'decision' ) ) { self::redirect( $params['redirect_uri'], [ 'error' => 'access_denied', 'state' => $params['state'] ] ); }
		$code = bin2hex( random_bytes( 32 ) ); global $wpdb;
		if ( false === $wpdb->insert( self::table( 'codes' ), [ 'code_hash' => self::hash( $code ), 'client_id' => $params['client_id'], 'user_id' => get_current_user_id(), 'redirect_uri' => $params['redirect_uri'], 'challenge' => $params['challenge'], 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'used' => 0 ], [ '%s', '%s', '%d', '%s', '%s', '%s', '%d' ] ) ) { return self::error( 'server_error', 'Could not issue an authorization code.', 500 ); }
		self::redirect( $params['redirect_uri'], [ 'code' => $code, 'state' => $params['state'] ] );
	}

	public static function token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return 'authorization_code' === $request->get_param( 'grant_type' ) ? self::exchange_code( $request ) : ( 'refresh_token' === $request->get_param( 'grant_type' ) ? self::exchange_refresh( $request ) : self::error( 'unsupported_grant_type', 'Unsupported grant type.' ) );
	}

	public static function revoke( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' ); if ( '' !== $token ) { global $wpdb; $hash = self::hash( $token ); $wpdb->update( self::table( 'tokens' ), [ 'revoked' => 1 ], [ 'access_hash' => $hash ], [ '%d' ], [ '%s' ] ); $wpdb->update( self::table( 'tokens' ), [ 'revoked' => 1 ], [ 'refresh_hash' => $hash ], [ '%d' ], [ '%s' ] ); }
		return new WP_REST_Response( null, 200 );
	}

	public static function authenticate( $result ) {
		if ( null !== $result || ! self::mcp_request() ) { return $result; }
		$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( '' === $header && is_user_logged_in() ) { return $result; }
		if ( ! str_starts_with( $header, 'Bearer ' ) ) { header( 'WWW-Authenticate: Bearer resource_metadata="' . esc_url( rest_url( self::NS . '/resource-metadata' ) ) . '"' ); return self::error( 'mcp_authentication_required', 'OAuth authentication is required.', 401 ); }
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'tokens' ) . ' WHERE access_hash = %s AND revoked = 0 AND expires_at > %s', self::hash( substr( $header, 7 ) ), gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) { return self::error( 'invalid_token', 'The access token is invalid, expired, or revoked.', 401 ); }
		wp_set_current_user( (int) $row['user_id'] ); return true;
	}

	public static function verify_pkce( string $verifier, string $challenge ): bool {
		return strlen( $verifier ) >= 43 && strlen( $verifier ) <= 128 && hash_equals( $challenge, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
	}

	public static function valid_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri ); $scheme = $parts['scheme'] ?? ''; $host = $parts['host'] ?? '';
		return 'https' === $scheme || ( 'http' === $scheme && in_array( $host, [ 'localhost', '127.0.0.1', '[::1]' ], true ) );
	}

	private static function exchange_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code = (string) $request->get_param( 'code' ); $client = sanitize_text_field( (string) $request->get_param( 'client_id' ) ); $uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) ); $verifier = (string) $request->get_param( 'code_verifier' );
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'codes' ) . ' WHERE code_hash = %s AND used = 0 AND expires_at > %s', self::hash( $code ), gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || ! hash_equals( $row['client_id'], $client ) || ! hash_equals( $row['redirect_uri'], $uri ) || ! self::verify_pkce( $verifier, $row['challenge'] ) ) { return self::error( 'invalid_grant', 'The authorization grant is invalid, expired, or revoked.' ); }
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'codes' ) . ' SET used = 1 WHERE code_hash = %s AND used = 0', self::hash( $code ) ) ) ) { return self::error( 'invalid_grant', 'The authorization grant is invalid, expired, or revoked.' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return self::issue_tokens( $client, (int) $row['user_id'] );
	}

	private static function exchange_refresh( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refresh = (string) $request->get_param( 'refresh_token' ); $client = sanitize_text_field( (string) $request->get_param( 'client_id' ) ); global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'tokens' ) . ' WHERE refresh_hash = %s AND revoked = 0 AND refresh_expires_at > %s', self::hash( $refresh ), gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || ! hash_equals( $row['client_id'], $client ) || 1 !== $wpdb->update( self::table( 'tokens' ), [ 'revoked' => 1 ], [ 'refresh_hash' => self::hash( $refresh ), 'revoked' => 0 ], [ '%d' ], [ '%s', '%d' ] ) ) { return self::error( 'invalid_grant', 'The refresh token is invalid, expired, or revoked.' ); }
		return self::issue_tokens( $client, (int) $row['user_id'] );
	}

	private static function issue_tokens( string $client, int $user_id ): WP_REST_Response|WP_Error {
		$access = bin2hex( random_bytes( 32 ) ); $refresh = bin2hex( random_bytes( 32 ) ); global $wpdb;
		if ( false === $wpdb->insert( self::table( 'tokens' ), [ 'access_hash' => self::hash( $access ), 'refresh_hash' => self::hash( $refresh ), 'client_id' => $client, 'user_id' => $user_id, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'revoked' => 0, 'created_at' => current_time( 'mysql', true ) ], [ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ] ) ) { return self::error( 'server_error', 'Could not issue tokens.', 500 ); }
		return new WP_REST_Response( [ 'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => HOUR_IN_SECONDS, 'refresh_token' => $refresh, 'scope' => self::SCOPE ] );
	}

	private static function params( WP_REST_Request $r ): array { return [ 'response_type' => sanitize_text_field( (string) $r->get_param( 'response_type' ) ), 'client_id' => sanitize_text_field( (string) $r->get_param( 'client_id' ) ), 'redirect_uri' => esc_url_raw( (string) $r->get_param( 'redirect_uri' ) ), 'state' => sanitize_text_field( (string) $r->get_param( 'state' ) ), 'challenge' => sanitize_text_field( (string) $r->get_param( 'code_challenge' ) ), 'method' => sanitize_text_field( (string) $r->get_param( 'code_challenge_method' ) ) ]; }
	private static function valid_authorize( array $p ): array|WP_Error { $c = self::client( $p['client_id'] ); if ( 'code' !== $p['response_type'] || ! $c || ! in_array( $p['redirect_uri'], $c['redirect_uris'], true ) || 'S256' !== $p['method'] || '' === $p['challenge'] ) { return self::error( 'invalid_request', 'Invalid OAuth authorization request.' ); } return $c; }
	private static function client( string $id ): ?array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'clients' ) . ' WHERE client_id = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) { return null; } $row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: []; return $row; }
	private static function resource_data(): array { return [ 'resource' => rest_url( 'mcp/mcp-adapter-default-server' ), 'authorization_servers' => [ home_url() ], 'bearer_methods_supported' => [ 'header' ], 'scopes_supported' => [ self::SCOPE ] ]; }
	private static function server_data(): array { return [ 'issuer' => home_url(), 'authorization_endpoint' => rest_url( self::NS . '/authorize' ), 'token_endpoint' => rest_url( self::NS . '/token' ), 'registration_endpoint' => rest_url( self::NS . '/register' ), 'revocation_endpoint' => rest_url( self::NS . '/revoke' ), 'response_types_supported' => [ 'code' ], 'grant_types_supported' => [ 'authorization_code', 'refresh_token' ], 'token_endpoint_auth_methods_supported' => [ 'none' ], 'code_challenge_methods_supported' => [ 'S256' ], 'scopes_supported' => [ self::SCOPE ] ]; }
	private static function restore_user(): void { if ( ! is_user_logged_in() ) { $id = wp_validate_auth_cookie( '', 'logged_in' ); if ( $id ) { wp_set_current_user( $id ); } } }
	private static function redirect( string $url, array $args ): void { wp_redirect( add_query_arg( $args, $url ) ); exit; }
	private static function consent_page( array $client, array $p ): void { status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' ); ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?php esc_html_e( 'Authorize MCP client', 'closehub-connector' ); ?></title><style>body{font:16px system-ui;background:#f0f0f1;color:#1d2327;margin:0;display:grid;place-items:center;min-height:100vh}.card{background:#fff;padding:32px;border-radius:8px;max-width:480px;box-shadow:0 1px 3px #0002}button{padding:10px 16px;margin-right:8px}</style></head><body><main class="card"><h1><?php esc_html_e( 'Authorize MCP client', 'closehub-connector' ); ?></h1><p><?php printf( esc_html__( '%s requests access to this WordPress site.', 'closehub-connector' ), esc_html( $client['client_name'] ) ); ?></p><p><?php esc_html_e( 'It will act with the permissions of your current WordPress account.', 'closehub-connector' ); ?></p><form method="post" action="<?php echo esc_url( rest_url( self::NS . '/authorize' ) ); ?>"><?php wp_nonce_field( 'closehub_oauth_authorize', 'closehub_oauth_nonce' ); foreach ( $p as $key => $value ) : ?><input type="hidden" name="<?php echo esc_attr( $key === 'challenge' ? 'code_challenge' : ( $key === 'method' ? 'code_challenge_method' : $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endforeach; ?><button name="decision" value="approve"><?php esc_html_e( 'Authorize', 'closehub-connector' ); ?></button><button name="decision" value="deny"><?php esc_html_e( 'Deny', 'closehub-connector' ); ?></button></form></main></body></html><?php }
	private static function mcp_request(): bool { return false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/mcp/mcp-adapter-default-server' ); }
	private static function table( string $name ): string { global $wpdb; return $wpdb->prefix . 'closehub_oauth_' . $name; }
	private static function hash( string $value ): string { return hash( 'sha256', $value ); }
	private static function error( string $code, string $message, int $status = 400 ): WP_Error { return new WP_Error( $code, $message, [ 'status' => $status ] ); }
}
