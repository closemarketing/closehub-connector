<?php

defined( 'ABSPATH' ) || exit;

/**
 * Store statistics for CloseHub: best sellers, best customers, new customers,
 * when people buy and orders left unpaid.
 *
 * build() works on plain order rows (no WooCommerce objects), so the maths
 * can be tested without WordPress; CloseHub_REST_API turns WC orders into rows.
 *
 * Order row: [
 *   'email' => string, 'name' => string, 'company' => string,
 *   'total' => float, 'date' => DateTimeInterface (site time zone),
 *   'items' => list<array{id: int, name: string, quantity: int, total: float}>,
 * ]
 */
class CloseHub_WooCommerce_Stats {

	/** Hour slots of the "when do they buy" grid: [from, to), 24 closes the day. */
	const SLOT_BOUNDS = [ 0, 8, 11, 14, 17, 20, 24 ];

	/**
	 * @param array<int, array<string, mixed>> $paid_orders   Paid orders of the period.
	 * @param array<int, array<string, mixed>> $unpaid_orders Orders left pending, failed or in draft checkout.
	 * @param callable                         $bought_before Receives an e-mail and says whether it ordered before the period.
	 * @param int                              $top           How many products and customers to list.
	 * @return array<string, mixed>
	 */
	public static function build( array $paid_orders, array $unpaid_orders, callable $bought_before, int $top = 10 ): array {
		return [
			'products'  => self::top_products( $paid_orders, $top ),
			'customers' => self::top_customers( $paid_orders, $top ),
			'new_customers'       => self::count_new_customers( $paid_orders, $bought_before ),
			'heatmap'   => self::heatmap( $paid_orders ),
			'unpaid'    => [
				'count' => count( $unpaid_orders ),
				'total' => round( array_sum( array_column( $unpaid_orders, 'total' ) ), 2 ),
			],
		];
	}

	/** @return array<int, array{id: int, name: string, quantity: int, revenue: float}> */
	private static function top_products( array $orders, int $top ): array {
		$products = [];

		foreach ( $orders as $order ) {
			foreach ( $order['items'] as $item ) {
				$key = (int) $item['id'];
				$products[ $key ] ??= [ 'id' => $key, 'name' => (string) $item['name'], 'quantity' => 0, 'revenue' => 0.0 ];
				$products[ $key ]['quantity'] += (int) $item['quantity'];
				$products[ $key ]['revenue']  += (float) $item['total'];
			}
		}

		usort( $products, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );

		return array_map(
			static fn( $p ) => [ 'revenue' => round( $p['revenue'], 2 ) ] + $p,
			array_slice( $products, 0, $top )
		);
	}

	/**
	 * Customers are grouped by billing e-mail, which is never sent out: only a
	 * display name (company, or first name and the initial of the first surname).
	 *
	 * @return array<int, array{name: string, orders: int, spent: float, last_order: string}>
	 */
	private static function top_customers( array $orders, int $top ): array {
		$customers = [];

		foreach ( $orders as $order ) {
			$email = strtolower( trim( (string) $order['email'] ) );
			if ( '' === $email ) {
				continue;
			}

			$customers[ $email ] ??= [ 'name' => self::display_name( $order ), 'orders' => 0, 'spent' => 0.0, 'last_order' => '' ];
			$customers[ $email ]['orders']++;
			$customers[ $email ]['spent'] += (float) $order['total'];
			$customers[ $email ]['last_order'] = max( $customers[ $email ]['last_order'], $order['date']->format( 'Y-m-d' ) );
		}

		usort( $customers, static fn( $a, $b ) => $b['spent'] <=> $a['spent'] );

		return array_map(
			static fn( $c ) => [ 'spent' => round( $c['spent'], 2 ) ] + $c,
			array_slice( $customers, 0, $top )
		);
	}

	private static function display_name( array $order ): string {
		$company = trim( (string) $order['company'] );
		if ( '' !== $company ) {
			return $company;
		}

		$parts = preg_split( '/\s+/', trim( (string) $order['name'] ) ) ?: [];
		if ( count( $parts ) < 2 ) {
			return $parts[0] ?? '';
		}

		return $parts[0] . ' ' . mb_strtoupper( mb_substr( $parts[1], 0, 1 ) ) . '.';
	}

	/** Customers (by e-mail) in the period whose first ever order falls in it. */
	private static function count_new_customers( array $orders, callable $bought_before ): int {
		$emails = [];
		foreach ( $orders as $order ) {
			$email = strtolower( trim( (string) $order['email'] ) );
			if ( '' !== $email ) {
				$emails[ $email ] = true;
			}
		}

		return count( array_filter( array_keys( $emails ), static fn( $email ) => ! $bought_before( $email ) ) );
	}

	/**
	 * Orders per weekday (Monday first) and hour slot.
	 *
	 * @return array{slots: array<int, string>, rows: array<int, array<int, int>>}
	 */
	private static function heatmap( array $orders ): array {
		$slot_count = count( self::SLOT_BOUNDS ) - 1;
		$rows       = array_fill( 0, 7, array_fill( 0, $slot_count, 0 ) );

		foreach ( $orders as $order ) {
			$weekday = (int) $order['date']->format( 'N' ) - 1;
			$hour    = (int) $order['date']->format( 'G' );

			for ( $slot = 0; $slot < $slot_count; $slot++ ) {
				if ( $hour >= self::SLOT_BOUNDS[ $slot ] && $hour < self::SLOT_BOUNDS[ $slot + 1 ] ) {
					$rows[ $weekday ][ $slot ]++;
					break;
				}
			}
		}

		$slots = [];
		for ( $slot = 0; $slot < $slot_count; $slot++ ) {
			$slots[] = self::SLOT_BOUNDS[ $slot ] . '-' . self::SLOT_BOUNDS[ $slot + 1 ];
		}

		return [ 'slots' => $slots, 'rows' => $rows ];
	}
}
