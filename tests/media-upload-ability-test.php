<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}
class CloseHub_REST_API {
	public static function post_type_allowed( string $post_type ): bool { return true; }
}

$GLOBALS['closehub_test_last_upload'] = null;
$GLOBALS['closehub_test_attachment_id'] = 781;
$GLOBALS['closehub_test_abilities'] = [];
$GLOBALS['closehub_test_filters'] = [];
$GLOBALS['closehub_test_max_upload_size'] = 1024;
$GLOBALS['closehub_test_detected_mime'] = 'image/jpeg';
$GLOBALS['closehub_test_generated_metadata'] = [ 'file' => 'photo.jpg' ];
$GLOBALS['closehub_test_metadata_updated'] = true;
$GLOBALS['closehub_test_stored_metadata'] = false;
$GLOBALS['closehub_test_delete_attachment'] = true;

function add_action( string $hook, callable $callback ): void {}
function add_filter( string $hook, callable $callback, int $priority = 10 ): void { $GLOBALS['closehub_test_filters'][ $hook ][ $priority ][] = $callback; }
function current_user_can( string $capability, ...$args ): bool { return true; }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function sanitize_file_name( string $filename ): string { return trim( basename( $filename ) ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function wp_max_upload_size(): int { return $GLOBALS['closehub_test_max_upload_size']; }
function get_allowed_mime_types(): array { return [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png' ]; }
function wp_check_filetype( string $filename, array $mimes ): array { return str_ends_with( $filename, '.jpg' ) ? [ 'type' => 'image/jpeg' ] : ( str_ends_with( $filename, '.png' ) ? [ 'type' => 'image/png' ] : [ 'type' => false ] ); }
function wp_upload_bits( string $filename, mixed $deprecated, string $contents ): array { $GLOBALS['closehub_test_last_upload'] = compact( 'filename', 'contents' ); return [ 'file' => '/tmp/' . $filename, 'url' => 'https://example.test/uploads/' . $filename, 'error' => false ]; }
function wp_get_image_mime( string $file ): string|false { return $GLOBALS['closehub_test_detected_mime']; }
function wp_delete_file( string $file ): void {}
function wp_insert_attachment( array $attachment, string $file, int $parent, bool $wp_error ): int|WP_Error { return $GLOBALS['closehub_test_attachment_id']; }
function wp_generate_attachment_metadata( int $attachment_id, string $file ): array|WP_Error { return $GLOBALS['closehub_test_generated_metadata']; }
function wp_update_attachment_metadata( int $attachment_id, array $metadata ): bool { return $GLOBALS['closehub_test_metadata_updated']; }
function wp_get_attachment_metadata( int $attachment_id ) { return $GLOBALS['closehub_test_stored_metadata']; }
function wp_delete_attachment( int $attachment_id, bool $force_delete ): bool { return $GLOBALS['closehub_test_delete_attachment']; }
function update_post_meta( int $post_id, string $meta_key, string $value ): bool { return true; }
function wp_get_attachment_url( int $attachment_id ): string { return 'https://example.test/uploads/photo.jpg'; }
function wp_register_ability( string $id, array $args ): bool { $GLOBALS['closehub_test_abilities'][ $id ] = $args; return true; }
function absint( $value ): int { return abs( (int) $value ); }

require_once dirname( __DIR__ ) . '/includes/class-content-abilities.php';

$missing = CloseHub_Content_Abilities::upload_media( [] );
if ( ! $missing instanceof WP_Error || 'closehub_missing_filename' !== $missing->code ) { fwrite( STDERR, "Missing filename should be rejected.\n" ); exit( 1 ); }

$invalid = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.jpg', 'data_base64' => 'not base64' ] );
if ( ! $invalid instanceof WP_Error || 'closehub_invalid_data_base64' !== $invalid->code || 400 !== $invalid->data['status'] ) { fwrite( STDERR, "Invalid base64 should be rejected as a client error.\n" ); exit( 1 ); }

$unsupported = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.txt', 'data_base64' => base64_encode( 'image data' ) ] );
if ( ! $unsupported instanceof WP_Error || 'closehub_invalid_media_type' !== $unsupported->code ) { fwrite( STDERR, "Unsupported media types should be rejected.\n" ); exit( 1 ); }

$GLOBALS['closehub_test_max_upload_size'] = 0;
$quota_exhausted = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.jpg', 'data_base64' => base64_encode( 'image data' ) ] );
if ( ! $quota_exhausted instanceof WP_Error || 413 !== $quota_exhausted->data['status'] ) { fwrite( STDERR, "A zero upload limit should reject the payload.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_max_upload_size'] = 1024;

$mime_mismatch = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.png', 'data_base64' => base64_encode( 'image data' ) ] );
if ( ! $mime_mismatch instanceof WP_Error || 'closehub_invalid_media_type' !== $mime_mismatch->code || 400 !== $mime_mismatch->data['status'] ) { fwrite( STDERR, "An image MIME that does not match its filename should be rejected.\n" ); exit( 1 ); }

$uploaded = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.jpg', 'data_base64' => base64_encode( 'image data' ), 'alt_text' => ' Product photo ' ] );
if ( $uploaded instanceof WP_Error || 781 !== $uploaded['attachment_id'] || 'https://example.test/uploads/photo.jpg' !== $uploaded['url'] ) { fwrite( STDERR, "A valid image should be uploaded and return its Media Library URL.\n" ); exit( 1 ); }
if ( 'photo.jpg' !== $GLOBALS['closehub_test_last_upload']['filename'] || 'image data' !== $GLOBALS['closehub_test_last_upload']['contents'] ) { fwrite( STDERR, "The decoded upload payload was not passed to WordPress.\n" ); exit( 1 ); }

$GLOBALS['closehub_test_metadata_updated'] = false;
$GLOBALS['closehub_test_stored_metadata'] = false;
$metadata_failed = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.jpg', 'data_base64' => base64_encode( 'image data' ) ] );
if ( ! $metadata_failed instanceof WP_Error || 'closehub_media_upload_failed' !== $metadata_failed->code ) { fwrite( STDERR, "A failed metadata save must fail the upload.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_metadata_updated'] = true;

$GLOBALS['closehub_test_generated_metadata'] = new WP_Error( 'metadata_failed' );
$GLOBALS['closehub_test_delete_attachment'] = false;
$cleanup_failed = CloseHub_Content_Abilities::upload_media( [ 'filename' => 'photo.jpg', 'data_base64' => base64_encode( 'image data' ) ] );
if ( ! $cleanup_failed instanceof WP_Error || 'closehub_media_cleanup_failed' !== $cleanup_failed->code ) { fwrite( STDERR, "A failed attachment rollback must be reported.\n" ); exit( 1 ); }
$GLOBALS['closehub_test_generated_metadata'] = [ 'file' => 'photo.jpg' ];
$GLOBALS['closehub_test_delete_attachment'] = true;

CloseHub_Content_Abilities::register_abilities();
$ability = $GLOBALS['closehub_test_abilities']['closehub/upload-media'] ?? null;
if ( ! $ability || ! $ability['meta']['mcp']['public'] || $ability['meta']['annotations']['readonly'] || $ability['meta']['annotations']['idempotent'] ) { fwrite( STDERR, "The media upload ability must be public to MCP and accurately marked as a write.\n" ); exit( 1 ); }

CloseHub_Content_Abilities::register();
$config = [ 'tools' => [ 'mcp-adapter/discover-abilities' ] ];
foreach ( $GLOBALS['closehub_test_filters']['mcp_adapter_default_server_config'] as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$config = $callback( $config );
	}
}
if ( ! in_array( 'closehub/upload-media', $config['tools'], true ) ) { fwrite( STDERR, "The media upload ability must be a direct default-server MCP tool.\n" ); exit( 1 ); }

echo "Media upload ability checks passed.\n";
