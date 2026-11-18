<?php

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/class-woocommerce-stats.php';

$fail = static function ( string $message ): void { fwrite( STDERR, $message . "\n" ); exit( 1 ); };
$same = static function ( mixed $actual, mixed $expected, string $label ) use ( $fail ): void {
	if ( $actual !== $expected ) {
		$fail( sprintf( "%s\nexpected: %s\nactual:   %s", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
	}
};

$order = static fn( string $email, string $name, string $date, float $total, array $items, string $company = '' ): array => [
	'email' => $email, 'name' => $name, 'company' => $company, 'total' => $total,
	'date' => new DateTimeImmutable( $date ), 'items' => $items,
];
$item = static fn( int $id, string $name, int $qty, float $total ): array => [ 'id' => $id, 'name' => $name, 'quantity' => $qty, 'total' => $total ];

$paid = [
	// Monday 2026-11-02 21:30 -> row 0, slot 20-24 (5).
	$order( 'ana@example.com', 'Ana Lopez Ruiz', '2026-11-02 21:30:00', 100.0, [ $item( 1, 'Jamon', 1, 80.0 ), $item( 2, 'Lote', 2, 20.0 ) ] ),
	// Same customer, other case in the e-mail, Wednesday 08:00 -> row 2, slot 8-11 (1).
	$order( 'ANA@example.com', 'Ana Lopez Ruiz', '2026-11-04 08:00:00', 50.0, [ $item( 1, 'Jamon', 1, 50.0 ) ] ),
	// Company wins over the person's name; Sunday 2026-11-08 00:00 -> row 6, slot 0-8 (0).
	$order( 'bar@example.com', 'Pepe', '2026-11-08 00:00:00', 300.0, [ $item( 3, 'Chorizo', 6, 300.0 ) ], 'Bar Pepe' ),
	// No e-mail: counted in products and heat map, never as a customer.
	$order( '', 'Anon', '2026-11-08 23:59:00', 10.0, [ $item( 2, 'Lote', 1, 10.0 ) ] ),
];
$unpaid = [ $order( 'x@example.com', 'X', '2026-11-05 10:00:00', 25.5, [] ), $order( 'y@example.com', 'Y', '2026-11-05 11:00:00', 14.5, [] ) ];

// Ana bought before the period, so only the bar is new.
$stats = CloseHub_WooCommerce_Stats::build( $paid, $unpaid, static fn( string $email ): bool => 'ana@example.com' === $email, 10 );

$same( array_column( $stats['products'], 'name' ), [ 'Chorizo', 'Jamon', 'Lote' ], 'products are sorted by revenue' );
$same( $stats['products'][1], [ 'revenue' => 130.0, 'id' => 1, 'name' => 'Jamon', 'quantity' => 2 ], 'a product adds up across orders' );
$same( $stats['products'][2]['quantity'], 3, 'quantities add up' );

$same( array_column( $stats['customers'], 'name' ), [ 'Bar Pepe', 'Ana L.' ], 'customers are grouped by e-mail, sorted by spend, named without the e-mail' );
$same( $stats['customers'][1], [ 'spent' => 150.0, 'name' => 'Ana L.', 'orders' => 2, 'last_order' => '2026-11-04' ], 'a customer adds up their orders' );
$same( str_contains( json_encode( $stats ), '@' ), false, 'no e-mail leaves the site' );

$same( $stats['new_customers'], 1, 'only customers without an earlier order are new' );

$same( $stats['heatmap']['slots'], [ '0-8', '8-11', '11-14', '14-17', '17-20', '20-24' ], 'slots' );
$same( $stats['heatmap']['rows'][0], [ 0, 0, 0, 0, 0, 1 ], 'Monday evening' );
$same( $stats['heatmap']['rows'][2], [ 0, 1, 0, 0, 0, 0 ], 'Wednesday morning' );
$same( $stats['heatmap']['rows'][6], [ 1, 0, 0, 0, 0, 1 ], 'Sunday: midnight and 23:59' );

$same( $stats['unpaid'], [ 'count' => 2, 'total' => 40.0 ], 'unpaid orders' );

$top = CloseHub_WooCommerce_Stats::build( $paid, [], static fn(): bool => false, 1 );
$same( count( $top['products'] ), 1, 'the list is cut to the top' );
$same( count( $top['customers'] ), 1, 'the customer list is cut to the top' );

echo "ok\n";
