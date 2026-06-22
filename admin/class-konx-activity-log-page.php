<?php
/**
 * Activity Log admin page.
 *
 * Displays the wp_konx_audit_log table with filtering, search,
 * and pagination. Provides a complete audit trail of all system
 * and admin actions.
 *
 * @package KonxAffiliateDashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Konx_Activity_Log_Page {

	public static function init() {
		// Menu registered by Konx_Tools_Page.
	}

	public static function register_menu() {
		add_submenu_page(
			'konx-affiliate-dashboard',
			__( 'Activity Log', 'konx-affiliate-dashboard' ),
			__( 'Activity Log', 'konx-affiliate-dashboard' ),
			'manage_konx_settings',
			'konx-activity-log',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_konx_settings' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'konx-affiliate-dashboard' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Activity Log', 'konx-affiliate-dashboard' ) . '</h1>';
		self::render_content();
		echo '</div>';
	}

	/** Render inner content (used by Tools page). */
	public static function render_content() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$event_filter = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged        = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable

		$result = self::query_logs( $event_filter, $search, $paged );
		$events = self::get_event_types();

		$icons = array(
			'affiliate'  => 'dashicons-admin-users',
			'commission' => 'dashicons-money-alt',
			'withdrawal' => 'dashicons-migrate',
			'milestone'  => 'dashicons-star-filled',
			'admin_fee'  => 'dashicons-clipboard',
			'order'      => 'dashicons-cart',
			'settings'   => 'dashicons-admin-generic',
		);

		?>
			<div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
				<a href="<?php echo esc_url( Konx_Export_Manager::get_export_url( 'affiliates' ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'konx-affiliate-dashboard' ); ?></a>
			</div>

			<!-- Filters -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="konx-activity-log">
				<div class="konx-filters">
					<select name="event">
						<option value=""><?php esc_html_e( 'All Events', 'konx-affiliate-dashboard' ); ?></option>
						<?php foreach ( $events as $val ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $event_filter, $val ); ?>>
								<?php echo esc_html( ucwords( str_replace( '_', ' ', $val ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search description...', 'konx-affiliate-dashboard' ); ?>">
					<?php submit_button( __( 'Filter', 'konx-affiliate-dashboard' ), 'secondary', '', false ); ?>
				</div>
			</form>

			<?php if ( empty( $result['entries'] ) ) : ?>
				<div class="konx-empty-state">
					<span class="dashicons dashicons-backup"></span>
					<p><?php esc_html_e( 'No activity log entries found.', 'konx-affiliate-dashboard' ); ?></p>
				</div>
			<?php else : ?>
				<div class="konx-table-wrap">
					<table class="widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" style="width:50px;"><?php esc_html_e( 'ID', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:160px;"><?php esc_html_e( 'Date', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:220px;"><?php esc_html_e( 'Event', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:100px;"><?php esc_html_e( 'Object', 'konx-affiliate-dashboard' ); ?></th>
								<th scope="col" style="width:100px;"><?php esc_html_e( 'Actor', 'konx-affiliate-dashboard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['entries'] as $log ) : ?>
								<?php
								$icon = isset( $icons[ $log->object_type ] ) ? $icons[ $log->object_type ] : 'dashicons-info-outline';
								$actor = $log->actor_id ? get_userdata( $log->actor_id ) : null;
								?>
								<tr>
									<td><?php echo esc_html( $log->id ); ?></td>
									<td><?php echo esc_html( date_i18n( 'M j, H:i', strtotime( $log->created_at ) ) ); ?></td>
									<td>
										<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:14px;width:14px;height:14px;margin-right:4px;color:#646970;vertical-align:middle;"></span>
										<span class="konx-badge konx-badge-<?php echo esc_attr( self::event_badge_type( $log->event_type ) ); ?>">
											<?php echo esc_html( ucwords( str_replace( '_', ' ', $log->event_type ) ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $log->description ); ?></td>
									<td>
										<?php if ( $log->object_id ) : ?>
											<?php echo esc_html( ucfirst( $log->object_type ) ); ?> #<?php echo esc_html( $log->object_id ); ?>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td><?php echo $actor ? esc_html( $actor->display_name ) : esc_html__( 'System', 'konx-affiliate-dashboard' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $result['pages'] > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post( paginate_links( array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $result['pages'],
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function query_logs( $event, $search, $page ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'konx_audit_log';
		$per_page = 30;
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $event ) ) {
			$where   .= ' AND event_type = %s';
			$params[] = $event;
		}

		if ( ! empty( $search ) ) {
			$where   .= ' AND description LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$select_params   = $params;
		$select_params[] = $per_page;
		$select_params[] = $offset;

		$sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries = $wpdb->get_results( $wpdb->prepare( $sql, $select_params ) );

		return array(
			'entries' => $entries ?: array(),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
		);
	}

	private static function get_event_types() {
		global $wpdb;
		$table = $wpdb->prefix . 'konx_audit_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type" );
		return $types ?: array();
	}

	private static function event_badge_type( $event_type ) {
		if ( strpos( $event_type, 'created' ) !== false || strpos( $event_type, 'awarded' ) !== false || strpos( $event_type, 'paid' ) !== false ) {
			return 'approved';
		}
		if ( strpos( $event_type, 'reversed' ) !== false || strpos( $event_type, 'failed' ) !== false || strpos( $event_type, 'rejected' ) !== false ) {
			return 'rejected';
		}
		if ( strpos( $event_type, 'blocked' ) !== false || strpos( $event_type, 'overdue' ) !== false ) {
			return 'blocked';
		}
		return 'pending';
	}
}
