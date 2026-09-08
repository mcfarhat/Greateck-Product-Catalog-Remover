<?php
/**
 * Admin screen and AJAX endpoints.
 *
 * @package WooProductRemover
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the settings screen and drives the batched removal over AJAX.
 */
class WPR_Admin {

	/**
	 * Capability required to use the plugin.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action shared by both AJAX endpoints.
	 */
	const NONCE = 'wpr_run_removal';

	/**
	 * Menu slug.
	 */
	const SLUG = 'woo-product-remover';

	/**
	 * Singleton.
	 *
	 * @var WPR_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return WPR_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpr_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_wpr_step', array( $this, 'ajax_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add a settings shortcut on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Remove products', 'woo-product-remover' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the admin menu entry.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Woo Product Remover', 'woo-product-remover' ),
			__( 'Woo Product Remover', 'woo-product-remover' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-trash',
			58
		);
	}

	/**
	 * Load CSS and JS, only on our own screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wpr-admin',
			WPR_URL . 'assets/css/wpr-admin.css',
			array(),
			WPR_VERSION
		);

		wp_enqueue_script(
			'wpr-admin',
			WPR_URL . 'assets/js/wpr-admin.js',
			array(),
			WPR_VERSION,
			true
		);

		wp_localize_script(
			'wpr-admin',
			'wprData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'confirm'    => __( 'This permanently deletes every product in this store and cannot be undone. Continue?', 'woo-product-remover' ),
					'starting'   => __( 'Starting…', 'woo-product-remover' ),
					'working'    => __( 'Removing products… %1$s of %2$s', 'woo-product-remover' ),
					'finishing'  => __( 'Cleaning up leftover data…', 'woo-product-remover' ),
					'done'       => __( 'All done.', 'woo-product-remover' ),
					'failed'     => __( 'Something went wrong. Please reload the page and try again.', 'woo-product-remover' ),
					'nothing'    => __( 'There are no products to remove.', 'woo-product-remover' ),
					'products'   => __( 'Products removed', 'woo-product-remover' ),
					'variations' => __( 'Variations removed', 'woo-product-remover' ),
					'images'     => __( 'Images removed', 'woo-product-remover' ),
					'reviews'    => __( 'Reviews removed', 'woo-product-remover' ),
					'terms'      => __( 'Categories, tags and attribute terms removed', 'woo-product-remover' ),
				),
			)
		);
	}

	/**
	 * Reject the request unless the user is allowed to run this and the nonce checks out.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to remove products.', 'woo-product-remover' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * AJAX: begin a run.
	 */
	public function ajax_start() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$settings = array(
			'remove_terms'     => ! empty( $_POST['remove_terms'] ),
			'remove_images'    => ! empty( $_POST['remove_images'] ),
			'remove_reviews'   => ! empty( $_POST['remove_reviews'] ),
			'remove_downloads' => ! empty( $_POST['remove_downloads'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$job = WPR_Remover::start_job( $settings );

		wp_send_json_success( $this->response( $job ) );
	}

	/**
	 * AJAX: run one batch.
	 */
	public function ajax_step() {
		$this->guard();

		$job = WPR_Remover::run_batch();

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( $this->response( $job ) );
	}

	/**
	 * Shape a job into the payload the browser expects.
	 *
	 * @param array $job Job state.
	 * @return array
	 */
	private function response( array $job ) {
		// Read defensively: a job stored by an earlier version of the plugin,
		// or one left behind mid-upgrade, may not have every key.
		$counts = array();

		foreach ( array( 'products', 'variations', 'images', 'reviews', 'terms' ) as $key ) {
			$counts[ $key ] = isset( $job[ $key ] ) ? (int) $job[ $key ] : 0;
		}

		$handled = $counts['products'] + $counts['variations'];
		$total   = isset( $job['total'] ) ? (int) $job['total'] : 0;

		return array(
			'done'      => ! empty( $job['done'] ),
			'error'     => isset( $job['error'] ) ? (string) $job['error'] : '',
			'total'     => max( $total, $handled ),
			'handled'   => $handled,
			'remaining' => WPR_Remover::count_remaining(),
			'counts'    => $counts,
		);
	}

	/**
	 * Render the admin screen.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woo-product-remover' ) );
		}

		// A stale job is not cleared here on purpose: opening this screen in a
		// second tab while a run is in progress would otherwise kill the run.
		// Starting a new run overwrites the stored job anyway.
		$count             = WPR_Remover::count_remaining();
		$woocommerce_ready = class_exists( 'WooCommerce' );

		?>
		<div class="wrap wpr-wrap">
			<h1><?php esc_html_e( 'Woo Product Remover', 'woo-product-remover' ); ?></h1>

			<?php if ( ! $woocommerce_ready ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'WooCommerce is not active. You can still clear out product data left behind in the database, but nothing else on this site will be affected.', 'woo-product-remover' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-error wpr-warning">
				<p>
					<strong><?php esc_html_e( 'This cannot be undone.', 'woo-product-remover' ); ?></strong>
					<?php esc_html_e( 'Back up your database before you continue. Orders and customers are never touched, but every product is deleted permanently.', 'woo-product-remover' ); ?>
				</p>
			</div>

			<div class="wpr-card">
				<p class="wpr-count">
					<?php
					printf(
						/* translators: %s: number of products, formatted. */
						esc_html( _n( '%s product or variation is currently in the database.', '%s products and variations are currently in the database.', $count, 'woo-product-remover' ) ),
						'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>'
					);
					?>
				</p>

				<form id="wpr-form" method="post" onsubmit="return false;">
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Optional extras', 'woo-product-remover' ); ?></legend>

						<p>
							<label>
								<input type="checkbox" name="remove_terms" value="1" />
								<?php esc_html_e( 'Also remove product categories, tags and attribute terms', 'woo-product-remover' ); ?>
							</label>
							<span class="description">
								<?php esc_html_e( "WooCommerce's own product type and visibility terms, and your default product category, are always kept.", 'woo-product-remover' ); ?>
							</span>
						</p>

						<p>
							<label>
								<input type="checkbox" name="remove_images" value="1" />
								<?php esc_html_e( 'Also remove product images', 'woo-product-remover' ); ?>
							</label>
							<span class="description">
								<?php esc_html_e( 'Deletes the files from your uploads folder. Only images uploaded directly to a product are removed; media used elsewhere is left alone.', 'woo-product-remover' ); ?>
							</span>
						</p>

						<p>
							<label>
								<input type="checkbox" name="remove_reviews" value="1" />
								<?php esc_html_e( 'Also remove product reviews', 'woo-product-remover' ); ?>
							</label>
						</p>

						<p>
							<label>
								<input type="checkbox" name="remove_downloads" value="1" />
								<?php esc_html_e( 'Also remove customer download permissions and download logs', 'woo-product-remover' ); ?>
							</label>
							<span class="description">
								<?php esc_html_e( 'Customers who bought a downloadable product will lose access to their files.', 'woo-product-remover' ); ?>
							</span>
						</p>
					</fieldset>

					<p class="wpr-confirm">
						<label>
							<input type="checkbox" id="wpr-understand" />
							<?php esc_html_e( 'I understand this permanently deletes all products and cannot be undone.', 'woo-product-remover' ); ?>
						</label>
					</p>

					<p>
						<button type="submit" class="button button-primary button-large" id="wpr-start" disabled>
							<?php esc_html_e( 'Delete all products', 'woo-product-remover' ); ?>
						</button>
					</p>
				</form>

				<div id="wpr-progress" class="wpr-progress" hidden>
					<div class="wpr-bar"><span id="wpr-bar-fill"></span></div>
					<p id="wpr-status" class="wpr-status" role="status" aria-live="polite"></p>
					<ul id="wpr-results" class="wpr-results"></ul>
				</div>
			</div>

			<p class="wpr-credit">
				<?php
				printf(
					/* translators: %s: link to the author's site. */
					esc_html__( 'Thank you for using Woo Product Remover by %s', 'woo-product-remover' ),
					'<a href="https://www.greateck.com" target="_blank" rel="noopener noreferrer">Greateck</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
