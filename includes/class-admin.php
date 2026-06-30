<?php

defined( 'ABSPATH' ) || exit;

class CloseHub_Admin {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_regenerate' ] );
	}

	public function add_menu(): void {
		add_options_page(
			'CloseHub Connector',
			'CloseHub',
			'manage_options',
			'closehub-connector',
			[ $this, 'render_page' ]
		);
	}

	public function handle_regenerate(): void {
		if ( ! isset( $_POST['closehub_regenerate'] ) ) {
			return;
		}
		check_admin_referer( 'closehub_regenerate_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'closehub-connector' ) );
		}
		CloseHub_API_Key::regenerate();
		wp_safe_redirect( add_query_arg( 'closehub_notice', 'regenerated', menu_page_url( 'closehub-connector', false ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key  = CloseHub_API_Key::get();
		$site_url = get_site_url();
		$notice   = isset( $_GET['closehub_notice'] ) ? sanitize_key( $_GET['closehub_notice'] ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CloseHub Connector', 'closehub-connector' ); ?></h1>

			<?php if ( 'regenerated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'API key regenerated successfully.', 'closehub-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Use the credentials below to connect CloseHub to this WordPress site.', 'closehub-connector' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site URL', 'closehub-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $site_url ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $site_url ); ?>')">
							<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'closehub-connector' ); ?></th>
					<td>
						<input
							type="text"
							id="closehub-api-key"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text"
							readonly
							style="font-family:monospace"
						/>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('closehub-api-key').value)">
							<?php esc_html_e( 'Copy', 'closehub-connector' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Keep this key secret. Anyone with it can access your site data via CloseHub.', 'closehub-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Endpoint', 'closehub-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $site_url . '/wp-json/closehub/v1/' ); ?></code>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Regenerate API Key', 'closehub-connector' ); ?></h2>
			<p><?php esc_html_e( 'Regenerating the key will immediately invalidate the current one. You will need to update CloseHub with the new key.', 'closehub-connector' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'closehub_regenerate_key' ); ?>
				<input type="hidden" name="closehub_regenerate" value="1" />
				<?php submit_button( __( 'Regenerate Key', 'closehub-connector' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Available Endpoints', 'closehub-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Method', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'closehub-connector' ); ?></th>
						<th><?php esc_html_e( 'Description', 'closehub-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/ping</code></td><td><?php esc_html_e( 'Verify connection', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>POST</code></td><td><code>/closehub/v1/posts</code></td><td><?php esc_html_e( 'Create a post', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/woocommerce/orders</code></td><td><?php esc_html_e( 'Fetch orders (WooCommerce)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms</code></td><td><?php esc_html_e( 'List forms (Gravity Forms)', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}</code></td><td><?php esc_html_e( 'Get form details', 'closehub-connector' ); ?></td></tr>
					<tr><td><code>GET</code></td><td><code>/closehub/v1/gravity-forms/forms/{id}/entries</code></td><td><?php esc_html_e( 'Count form entries', 'closehub-connector' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
