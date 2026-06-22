<?php
/**
 * Product mapping for the commission engine.
 *
 * Maps WooCommerce product IDs (including variation IDs) to internal
 * commission categories stored in wp_konx_product_map.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Konx_Product_Mapper
 */
class Konx_Product_Mapper {

	/**
	 * Valid internal product categories.
	 *
	 * @var array slug => label
	 */
	private static $categories = array(
		'starter_pack'          => 'Starter Pack',
		'pro_pack'              => 'Pro Pack',
		'ecard_pack'            => 'eCard Pack',
		'basic_pro_conference'  => 'Basic Pro Conference Room',
		'business_conference'   => 'Business Conference Room',
		'corporate_conference'  => 'Corporate Conference Room',
		'enterprise_conference' => 'Enterprise Conference Room',
	);

	// ------------------------------------------------------------------
	// Write
	// ------------------------------------------------------------------

	/**
	 * Map a WooCommerce product to an internal commission category.
	 *
	 * Inserts a new mapping or updates the existing one if the
	 * product_id is already mapped.
	 *
	 * @param int    $product_id      WooCommerce product ID or variation ID.
	 * @param string $product_type    Internal category slug.
	 * @param string $product_label   Human-readable label for admin display.
	 * @param bool   $is_subscription Whether this product has recurring billing.
	 * @return int|WP_Error The mapping row ID, or WP_Error on failure.
	 */
	public static function map_product( $product_id, $product_type, $product_label = '', $is_subscription = false ) {
		global $wpdb;

		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return new \WP_Error( 'invalid_product_id', __( 'Invalid product ID.', 'konx-affiliate-dashboard' ) );
		}

		$validation = self::validate_mapping( $product_id, $product_type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$product_type = sanitize_text_field( $product_type );
		$table        = $wpdb->prefix . 'konx_product_map';
		$now          = current_time( 'mysql', true );

		if ( '' === $product_label ) {
			$wc_product = wc_get_product( $product_id );
			$product_label = $wc_product ? $wc_product->get_name() : sprintf( 'Product #%d', $product_id );
		}
		$product_label = sanitize_text_field( $product_label );

		// Check for existing mapping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d", $product_id )
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'product_type'    => $product_type,
					'product_label'   => $product_label,
					'is_subscription' => $is_subscription ? 1 : 0,
					'is_active'       => 1,
					'updated_at'      => $now,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);

			return (int) $existing->id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'product_id'      => $product_id,
				'product_type'    => $product_type,
				'product_label'   => $product_label,
				'is_subscription' => $is_subscription ? 1 : 0,
				'is_active'       => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_insert_failed', __( 'Failed to save product mapping.', 'konx-affiliate-dashboard' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a product mapping by product ID.
	 *
	 * Performs a hard delete from the table.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool True on success, false on failure.
	 */
	public static function remove_mapping( $product_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_product_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array( 'product_id' => absint( $product_id ) ),
			array( '%d' )
		);

		return false !== $result;
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Get the internal product category for a WooCommerce order line item.
	 *
	 * Checks variation ID first (for variable products), then falls
	 * back to the parent product ID.
	 *
	 * @param int $product_id   The product ID from $item->get_product_id().
	 * @param int $variation_id The variation ID from $item->get_variation_id().
	 * @return object|null The mapping row (product_type, is_subscription, etc.), or null.
	 */
	public static function get_product_category( $product_id, $variation_id = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_product_map';

		// Check variation first.
		if ( $variation_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapping = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d AND is_active = 1",
					absint( $variation_id )
				)
			);
			if ( $mapping ) {
				return $mapping;
			}
		}

		// Fall back to parent product.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND is_active = 1",
				absint( $product_id )
			)
		);
	}

	/**
	 * Get all active product mappings.
	 *
	 * @return array Array of mapping row objects.
	 */
	public static function get_all_mappings() {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_product_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY product_type ASC, product_label ASC" );
	}

	/**
	 * Get a single mapping row by its ID.
	 *
	 * @param int $mapping_id The mapping table ID.
	 * @return object|null The mapping row, or null.
	 */
	public static function get_mapping( $mapping_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'konx_product_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $mapping_id ) )
		);
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Validate a product mapping before saving.
	 *
	 * Checks that the product exists in WooCommerce and the category
	 * is a recognized internal type.
	 *
	 * @param int    $product_id   WooCommerce product ID or variation ID.
	 * @param string $product_type Internal category slug.
	 * @return true|WP_Error True if valid, WP_Error with details if not.
	 */
	public static function validate_mapping( $product_id, $product_type ) {
		if ( ! in_array( $product_type, array_keys( self::$categories ), true ) ) {
			return new \WP_Error(
				'invalid_category',
				sprintf(
					/* translators: %s: the invalid category slug */
					__( 'Invalid product category: %s', 'konx-affiliate-dashboard' ),
					$product_type
				)
			);
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->exists() ) {
				return new \WP_Error(
					'product_not_found',
					sprintf(
						/* translators: %d: the product ID */
						__( 'WooCommerce product #%d not found.', 'konx-affiliate-dashboard' ),
						$product_id
					)
				);
			}
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Get all valid product category slugs and their labels.
	 *
	 * @return array slug => label.
	 */
	public static function get_categories() {
		return self::$categories;
	}

	/**
	 * Get all valid product category slugs.
	 *
	 * @return array Indexed array of slug strings.
	 */
	public static function get_category_slugs() {
		return array_keys( self::$categories );
	}
}
