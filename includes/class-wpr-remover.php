<?php
/**
 * The deletion engine.
 *
 * Products are removed with direct SQL, which is what makes this plugin fast on
 * large catalogs. The trade-off is that WooCommerce's data stores never get a
 * chance to tidy up after themselves, so every table WooCommerce keys by
 * product ID has to be cleaned here by hand. Missing one of them leaves ghost
 * rows that keep deleted products showing up in search, filters and reports.
 *
 * @package WooProductRemover
 */

defined( 'ABSPATH' ) || exit;

/*
 * Direct queries and interpolated ID lists are the entire point of this class:
 * every interpolated value is an integer read back out of the database and run
 * through absint(), and caching a DELETE makes no sense.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Removes WooCommerce products and everything hanging off them.
 */
class WPR_Remover {

	/**
	 * Option holding the state of the run in progress.
	 */
	const OPTION_JOB = 'wpr_job';

	/**
	 * Products handled per AJAX request.
	 */
	const DEFAULT_BATCH = 200;

	/**
	 * Post types treated as products.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = apply_filters( 'wpr_product_post_types', array( 'product', 'product_variation' ) );
		$types = array_filter( array_map( 'sanitize_key', (array) $types ) );

		return $types ? $types : array( 'product', 'product_variation' );
	}

	/**
	 * Product post types as a quoted SQL list.
	 *
	 * @return string
	 */
	private static function post_types_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', self::post_types() ) ) . "'";
	}

	/**
	 * Taxonomies considered "product categories, tags and attributes".
	 *
	 * product_type and product_visibility are deliberately excluded. WooCommerce
	 * creates those terms itself (simple, variable, featured, rated-1..5) and
	 * attaches them to every product, so deleting them along with the products
	 * would break the store rather than clean it.
	 *
	 * @return string[]
	 */
	public static function term_taxonomies() {
		global $wpdb;

		$taxonomies = array( 'product_cat', 'product_tag' );

		// Product attribute taxonomies are registered dynamically as pa_*.
		$attributes = $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE 'pa\\_%'" );
		if ( $attributes ) {
			$taxonomies = array_merge( $taxonomies, $attributes );
		}

		$taxonomies = apply_filters( 'wpr_term_taxonomies', array_unique( $taxonomies ) );

		return array_filter( array_map( 'sanitize_key', (array) $taxonomies ) );
	}

	/**
	 * Batch size, clamped to something sane.
	 *
	 * @return int
	 */
	public static function batch_size() {
		$size = (int) apply_filters( 'wpr_batch_size', self::DEFAULT_BATCH );

		return max( 1, min( 5000, $size ) );
	}

	/**
	 * How many products and variations are still in the database.
	 *
	 * @return int
	 */
	public static function count_remaining() {
		global $wpdb;

		$types = self::post_types_sql();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types})" );
	}

	/**
	 * Currently stored job, if any.
	 *
	 * @return array|null
	 */
	public static function get_job() {
		$job = get_option( self::OPTION_JOB );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * Clear the stored job.
	 */
	public static function clear_job() {
		delete_option( self::OPTION_JOB );
	}

	/**
	 * Begin a new removal run.
	 *
	 * @param array $settings Which optional extras to include.
	 * @return array The new job.
	 */
	public static function start_job( array $settings ) {
		$job = array(
			'settings'   => array(
				'remove_terms'     => ! empty( $settings['remove_terms'] ),
				'remove_images'    => ! empty( $settings['remove_images'] ),
				'remove_reviews'   => ! empty( $settings['remove_reviews'] ),
				'remove_downloads' => ! empty( $settings['remove_downloads'] ),
			),
			'total'      => self::count_remaining(),
			'products'   => 0,
			'variations' => 0,
			'images'     => 0,
			'reviews'    => 0,
			'terms'      => 0,
			'started'    => time(),
			'done'       => false,
			'error'      => '',
		);

		update_option( self::OPTION_JOB, $job, false );

		return $job;
	}

	/**
	 * Process one batch of products.
	 *
	 * @return array|WP_Error The updated job, or an error.
	 */
	public static function run_batch() {
		global $wpdb;

		$job = self::get_job();

		if ( null === $job ) {
			return new WP_Error( 'wpr_no_job', __( 'No removal is in progress. Please start again.', 'woo-product-remover' ) );
		}

		if ( ! empty( $job['done'] ) ) {
			return $job;
		}

		$types = self::post_types_sql();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type IN ({$types}) ORDER BY ID ASC LIMIT %d",
				self::batch_size()
			)
		);

		if ( empty( $rows ) ) {
			self::finalize( $job );
			$job['done'] = true;
			update_option( self::OPTION_JOB, $job, false );

			return $job;
		}

		$ids        = array();
		$products   = 0;
		$variations = 0;

		foreach ( $rows as $row ) {
			$ids[] = (int) $row->ID;

			if ( 'product_variation' === $row->post_type ) {
				++$variations;
			} else {
				++$products;
			}
		}

		/**
		 * Fires before a batch of products is deleted.
		 *
		 * Because deletion is done in raw SQL, the usual before_delete_post and
		 * woocommerce_delete_product hooks never fire. This is the hook to use
		 * if you need to clean up your own tables.
		 *
		 * @param int[] $ids Product and variation IDs about to be deleted.
		 */
		do_action( 'wpr_before_delete_batch', $ids );

		$job['images']  += self::delete_attachments( $ids, $job['settings']['remove_images'] );
		$job['reviews'] += self::delete_reviews( $ids, $job['settings']['remove_reviews'] );

		self::delete_downloads( $ids, $job['settings']['remove_downloads'] );
		self::delete_lookup_tables( $ids );

		$deleted = self::delete_posts( $ids );

		if ( ! $deleted ) {
			// Nothing was removed, so the next request would read the same rows
			// back and spin forever. Stop instead.
			$job['done']  = true;
			$job['error'] = __( 'The database refused to delete any products. Check that the database user has DELETE permission, then try again.', 'woo-product-remover' );

			update_option( self::OPTION_JOB, $job, false );

			return $job;
		}

		$job['products']   += $products;
		$job['variations'] += $variations;

		/**
		 * Fires after a batch of products has been deleted.
		 *
		 * @param int[] $ids Product and variation IDs that were deleted.
		 */
		do_action( 'wpr_after_delete_batch', $ids );

		update_option( self::OPTION_JOB, $job, false );

		return $job;
	}

	/**
	 * Delete the posts, their meta and their term relationships.
	 *
	 * @param int[] $ids Post IDs.
	 * @return int Number of posts removed.
	 */
	private static function delete_posts( array $ids ) {
		global $wpdb;

		$in = self::id_list( $ids );

		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );

		return (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
	}

	/**
	 * Remove WooCommerce's product-keyed lookup tables for these products.
	 *
	 * @param int[] $ids Product IDs.
	 */
	private static function delete_lookup_tables( array $ids ) {
		global $wpdb;

		$in     = self::id_list( $ids );
		$prefix = $wpdb->prefix;

		self::query_if_table_exists(
			$prefix . 'wc_product_meta_lookup',
			"DELETE FROM {$prefix}wc_product_meta_lookup WHERE product_id IN ({$in})"
		);

		// Variations are stored against their parent here as well.
		self::query_if_table_exists(
			$prefix . 'wc_product_attributes_lookup',
			"DELETE FROM {$prefix}wc_product_attributes_lookup WHERE product_id IN ({$in}) OR product_or_parent_id IN ({$in})"
		);

		self::query_if_table_exists(
			$prefix . 'wc_reserved_stock',
			"DELETE FROM {$prefix}wc_reserved_stock WHERE product_id IN ({$in})"
		);

		// Back-in-stock notifications, plus their meta rows.
		if ( self::table_exists( $prefix . 'wc_stock_notifications' ) ) {
			$notification_ids = $wpdb->get_col( "SELECT id FROM {$prefix}wc_stock_notifications WHERE product_id IN ({$in})" );

			if ( $notification_ids ) {
				$notification_in = self::id_list( $notification_ids );

				self::query_if_table_exists(
					$prefix . 'wc_stock_notificationmeta',
					"DELETE FROM {$prefix}wc_stock_notificationmeta WHERE notification_id IN ({$notification_in})"
				);

				$wpdb->query( "DELETE FROM {$prefix}wc_stock_notifications WHERE id IN ({$notification_in})" );
			}
		}
	}

	/**
	 * Delete customer download permissions and their access logs.
	 *
	 * @param int[] $ids     Product IDs.
	 * @param bool  $enabled Whether the user asked for this.
	 */
	private static function delete_downloads( array $ids, $enabled ) {
		global $wpdb;

		if ( ! $enabled ) {
			return;
		}

		$prefix      = $wpdb->prefix;
		$permissions = $prefix . 'woocommerce_downloadable_product_permissions';

		if ( ! self::table_exists( $permissions ) ) {
			return;
		}

		$in             = self::id_list( $ids );
		$permission_ids = $wpdb->get_col( "SELECT permission_id FROM {$permissions} WHERE product_id IN ({$in})" );

		if ( ! $permission_ids ) {
			return;
		}

		$permission_in = self::id_list( $permission_ids );

		// The log references permissions, so it has to go first.
		self::query_if_table_exists(
			$prefix . 'wc_download_log',
			"DELETE FROM {$prefix}wc_download_log WHERE permission_id IN ({$permission_in})"
		);

		$wpdb->query( "DELETE FROM {$permissions} WHERE permission_id IN ({$permission_in})" );
	}

	/**
	 * Delete product reviews and their meta.
	 *
	 * @param int[] $ids     Product IDs.
	 * @param bool  $enabled Whether the user asked for this.
	 * @return int Number of reviews removed.
	 */
	private static function delete_reviews( array $ids, $enabled ) {
		global $wpdb;

		if ( ! $enabled ) {
			return 0;
		}

		$in          = self::id_list( $ids );
		$comment_ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in})" );

		if ( ! $comment_ids ) {
			return 0;
		}

		$comment_in = self::id_list( $comment_ids );

		$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$comment_in})" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$comment_in})" );

		return count( $comment_ids );
	}

	/**
	 * Delete images attached to these products.
	 *
	 * This one goes through wp_delete_attachment() rather than SQL, because the
	 * files on disk and the generated thumbnail sizes need to go too.
	 *
	 * Only attachments whose post_parent is the product are touched, so images
	 * uploaded elsewhere in the media library and merely referenced by a product
	 * are left alone.
	 *
	 * @param int[] $ids     Product IDs.
	 * @param bool  $enabled Whether the user asked for this.
	 * @return int Number of attachments removed.
	 */
	private static function delete_attachments( array $ids, $enabled ) {
		global $wpdb;

		if ( ! $enabled ) {
			return 0;
		}

		$in             = self::id_list( $ids );
		$attachment_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent IN ({$in})" );

		if ( ! $attachment_ids ) {
			return 0;
		}

		$removed = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			if ( wp_delete_attachment( (int) $attachment_id, true ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Wrap up once every product is gone.
	 *
	 * @param array $job Job state, passed by reference so counters survive.
	 */
	private static function finalize( array &$job ) {
		global $wpdb;

		if ( $job['settings']['remove_terms'] ) {
			$job['terms'] = self::delete_product_terms();
		}

		// Every product is gone, so all product-scoped counts really are zero.
		// This runs either way: terms that were kept on purpose, such as the
		// default product category, would otherwise keep a stale count.
		$wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET count = 0 WHERE taxonomy LIKE 'product\\_%' OR taxonomy LIKE 'pa\\_%'" );

		self::sweep_orphans();
		self::flush_caches();

		/**
		 * Fires once a removal run has fully completed.
		 *
		 * @param array $job Final job state, including counters.
		 */
		do_action( 'wpr_removal_complete', $job );
	}

	/**
	 * Delete product categories, tags and attribute terms left with nothing attached.
	 *
	 * @return int Number of terms removed.
	 */
	private static function delete_product_terms() {
		global $wpdb;

		$taxonomies = self::term_taxonomies();

		if ( ! $taxonomies ) {
			return 0;
		}

		$taxonomy_in = "'" . implode( "','", array_map( 'esc_sql', $taxonomies ) ) . "'";

		// WooCommerce needs a default product category to fall back on, so it is
		// always kept. The option has held a term_taxonomy_id in some versions
		// and a term_id in others, so exclude both readings.
		$default = (int) get_option( 'default_product_cat', 0 );
		$exclude = $default ? " AND tt.term_id <> {$default} AND tt.term_taxonomy_id <> {$default}" : '';

		$rows = $wpdb->get_results(
			"SELECT tt.term_taxonomy_id, tt.term_id
			 FROM {$wpdb->term_taxonomy} tt
			 LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.taxonomy IN ({$taxonomy_in})
			   AND tr.object_id IS NULL
			   {$exclude}"
		);

		if ( ! $rows ) {
			return 0;
		}

		$term_taxonomy_ids = array();
		$term_ids          = array();

		foreach ( $rows as $row ) {
			$term_taxonomy_ids[] = (int) $row->term_taxonomy_id;
			$term_ids[]          = (int) $row->term_id;
		}

		$tt_in   = self::id_list( $term_taxonomy_ids );
		$term_in = self::id_list( array_unique( $term_ids ) );

		// Re-parent any children so no term is left pointing at a missing parent.
		$wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET parent = 0 WHERE parent IN ({$term_in})" );

		$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$tt_in})" );

		// A term row is shared between taxonomies, so only drop it once nothing
		// else refers to it.
		$wpdb->query(
			"DELETE t, tm FROM {$wpdb->terms} t
			 LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id
			 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE t.term_id IN ({$term_in}) AND tt.term_taxonomy_id IS NULL"
		);

		// WooCommerce's category hierarchy cache.
		self::query_if_table_exists(
			$wpdb->prefix . 'wc_category_lookup',
			"DELETE FROM {$wpdb->prefix}wc_category_lookup WHERE category_id IN ({$term_in}) OR category_tree_id IN ({$term_in})"
		);

		return count( $term_taxonomy_ids );
	}

	/**
	 * Catch any lookup rows whose product has already gone.
	 *
	 * Covers products deleted by something other than this plugin, and anything
	 * an interrupted earlier run left behind.
	 */
	private static function sweep_orphans() {
		global $wpdb;

		$prefix = $wpdb->prefix;

		$sweeps = array(
			$prefix . 'wc_product_meta_lookup'       => 'product_id',
			$prefix . 'wc_product_attributes_lookup' => 'product_id',
			$prefix . 'wc_reserved_stock'            => 'product_id',
			$prefix . 'wc_stock_notifications'       => 'product_id',
		);

		foreach ( $sweeps as $table => $column ) {
			self::query_if_table_exists(
				$table,
				"DELETE lookup FROM {$table} lookup
				 LEFT JOIN {$wpdb->posts} p ON p.ID = lookup.{$column}
				 WHERE p.ID IS NULL"
			);
		}
	}

	/**
	 * Drop every cached copy of the catalogue we just deleted behind WordPress's back.
	 */
	private static function flush_caches() {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		foreach ( array( 'wc_products_onsale', 'wc_featured_products', 'wc_outofstock_count', 'wc_low_stock_count', 'wc_term_counts' ) as $transient ) {
			delete_transient( $transient );
		}

		// Bumping the transient version invalidates every per-product transient
		// in one go, rather than deleting them row by row.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		// The deletes above went straight to MySQL, so the object cache is still
		// holding products that no longer exist.
		wp_cache_flush();
	}

	/**
	 * Turn a list of IDs into a safe comma separated SQL list.
	 *
	 * @param array $ids IDs.
	 * @return string
	 */
	private static function id_list( array $ids ) {
		return implode( ',', array_map( 'absint', $ids ) );
	}

	/**
	 * Run a query only if its table is present.
	 *
	 * @param string $table Table name.
	 * @param string $sql   Query.
	 */
	private static function query_if_table_exists( $table, $sql ) {
		global $wpdb;

		if ( self::table_exists( $table ) ) {
			$wpdb->query( $sql );
		}
	}

	/**
	 * Whether a table exists, cached per request.
	 *
	 * WooCommerce adds and retires tables between releases, and the plugin has
	 * to keep working on sites that never had some of them.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		static $cache = array();

		if ( ! isset( $cache[ $table ] ) ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			$cache[ $table ] = ( $found === $table );
		}

		return $cache[ $table ];
	}
}
