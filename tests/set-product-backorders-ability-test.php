<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
}

/** Minimal stand-in for WC_Product / WC_Product_Variation. */
class Fake_Product {
	public int $saves = 0;
	public function __construct( public int $id, public string $sku, public string $backorders, public int $parent = 0 ) {}
	public function get_sku(): string { return $this->sku; }
	public function get_backorders(): string { return $this->backorders; }
	public function set_backorders( string $value ): void { $this->backorders = $value; }
	public function get_parent_id(): int { return $this->parent; }
	public function save(): int { ++$this->saves; return $this->id; }
}

$GLOBALS['closehub_products']  = [];
$GLOBALS['closehub_purged']    = [];
$GLOBALS['closehub_caps']      = [ 'edit_products' ];

function current_user_can( string $capability, ...$args ): bool { return in_array( $capability, $GLOBALS['closehub_caps'], true ); }
function absint( $value ): int { return abs( (int) $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wc_get_product( int $id ) { return $GLOBALS['closehub_products'][ $id ] ?? false; }
function get_post( int $id ) { return isset( $GLOBALS['closehub_products'][ $id ] ) ? (object) [ 'ID' => $id ] : null; }
function apply_filters( string $hook, $value ) {
	if ( 'closehub_clear_cache_providers' === $hook ) {
		$value = [ 'fake' => [ 'label' => 'Fake', 'is_active' => static fn(): bool => true, 'purge' => static function ( string $scope, array $ids ): array { $GLOBALS['closehub_purged'] = $ids; return [ 'cleared' => true, 'post_ids' => $ids ]; } ] ];
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-site-abilities.php';

function closehub_fail( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
function closehub_reset(): void {
	$GLOBALS['closehub_purged']   = [];
	$GLOBALS['closehub_products'] = [
		10 => new Fake_Product( 10, 'MUN-1', 'no' ),
		11 => new Fake_Product( 11, 'MUN-2', 'yes' ),
		20 => new Fake_Product( 20, 'MUN-3-S', 'no', 12 ), // variation of product 12.
		12 => new Fake_Product( 12, 'MUN-3', 'no' ),
	];
}

// ── Permission ────────────────────────────────────────────────────────────────
$GLOBALS['closehub_caps'] = [ 'edit_posts' ];
if ( CloseHub_Site_Abilities::can_edit_products() ) { closehub_fail( 'Without edit_products the ability must be denied.' ); }
$GLOBALS['closehub_caps'] = [ 'edit_products' ];
if ( ! CloseHub_Site_Abilities::can_edit_products() ) { closehub_fail( 'edit_products should allow the ability.' ); }

// ── dry_run (default) does not save ──────────────────────────────────────────
closehub_reset();
$dry = CloseHub_Site_Abilities::set_product_backorders( [ 'items' => [ [ 'id' => 10, 'backorders' => 'notify' ] ] ] );
if ( is_wp_error( $dry ) || true !== $dry['dry_run'] || 'would_change' !== $dry['items'][0]['status'] || 'no' !== $dry['items'][0]['before'] || 'notify' !== $dry['items'][0]['after'] || 1 !== $dry['counts']['would_change'] ) { closehub_fail( 'Default dry run should report would_change: ' . var_export( $dry, true ) ); }
if ( 0 !== $GLOBALS['closehub_products'][10]->saves || 'no' !== $GLOBALS['closehub_products'][10]->backorders || $GLOBALS['closehub_purged'] || isset( $dry['cache'] ) ) { closehub_fail( 'dry_run must not save or purge.' ); }

// ── SKU mismatch: product untouched, reported as error ───────────────────────
closehub_reset();
$mismatch = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 10, 'sku' => 'WRONG', 'backorders' => 'yes' ] ] ] );
if ( is_wp_error( $mismatch ) || 'error' !== $mismatch['items'][0]['status'] || empty( $mismatch['items'][0]['message'] ) || 1 !== $mismatch['counts']['error'] ) { closehub_fail( 'SKU mismatch should be an error item.' ); }
if ( 0 !== $GLOBALS['closehub_products'][10]->saves || 'no' !== $GLOBALS['closehub_products'][10]->backorders ) { closehub_fail( 'SKU mismatch must not touch the product.' ); }

// ── Already correct: unchanged, no save ──────────────────────────────────────
closehub_reset();
$same = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 11, 'sku' => 'MUN-2', 'backorders' => 'yes' ] ] ] );
if ( is_wp_error( $same ) || 'unchanged' !== $same['items'][0]['status'] || 1 !== $same['counts']['unchanged'] || 0 !== $GLOBALS['closehub_products'][11]->saves || isset( $same['cache'] ) ) { closehub_fail( 'Matching value should be unchanged without saving.' ); }

// ── Real change: saved and cache purged ──────────────────────────────────────
closehub_reset();
$real = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 10, 'sku' => 'MUN-1', 'backorders' => 'notify' ] ] ] );
if ( is_wp_error( $real ) || 'changed' !== $real['items'][0]['status'] || 'no' !== $real['items'][0]['before'] || 'notify' !== $real['items'][0]['after'] || 1 !== $real['counts']['changed'] ) { closehub_fail( 'Real change should report changed: ' . var_export( $real, true ) ); }
if ( 1 !== $GLOBALS['closehub_products'][10]->saves || 'notify' !== $GLOBALS['closehub_products'][10]->backorders ) { closehub_fail( 'Real change should save once.' ); }
if ( [ 10 ] !== $GLOBALS['closehub_purged'] || empty( $real['cache']['cleared'] ) ) { closehub_fail( 'Changed products should be purged from cache.' ); }

// ── Variation: variation itself changes, parent only gets purged ─────────────
closehub_reset();
$var = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 20, 'backorders' => 'yes' ] ] ] );
if ( is_wp_error( $var ) || 'changed' !== $var['items'][0]['status'] || 'MUN-3-S' !== $var['items'][0]['sku'] ) { closehub_fail( 'Variation should be changed.' ); }
if ( 'yes' !== $GLOBALS['closehub_products'][20]->backorders || 'no' !== $GLOBALS['closehub_products'][12]->backorders || 0 !== $GLOBALS['closehub_products'][12]->saves ) { closehub_fail( 'Only the variation must be modified, never its parent.' ); }
if ( [ 20, 12 ] !== $GLOBALS['closehub_purged'] ) { closehub_fail( 'Variation and parent should be purged.' ); }

// ── Nonexistent ID ────────────────────────────────────────────────────────────
closehub_reset();
$missing = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 999, 'backorders' => 'yes' ], [ 'id' => 10, 'backorders' => 'yes' ] ] ] );
if ( is_wp_error( $missing ) || 'error' !== $missing['items'][0]['status'] || 'changed' !== $missing['items'][1]['status'] || 1 !== $missing['counts']['error'] || 1 !== $missing['counts']['changed'] ) { closehub_fail( 'Unknown ID should be an error item without aborting the batch.' ); }

// ── Input validation ─────────────────────────────────────────────────────────
$empty = CloseHub_Site_Abilities::set_product_backorders( [ 'items' => [] ] );
if ( ! $empty instanceof WP_Error || 400 !== $empty->data['status'] ) { closehub_fail( 'Empty items should return 400.' ); }
$too_many = CloseHub_Site_Abilities::set_product_backorders( [ 'items' => array_fill( 0, 501, [ 'id' => 10, 'backorders' => 'no' ] ) ] );
if ( ! $too_many instanceof WP_Error || 400 !== $too_many->data['status'] ) { closehub_fail( 'More than 500 items should return 400.' ); }
closehub_reset();
$bad = CloseHub_Site_Abilities::set_product_backorders( [ 'dry_run' => false, 'items' => [ [ 'id' => 10, 'backorders' => 'maybe' ] ] ] );
if ( 'error' !== $bad['items'][0]['status'] || 0 !== $GLOBALS['closehub_products'][10]->saves ) { closehub_fail( 'Invalid backorders value should be an error item.' ); }

echo "Set product backorders ability checks passed.\n";
