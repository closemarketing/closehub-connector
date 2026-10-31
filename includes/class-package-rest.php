<?php

defined( 'ABSPATH' ) || exit;

/**
 * Deploy route for plugin and theme zips.
 *
 * Authenticated with a single-use ticket from the closehub/create-deploy-ticket
 * ability (see CloseHub_Deploy_Ticket), never with cookies, application
 * passwords or the CloseHub API key: the ticket is bound to one user, one
 * type and one slug, and expires after 15 minutes.
 *
 * The zip is sent as multipart/form-data in the "file" field so a script
 * (bin/closehub-deploy) can upload it straight from disk. type, slug and the
 * flags go in the query string, so they still arrive when the body is larger
 * than post_max_size and the error can say so.
 *
 *   POST /wp-json/closehub/v1/packages/install?type=theme&slug=my-theme
 *   X-CloseHub-Deploy-Ticket: <ticket>
 *
 * Version checks and rollbacks go through the closehub/get-installed-version
 * and closehub/rollback-package abilities, which need no upload.
 */
class CloseHub_Package_REST {
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( CloseHub_REST_API::NAMESPACE, '/packages/install', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'install' ],
			'permission_callback' => [ $this, 'authorize' ],
			'args'                => [
				'type'        => [ 'type' => 'string', 'enum' => CloseHub_Package_Installer::TYPES ],
				'slug'        => [ 'type' => 'string' ],
				'overwrite'   => [ 'type' => 'boolean', 'default' => true ],
				'activate'    => [ 'type' => 'boolean', 'default' => false ],
				'clear_cache' => [ 'type' => 'boolean', 'default' => true ],
			],
		] );
	}

	/** Redeem the ticket and act as the user it was issued to, whoever else the request claims to be. */
	public function authorize( WP_REST_Request $request ): bool|WP_Error {
		$type    = (string) $request->get_param( 'type' );
		$user_id = CloseHub_Deploy_Ticket::redeem( (string) $request->get_header( CloseHub_Deploy_Ticket::HEADER ), $type, (string) $request->get_param( 'slug' ) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		wp_set_current_user( $user_id );
		if ( ! current_user_can( 'theme' === $type ? 'install_themes' : 'install_plugins' ) ) {
			return new WP_Error( 'closehub_forbidden', 'The user who created the ticket can no longer install this package.', [ 'status' => 403 ] );
		}
		return true;
	}

	// ── Callbacks ──────────────────────────────────────────────────────────────

	public function install( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = (string) $request->get_param( 'type' );
		$slug = (string) $request->get_param( 'slug' );
		if ( ! in_array( $type, CloseHub_Package_Installer::TYPES, true ) || ! CloseHub_Package_Installer::valid_slug( $slug ) ) {
			return new WP_Error( 'closehub_package_invalid_slug', 'type (plugin or theme) and slug (the folder name inside the zip) are required.', [ 'status' => 400 ] );
		}

		$file = $this->uploaded_zip( $request );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$result = CloseHub_Package_Installer::install( $type, $file, [
			'slug'        => $slug,
			'overwrite'   => (bool) $request->get_param( 'overwrite' ),
			'activate'    => (bool) $request->get_param( 'activate' ),
			'clear_cache' => (bool) $request->get_param( 'clear_cache' ),
		] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** The uploaded "file" field, checked against the server's upload limits. */
	private function uploaded_zip( WP_REST_Request $request ): string|WP_Error {
		$file = $request->get_file_params()['file'] ?? null;

		if ( ! is_array( $file ) ) {
			$post_max = $this->ini_bytes( 'post_max_size' );
			$length   = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			if ( $post_max > 0 && $length > $post_max ) {
				return $this->too_large( $length, $post_max, 'post_max_size' );
			}
			return new WP_Error( 'closehub_package_missing_file', 'Send the zip as multipart/form-data in the "file" field.', [ 'status' => 400 ] );
		}

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return $this->too_large( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ), $this->ini_bytes( 'upload_max_filesize' ), 'upload_max_filesize' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'closehub_package_upload_failed', sprintf( 'The upload failed (PHP upload error %d).', $error ), [ 'status' => 400 ] );
		}
		if ( ! $this->is_http_upload( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'closehub_package_upload_failed', 'The file was not uploaded through HTTP POST.', [ 'status' => 400 ] );
		}
		if ( 'zip' !== strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'closehub_invalid_filename', 'The uploaded file must be a .zip.', [ 'status' => 400 ] );
		}
		$max = wp_max_upload_size();
		if ( (int) $file['size'] > $max ) {
			return $this->too_large( (int) $file['size'], $max, 'the WordPress upload limit' );
		}
		return (string) $file['tmp_name'];
	}

	/** Separate so tests can stand in for PHP's real upload handling and limits. */
	protected function is_http_upload( string $path ): bool {
		return is_uploaded_file( $path );
	}

	protected function ini_bytes( string $setting ): int {
		return wp_convert_hr_to_bytes( (string) ini_get( $setting ) );
	}

	private function too_large( int $size, int $limit, string $setting ): WP_Error {
		return new WP_Error(
			'closehub_package_too_large',
			sprintf( 'The upload (%1$s) exceeds this server\'s %2$s (%3$s). Raise upload_max_filesize and post_max_size, or ask the host to.', $size ? size_format( $size ) : 'unknown size', $setting, size_format( $limit ) ),
			[ 'status' => 413, 'limit_bytes' => $limit ]
		);
	}
}
