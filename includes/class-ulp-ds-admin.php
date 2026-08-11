<?php
/**
 * Admin: WooCommerce → Dropship Connect.
 *
 * - Partner management (label, WP user, shipping method/fallback, enable,
 *   partner payment emails) with per-partner webhook URL + secret shown for
 *   copy-paste into the partner's WooCommerce webhook screen.
 * - Simulate tool: paste a Woo order JSON payload to run the full pipeline
 *   (marked simulated) without a live webhook.
 * - Activity log + pending dropship orders overview.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Admin {

	const SLUG = 'ulp-dropship';

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_ulp_ds_save_partner', array( $this, 'handle_save_partner' ) );
		add_action( 'admin_post_ulp_ds_delete_partner', array( $this, 'handle_delete_partner' ) );
		add_action( 'admin_post_ulp_ds_simulate', array( $this, 'handle_simulate' ) );
		add_action( 'admin_post_ulp_ds_adjust_credit', array( $this, 'handle_adjust_credit' ) );
		add_action( 'admin_post_ulp_ds_capture', array( $this, 'handle_capture' ) );
		// "Webhook payload" box on dropship order edit screens (legacy + HPOS).
		add_action( 'add_meta_boxes', array( $this, 'register_payload_metabox' ), 10, 2 );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Dropship Connect', 'ulp-ds' ),
			__( 'Dropship Connect', 'ulp-ds' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	public function handle_save_partner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}
		check_admin_referer( 'ulp_ds_save_partner' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_key( isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : 'partner' );
		}

		$partners = ulp_ds_partners();
		$existing = isset( $partners[ $slug ] ) ? $partners[ $slug ] : array();

		$label_fee = isset( $_POST['label_fee'] ) ? sanitize_key( $_POST['label_fee'] ) : 'auto';
		if ( ! in_array( $label_fee, array( 'auto', 'always', 'never' ), true ) ) {
			$label_fee = 'auto';
		}

		// SKU map: parse "theirs = ours" lines; validate targets against our catalog.
		$sku_map_raw = isset( $_POST['sku_map'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sku_map'] ) ) : '';
		list( $sku_map, $sku_warnings ) = self::parse_sku_map( $sku_map_raw );

		// Shipping options: "token = Title | price" lines.
		$ship_options_raw = isset( $_POST['ship_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ship_options'] ) ) : '';
		list( $ship_options, $ship_warnings ) = self::parse_ship_options( $ship_options_raw );
		$sku_warnings = array_merge( $sku_warnings, $ship_warnings );

		$partners[ $slug ] = array(
			'label'          => sanitize_text_field( wp_unslash( isset( $_POST['label'] ) ? $_POST['label'] : $slug ) ),
			'user_id'        => absint( isset( $_POST['user_id'] ) ? $_POST['user_id'] : 0 ),
			'ship_method'    => sanitize_text_field( wp_unslash( isset( $_POST['ship_method'] ) ? $_POST['ship_method'] : '2-Day Shipping' ) ),
			'ship_fallback'  => (float) ( isset( $_POST['ship_fallback'] ) ? $_POST['ship_fallback'] : 15 ),
			'label_fee'      => $label_fee,
			'sku_map_raw'    => $sku_map_raw,
			'sku_map'        => $sku_map,
			'ship_meta_key'  => sanitize_text_field( wp_unslash( isset( $_POST['ship_meta_key'] ) ? $_POST['ship_meta_key'] : '' ) ),
			'ship_options_raw' => $ship_options_raw,
			'ship_options'   => $ship_options,
			'handling_fee'   => max( 0, (float) ( isset( $_POST['handling_fee'] ) ? $_POST['handling_fee'] : 0 ) ),
			'private_label_name' => sanitize_text_field( wp_unslash( isset( $_POST['private_label_name'] ) ? $_POST['private_label_name'] : '' ) ),
			'notify_emails'  => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', (string) wp_unslash( isset( $_POST['notify_emails'] ) ? $_POST['notify_emails'] : '' ) ) ) ) ),
			'zelle_sender'   => sanitize_text_field( wp_unslash( isset( $_POST['zelle_sender'] ) ? $_POST['zelle_sender'] : '' ) ),
			'wb_enabled'     => empty( $_POST['wb_enabled'] ) ? 0 : 1,
			'wb_url'         => esc_url_raw( wp_unslash( isset( $_POST['wb_url'] ) ? $_POST['wb_url'] : '' ) ),
			'wb_key'         => sanitize_text_field( wp_unslash( isset( $_POST['wb_key'] ) ? $_POST['wb_key'] : '' ) ),
			'wb_secret'      => sanitize_text_field( wp_unslash( isset( $_POST['wb_secret'] ) ? $_POST['wb_secret'] : '' ) ),
			'enabled'         => empty( $_POST['enabled'] ) ? 0 : 1,
			'notify_partner'  => empty( $_POST['notify_partner'] ) ? 0 : 1,
			'silence_nomatch' => empty( $_POST['silence_nomatch'] ) ? 0 : 1,
			'hold_covered'    => empty( $_POST['hold_covered'] ) ? 0 : 1,
			// Secret survives edits; generated once on creation.
			'secret'         => ! empty( $existing['secret'] ) ? $existing['secret'] : wp_generate_password( 32, false, false ),
			// Credit balance is managed only via the deposit form / order flow.
			'credit'         => isset( $existing['credit'] ) ? (float) $existing['credit'] : 0,
		);

		update_option( ULP_DS_PARTNERS_OPTION, $partners );
		ulp_ds_log( sprintf( 'Partner "%s" saved (user #%d, %d SKU mapping(s)).', $slug, $partners[ $slug ]['user_id'], count( $sku_map ) ) );

		$args = array( 'page' => self::SLUG, 'saved' => 1 );
		if ( ! empty( $sku_warnings ) ) {
			$args['sku_warn'] = rawurlencode( mb_substr( implode( ' | ', $sku_warnings ), 0, 400 ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Parse a "theirs = ours" SKU-map textarea.
	 *
	 * One mapping per line; blank lines and lines starting with # are ignored.
	 * Keys are stored lowercased/trimmed (lookups are case-insensitive);
	 * targets keep their exact casing. Targets are validated against our
	 * catalog so typos surface at save time, not at order time.
	 *
	 * @param string $raw
	 * @return array{0: array<string,string>, 1: string[]} [map, warnings]
	 */
	public static function parse_sku_map( $raw ) {
		$map      = array();
		$warnings = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( false === strpos( $line, '=' ) ) {
				$warnings[] = sprintf( 'Line %d has no "=" and was ignored: "%s"', $n + 1, mb_substr( $line, 0, 40 ) );
				continue;
			}
			list( $theirs, $ours ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $theirs || '' === $ours ) {
				$warnings[] = sprintf( 'Line %d is incomplete and was ignored.', $n + 1 );
				continue;
			}
			$key = strtolower( $theirs );
			if ( isset( $map[ $key ] ) ) {
				$warnings[] = sprintf( 'Duplicate mapping for "%s" — the later line wins.', $theirs );
			}
			$map[ $key ] = $ours;

			if ( function_exists( 'wc_get_product_id_by_sku' ) && ! wc_get_product_id_by_sku( $ours ) ) {
				$warnings[] = sprintf( 'Target SKU not found in our catalog: "%s" (mapped from "%s")', $ours, $theirs );
			}
		}

		return array( $map, $warnings );
	}

	/**
	 * Parse "token = Title | price" lines into token => [title, price].
	 * Tokens lowercased; blank/# lines ignored.
	 *
	 * @param string $raw
	 * @return array{0: array<string,array{title:string,price:float}>, 1: string[]} [options, warnings]
	 */
	public static function parse_ship_options( $raw ) {
		$options  = array();
		$warnings = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( false === strpos( $line, '=' ) ) {
				$warnings[] = sprintf( 'Shipping option line %d has no "=" and was ignored.', $n + 1 );
				continue;
			}
			list( $token, $rest ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$parts = array_map( 'trim', explode( '|', $rest, 2 ) );
			$title = isset( $parts[0] ) ? $parts[0] : '';
			$price = isset( $parts[1] ) ? $parts[1] : '';
			if ( '' === $token || '' === $title || '' === $price || ! is_numeric( $price ) ) {
				$warnings[] = sprintf( 'Shipping option line %d must be "token = Title | price" — ignored.', $n + 1 );
				continue;
			}
			$options[ strtolower( $token ) ] = array(
				'title' => $title,
				'price' => (float) max( 0, (float) $price ),
			);
		}

		return array( $options, $warnings );
	}

	public function handle_delete_partner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}
		check_admin_referer( 'ulp_ds_delete_partner' );

		$slug     = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';
		$partners = ulp_ds_partners();
		unset( $partners[ $slug ] );
		update_option( ULP_DS_PARTNERS_OPTION, $partners );
		ulp_ds_log( sprintf( 'Partner "%s" deleted.', $slug ) );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_adjust_credit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}
		check_admin_referer( 'ulp_ds_adjust_credit' );

		$slug   = isset( $_POST['partner'] ) ? sanitize_key( $_POST['partner'] ) : '';
		$amount = isset( $_POST['amount'] ) ? round( (float) $_POST['amount'], 2 ) : 0.0;
		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$result = null;
		if ( $slug && 0.0 !== $amount && ulp_ds_partner( $slug ) ) {
			$result = ulp_ds_adjust_credit( $slug, $amount, $note ? $note : ( $amount > 0 ? 'deposit received' : 'manual deduction' ) );
		}

		$args = array( 'page' => self::SLUG );
		if ( null !== $result && false !== $result ) {
			$args['credit_saved'] = rawurlencode( sprintf( '%s balance is now $%s', $slug, number_format( $result, 2 ) ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Arm, disarm, or clear the webhook payload inspector. */
	public function handle_capture() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}
		check_admin_referer( 'ulp_ds_capture' );

		$do = isset( $_POST['capture_do'] ) ? sanitize_key( $_POST['capture_do'] ) : '';
		if ( 'arm' === $do ) {
			$slug = isset( $_POST['partner'] ) ? sanitize_key( $_POST['partner'] ) : '';
			if ( $slug && ulp_ds_partner( $slug ) ) {
				update_option( 'ulp_ds_capture_armed', $slug, false );
				ulp_ds_log( sprintf( '[%s] Payload inspector armed — the next webhook delivery will be captured.', $slug ) );
			}
		} elseif ( 'disarm' === $do ) {
			delete_option( 'ulp_ds_capture_armed' );
		} elseif ( 'clear' === $do ) {
			delete_option( 'ulp_ds_capture_data' );
		} elseif ( 'clear_failed' === $do ) {
			delete_option( 'ulp_ds_failed_payloads' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_simulate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}
		check_admin_referer( 'ulp_ds_simulate' );

		$slug    = isset( $_POST['partner'] ) ? sanitize_key( $_POST['partner'] ) : '';
		$partner = ulp_ds_partner( $slug );
		$json    = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$payload = json_decode( $json, true );

		if ( ! $partner ) {
			$msg = 'Unknown partner.';
		} elseif ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
			$msg = 'Payload is not valid Woo order JSON (needs at least "id", "status", "line_items", "shipping").';
		} else {
			$receiver = new ULP_DS_Receiver();
			$result   = $receiver->process_order_payload( $slug, $partner, $payload, true );
			$msg      = wp_json_encode( $result );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'sim_result' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ulp-ds' ) );
		}

		$partners   = ulp_ds_partners();
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dropship Connect', 'ulp-ds' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Partner saved.', 'ulp-ds' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sku_warn'] ) ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'SKU map warnings:', 'ulp-ds' ); ?></strong> <?php echo esc_html( rawurldecode( $_GET['sku_warn'] ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Partner deleted.', 'ulp-ds' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sim_result'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><strong><?php esc_html_e( 'Simulation result:', 'ulp-ds' ); ?></strong> <code><?php echo esc_html( rawurldecode( $_GET['sim_result'] ) ); ?></code></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['credit_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Credit updated:', 'ulp-ds' ); ?></strong> <?php echo esc_html( rawurldecode( $_GET['credit_saved'] ) ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Partner stores send paid orders here via a standard WooCommerce webhook. Matching orders are created under the partner\'s wholesale account at their tier pricing, shipped to their end customer, and held in "Dropship – Awaiting Payment" (invisible to ShipStation) until you confirm payment and set them to Processing.', 'ulp-ds' ); ?>
			</p>

			<?php $this->render_pending(); ?>

			<h2><?php esc_html_e( 'Partners', 'ulp-ds' ); ?></h2>
			<?php if ( $partners ) : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<thead><tr>
						<th><?php esc_html_e( 'Partner', 'ulp-ds' ); ?></th>
						<th><?php esc_html_e( 'WP user', 'ulp-ds' ); ?></th>
						<th><?php esc_html_e( 'Shipping method / fallback', 'ulp-ds' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ulp-ds' ); ?></th>
						<th><?php esc_html_e( 'Webhook config (give to partner)', 'ulp-ds' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $partners as $slug => $p ) :
						$user = get_userdata( (int) $p['user_id'] );
						$hook = rest_url( 'ulp/v1/dropship/webhook/' . $slug );
					?>
						<tr>
							<td><strong><?php echo esc_html( $p['label'] ); ?></strong><br /><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo $user ? esc_html( $user->user_login . ' (#' . $p['user_id'] . ')' ) : '<span style="color:#b32d2e;">missing!</span>'; ?></td>
							<td><?php echo esc_html( $p['ship_method'] ); ?> / $<?php echo esc_html( number_format( (float) $p['ship_fallback'], 2 ) ); ?><br />
							<span class="description"><?php esc_html_e( 'Label fee:', 'ulp-ds' ); ?> <?php echo esc_html( isset( $p['label_fee'] ) ? $p['label_fee'] : 'auto' ); ?><?php if ( ! empty( $p['handling_fee'] ) ) { echo esc_html( ' · Handling: $' . number_format( (float) $p['handling_fee'], 2 ) ); } ?><?php if ( ! empty( $p['sku_map'] ) ) { echo esc_html( ' · ' . count( $p['sku_map'] ) . ' SKU map(s)' ); } ?><?php if ( ! empty( $p['ship_options'] ) ) { echo esc_html( ' · ' . count( $p['ship_options'] ) . ' ship option(s)' ); } ?></span><?php if ( ! empty( $p['private_label_name'] ) ) : ?><br /><span class="description"><?php esc_html_e( 'PL name:', 'ulp-ds' ); ?> <?php echo esc_html( $p['private_label_name'] ); ?></span><?php endif; ?></td>
							<td><?php echo $p['enabled'] ? '<span style="color:#00a32a;">enabled</span>' : '<span style="color:#b32d2e;">disabled</span>'; ?><br />
							<span class="description"><?php esc_html_e( 'Credit:', 'ulp-ds' ); ?> <strong>$<?php echo esc_html( number_format( (float) ( isset( $p['credit'] ) ? $p['credit'] : 0 ), 2 ) ); ?></strong></span></td>
							<td style="font-size:11px;">
								<?php esc_html_e( 'Delivery URL:', 'ulp-ds' ); ?> <code><?php echo esc_html( $hook ); ?></code><br />
								<?php esc_html_e( 'Secret:', 'ulp-ds' ); ?> <code><?php echo esc_html( $p['secret'] ); ?></code><br />
								<?php esc_html_e( 'Topic: Order updated', 'ulp-ds' ); ?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'edit' => $slug ), admin_url( 'admin.php' ) ) . '#ulp-ds-partner-form' ); ?>"><?php esc_html_e( 'Edit', 'ulp-ds' ); ?></a>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:4px;" onsubmit="return confirm('Delete this partner? Incoming webhooks will be rejected.');">
									<?php wp_nonce_field( 'ulp_ds_delete_partner' ); ?>
									<input type="hidden" name="action" value="ulp_ds_delete_partner" />
									<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
									<button type="submit" class="button-link" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'ulp-ds' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No partners yet — add the first one below.', 'ulp-ds' ); ?></em></p>
			<?php endif; ?>

			<?php
			// Edit mode: ?edit={slug} pre-fills the form with that partner.
			$edit_slug = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
			$editing   = $edit_slug ? ulp_ds_partner( $edit_slug ) : null;

			$f = wp_parse_args( is_array( $editing ) ? $editing : array(), array(
				'label'          => '',
				'user_id'        => '',
				'ship_method'    => '2-Day Shipping',
				'ship_fallback'  => 15,
				'label_fee'      => 'auto',
				'sku_map_raw'    => '',
				'ship_meta_key'  => '',
				'ship_options_raw' => '',
				'handling_fee'   => 0,
				'private_label_name' => '',
				'notify_emails'  => '',
				'zelle_sender'   => '',
				'wb_enabled'     => 0,
				'wb_url'         => '',
				'wb_key'         => '',
				'wb_secret'      => '',
				'enabled'        => 1,
				'notify_partner' => 1,
				'silence_nomatch' => 0,
				'hold_covered'   => 0,
			) );
			?>
			<h3 id="ulp-ds-partner-form" style="margin-top:1.5em;">
				<?php
				if ( $editing ) {
					printf( esc_html__( 'Edit partner: %s', 'ulp-ds' ), esc_html( $f['label'] ) );
					echo ' <a href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '" style="font-size:13px;font-weight:normal;">' . esc_html__( '(cancel — switch to add new)', 'ulp-ds' ) . '</a>';
				} else {
					esc_html_e( 'Add / update partner', 'ulp-ds' );
				}
				?>
			</h3>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="background:#fff;border:1px solid #c3c4c7;padding:1em;max-width:720px;">
				<?php wp_nonce_field( 'ulp_ds_save_partner' ); ?>
				<input type="hidden" name="action" value="ulp_ds_save_partner" />
				<table class="form-table"><tbody>
					<tr><th><?php esc_html_e( 'Label', 'ulp-ds' ); ?></th>
						<td><input type="text" name="label" class="regular-text" placeholder="TruFusion Labs (PepPro)" value="<?php echo esc_attr( $f['label'] ); ?>" required /></td></tr>
					<tr><th><?php esc_html_e( 'Slug (URL id)', 'ulp-ds' ); ?></th>
						<td><input type="text" name="slug" class="regular-text" placeholder="trufusion" value="<?php echo esc_attr( $edit_slug ); ?>" <?php echo $editing ? 'readonly style="background:#f0f0f1;"' : ''; ?> />
						<p class="description"><?php echo $editing
							? esc_html__( 'Locked while editing — the slug identifies this partner and their webhook URL.', 'ulp-ds' )
							: esc_html__( 'Lowercase letters/numbers/dashes. Re-use an existing slug to update that partner.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Wholesale WP user ID', 'ulp-ds' ); ?></th>
						<td><input type="number" name="user_id" style="width:120px;" placeholder="35" value="<?php echo esc_attr( $f['user_id'] ); ?>" required />
						<p class="description"><?php esc_html_e( 'Their orders are created under this account — its B2BKing tier, tax exemption, and billing details apply.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Shipping method title', 'ulp-ds' ); ?></th>
						<td><input type="text" name="ship_method" class="regular-text" value="<?php echo esc_attr( $f['ship_method'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Matched by name in the shipping zone for each destination.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Shipping fallback ($)', 'ulp-ds' ); ?></th>
						<td><input type="number" step="0.01" name="ship_fallback" style="width:120px;" value="<?php echo esc_attr( $f['ship_fallback'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Used (with an order note) when the method can\'t be auto-resolved. Editable per order before release.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Private Label Fee', 'ulp-ds' ); ?></th>
						<td>
							<select name="label_fee">
								<option value="auto" <?php selected( $f['label_fee'], 'auto' ); ?>><?php esc_html_e( 'Auto — follow user profile (like checkout)', 'ulp-ds' ); ?></option>
								<option value="always" <?php selected( $f['label_fee'], 'always' ); ?>><?php esc_html_e( 'Always charge', 'ulp-ds' ); ?></option>
								<option value="never" <?php selected( $f['label_fee'], 'never' ); ?>><?php esc_html_e( 'Never charge', 'ulp-ds' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '$1.75/unit on lines under 26 units (matches the checkout snippet, incl. its excluded products). "Auto" charges only when the partner\'s profile Product Label is "Private Label".', 'ulp-ds' ); ?></p>
						</td></tr>
					<tr><th><?php esc_html_e( 'SKU mappings', 'ulp-ds' ); ?></th>
						<td>
							<textarea name="sku_map" rows="6" style="width:100%;max-width:460px;font-family:monospace;font-size:12px;" placeholder="their-sku = our-sku&#10;Phych-Tir-40mg-V = TIR40mgV&#10;# comment lines start with #"><?php echo esc_textarea( $f['sku_map_raw'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. One mapping per line: the partner\'s SKU, an equals sign, then our SKU. SKUs with a mapping are translated before matching; SKUs without one must match ours directly (unchanged behavior). Their side is case-insensitive. Targets are validated against our catalog on save.', 'ulp-ds' ); ?></p>
						</td></tr>
					<tr><th><?php esc_html_e( 'Partner shipping options', 'ulp-ds' ); ?></th>
						<td>
							<input type="text" name="ship_meta_key" class="regular-text" placeholder="trufusion_shipping_method" value="<?php echo esc_attr( $f['ship_meta_key'] ); ?>" />
							<p class="description" style="margin:2px 0 6px;"><?php esc_html_e( 'Webhook meta key the partner sends their shipping choice in (underscores/case ignored when matching). Leave blank to keep zone-rate shipping only.', 'ulp-ds' ); ?></p>
							<textarea name="ship_options" rows="4" style="width:100%;max-width:460px;font-family:monospace;font-size:12px;" placeholder="2day = 2-Day Delivery | 25&#10;express = Express (Next-Day) Delivery | 50&#10;pickup = Pickup | 0"><?php echo esc_textarea( $f['ship_options_raw'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One option per line: token = Title | price. The token is what the partner sends; the Title becomes the order\'s shipping line (and ShipStation\'s Requested service); the price is OURS — their payload never sets prices. Missing/unknown token falls back to zone-rate shipping with an order note.', 'ulp-ds' ); ?></p>
						</td></tr>
					<tr><th><?php esc_html_e( 'Handling fee ($)', 'ulp-ds' ); ?></th>
						<td><input type="number" step="0.01" min="0" name="handling_fee" style="width:120px;" value="<?php echo esc_attr( $f['handling_fee'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Flat fee added to every dropship order from this partner. 0 = no fee.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Private Label Name', 'ulp-ds' ); ?></th>
						<td><input type="text" name="private_label_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Azoth Peptide', 'ulp-ds' ); ?>" value="<?php echo esc_attr( $f['private_label_name'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Sent to ShipStation as Custom Field 3 on this partner\'s dropship orders — usable in packing-slip templates and automation rules. Leave blank to send nothing.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Notification emails', 'ulp-ds' ); ?></th>
						<td><input type="text" name="notify_emails" class="regular-text" placeholder="<?php esc_attr_e( 'defaults to the partner account email', 'ulp-ds' ); ?>" value="<?php echo esc_attr( $f['notify_emails'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Where the "amount due" and tracking emails go. Leave blank to use the partner account email; enter one or more addresses (comma-separated) to send there instead — include the account email too if both should receive them.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Zelle sender name(s)', 'ulp-ds' ); ?></th>
						<td><input type="text" name="zelle_sender" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Hayton Oei, Azoth Peptide', 'ulp-ds' ); ?>" value="<?php echo esc_attr( $f['zelle_sender'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional but recommended. Incoming Zelle payments are auto-matched to this partner\'s held orders by exact amount due; if names are set here (comma-separated), the payment\'s sender must also match one of them. Blank = match by amount alone.', 'ulp-ds' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Shipping write-back', 'ulp-ds' ); ?></th>
						<td>
							<label><input type="checkbox" name="wb_enabled" value="1" <?php checked( ! empty( $f['wb_enabled'] ) ); ?> /> <?php esc_html_e( 'Push tracking + Completed status to the partner\'s store when we ship', 'ulp-ds' ); ?></label><br />
							<input type="url" name="wb_url" class="regular-text" style="margin-top:6px;" placeholder="https://azothpeptide.com" value="<?php echo esc_attr( $f['wb_url'] ); ?>" />
							<p class="description" style="margin:2px 0 6px;"><?php esc_html_e( 'Partner store URL (https).', 'ulp-ds' ); ?></p>
							<input type="text" name="wb_key" class="regular-text" placeholder="ck_..." value="<?php echo esc_attr( $f['wb_key'] ); ?>" autocomplete="off" />
							<p class="description" style="margin:2px 0 6px;"><?php esc_html_e( 'Their REST API Consumer key (Read/Write).', 'ulp-ds' ); ?></p>
							<input type="password" name="wb_secret" class="regular-text" placeholder="cs_..." value="<?php echo esc_attr( $f['wb_secret'] ); ?>" autocomplete="new-password" />
							<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Their REST API Consumer secret. On ship: a PRIVATE note (carrier, tracking, link — no email) is added to their order, then it\'s marked Completed with tracking meta (_ulp_tracking_number/_provider/_url) — their store\'s Completed email goes to their customer. Fails retry 3× then alert.', 'ulp-ds' ); ?></p>
						</td></tr>
					<tr><th><?php esc_html_e( 'Options', 'ulp-ds' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $f['enabled'] ) ); ?> /> <?php esc_html_e( 'Enabled', 'ulp-ds' ); ?></label><br />
							<label><input type="checkbox" name="notify_partner" value="1" <?php checked( ! empty( $f['notify_partner'] ) ); ?> /> <?php esc_html_e( 'Email partner the amount due on each new order', 'ulp-ds' ); ?></label><br />
							<label><input type="checkbox" name="silence_nomatch" value="1" <?php checked( ! empty( $f['silence_nomatch'] ) ); ?> /> <?php esc_html_e( 'Silence "no matching SKUs" alert emails (for partners who also sell products we don\'t carry — the activity log entry always remains)', 'ulp-ds' ); ?></label><br />
							<label><input type="checkbox" name="hold_covered" value="1" <?php checked( ! empty( $f['hold_covered'] ) ); ?> /> <?php esc_html_e( 'Hold orders even when fully covered by deposit credit (credit still applies, but release stays manual)', 'ulp-ds' ); ?></label>
						</td></tr>
				</tbody></table>
				<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save changes', 'ulp-ds' ) : esc_html__( 'Save partner', 'ulp-ds' ); ?></button>
			</form>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Partner deposit credit', 'ulp-ds' ); ?></h2>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Record deposits partners have pre-paid. Every incoming dropship order automatically applies available credit: fully covered orders skip the payment hold and release to fulfillment immediately; partially covered orders stay held and the partner is emailed only the remaining amount due. Trashing or cancelling an order returns the credit it used. Every movement appears in the activity log.', 'ulp-ds' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="background:#fff;border:1px solid #c3c4c7;padding:1em;max-width:720px;">
				<?php wp_nonce_field( 'ulp_ds_adjust_credit' ); ?>
				<input type="hidden" name="action" value="ulp_ds_adjust_credit" />
				<select name="partner" required>
					<option value=""><?php esc_html_e( '— partner —', 'ulp-ds' ); ?></option>
					<?php foreach ( $partners as $slug => $p ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $p['label'] . ' — balance $' . number_format( (float) ( isset( $p['credit'] ) ? $p['credit'] : 0 ), 2 ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" step="0.01" name="amount" style="width:130px;" placeholder="<?php esc_attr_e( 'amount', 'ulp-ds' ); ?>" required />
				<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'note, e.g. "Zelle deposit 7/26"', 'ulp-ds' ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'ulp-ds' ); ?></button>
				<p class="description"><?php esc_html_e( 'Positive amount adds credit (deposit received); negative deducts (correction/refund).', 'ulp-ds' ); ?></p>
			</form>

			<?php $this->render_capture( $partners, $action_url ); ?>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Simulate an incoming order (test tool)', 'ulp-ds' ); ?></h2>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Paste a WooCommerce order JSON payload (from the partner store\'s REST API or webhook log) and run the full pipeline without a live webhook. The created order is tagged SIMULATED — cancel/delete it afterwards.', 'ulp-ds' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'ulp_ds_simulate' ); ?>
				<input type="hidden" name="action" value="ulp_ds_simulate" />
				<select name="partner" required>
					<option value=""><?php esc_html_e( '— partner —', 'ulp-ds' ); ?></option>
					<?php foreach ( $partners as $slug => $p ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $p['label'] ); ?></option>
					<?php endforeach; ?>
				</select><br />
				<textarea name="payload" rows="8" style="width:100%;max-width:760px;font-family:monospace;font-size:12px;" placeholder='{"id":9001,"number":"9001","status":"processing","line_items":[{"sku":"YOUR-SKU","quantity":3}],"shipping":{"first_name":"Test","last_name":"Customer","address_1":"123 Main St","city":"Austin","state":"TX","postcode":"78701","country":"US"},"billing":{"email":"end.customer@example.com"}}'></textarea><br />
				<button type="submit" class="button"><?php esc_html_e( 'Run simulation', 'ulp-ds' ); ?></button>
			</form>

			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/** Register the "Webhook payload" box on orders that carry a payload. */
	public function register_payload_metabox( $post_type, $post = null ) {
		if ( ! in_array( $post_type, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$order = null;
		if ( $post instanceof WP_Post ) {
			$order = wc_get_order( $post->ID );
		} elseif ( is_object( $post ) && method_exists( $post, 'get_id' ) ) {
			$order = wc_get_order( $post->get_id() );
		}
		if ( ! $order || '' === (string) $order->get_meta( '_ulp_ds_raw_payload' ) ) {
			return;
		}

		add_meta_box(
			'ulp-ds-payload',
			__( 'Dropship webhook payload', 'ulp-ds' ),
			array( $this, 'render_payload_metabox' ),
			$post_type,
			'normal',
			'low'
		);
	}

	/** Render the stored raw payload, pretty-printed, collapsed by default. */
	public function render_payload_metabox( $post ) {
		$order_id = ( $post instanceof WP_Post ) ? $post->ID : ( is_object( $post ) && method_exists( $post, 'get_id' ) ? $post->get_id() : 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$raw      = $order ? (string) $order->get_meta( '_ulp_ds_raw_payload' ) : '';
		if ( '' === $raw ) {
			echo '<p>' . esc_html__( 'No payload stored for this order.', 'ulp-ds' ) . '</p>';
			return;
		}
		$decoded = json_decode( $raw );
		$display = ( null !== $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $raw;
		echo '<details><summary style="cursor:pointer;font-weight:600;">' . esc_html( sprintf(
			/* translators: %s: byte count */
			__( 'Show raw payload (%s bytes) — exactly what the partner store sent when this order was created', 'ulp-ds' ),
			number_format( strlen( $raw ) )
		) ) . '</summary>';
		echo '<pre style="overflow:auto;max-height:480px;font-size:11px;line-height:1.5;background:#f6f7f7;padding:8px;">' . esc_html( $display ) . '</pre>';
		echo '</details>';
	}

	/** Webhook payload inspector: arm a one-shot raw capture + show the result. */
	private function render_capture( $partners, $action_url ) {
		$armed    = (string) get_option( 'ulp_ds_capture_armed', '' );
		$captured = get_option( 'ulp_ds_capture_data', null );

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Webhook payload inspector', 'ulp-ds' ) . '</h2>';
		echo '<p class="description" style="max-width:820px;">' . esc_html__( 'Arm the inspector, then have the partner trigger a webhook (re-save an order). The next real delivery\'s FULL raw body is captured for inspection — pings are skipped, and capture happens before signature checks so even rejected deliveries can be examined. One capture at a time; arming disarms automatically after the catch.', 'ulp-ds' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $action_url ) . '" style="margin-bottom:0.8em;">';
		wp_nonce_field( 'ulp_ds_capture' );
		echo '<input type="hidden" name="action" value="ulp_ds_capture" />';
		if ( $armed ) {
			echo '<p><strong style="color:#996800;">' . esc_html( sprintf( __( 'Armed — waiting for the next "%s" delivery.', 'ulp-ds' ), $armed ) ) . '</strong> ';
			echo '<button type="submit" name="capture_do" value="disarm" class="button button-small">' . esc_html__( 'Disarm', 'ulp-ds' ) . '</button></p>';
		} else {
			echo '<select name="partner" required><option value="">' . esc_html__( '— partner —', 'ulp-ds' ) . '</option>';
			foreach ( $partners as $slug => $p ) {
				echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $p['label'] ) . '</option>';
			}
			echo '</select> ';
			echo '<button type="submit" name="capture_do" value="arm" class="button">' . esc_html__( 'Capture next webhook', 'ulp-ds' ) . '</button>';
		}
		echo '</form>';

		if ( is_array( $captured ) && ! empty( $captured['body'] ) ) {
			$pretty  = json_decode( $captured['body'] );
			$display = ( null !== $pretty ) ? wp_json_encode( $pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $captured['body'];
			echo '<details style="background:#fff;border:1px solid #c3c4c7;padding:0.6em 1em;max-width:980px;">';
			echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html( sprintf(
				/* translators: 1 partner, 2 time, 3 bytes, 4 topic */
				__( 'Captured payload: %1$s at %2$s (%3$d bytes, topic: %4$s)', 'ulp-ds' ),
				$captured['slug'], $captured['time'], $captured['bytes'], $captured['topic'] ?: '?'
			) ) . '</summary>';
			echo '<pre style="overflow:auto;max-height:480px;font-size:11px;line-height:1.5;">' . esc_html( $display ) . '</pre>';
			echo '<form method="post" action="' . esc_url( $action_url ) . '">';
			wp_nonce_field( 'ulp_ds_capture' );
			echo '<input type="hidden" name="action" value="ulp_ds_capture" />';
			echo '<button type="submit" name="capture_do" value="clear" class="button button-small">' . esc_html__( 'Clear captured payload', 'ulp-ds' ) . '</button>';
			echo '</form></details>';
		}

		// Bounded buffer of the last 5 FAILED import payloads (no local order
		// exists for these, so this is their only record). Successful imports
		// keep their full payload on the order itself (_ulp_ds_raw_payload).
		$failed = get_option( 'ulp_ds_failed_payloads', array() );
		if ( ! empty( $failed ) && is_array( $failed ) ) {
			echo '<h3 style="margin-top:1em;">' . esc_html__( 'Failed import payloads (last 5)', 'ulp-ds' ) . '</h3>';
			foreach ( array_reverse( $failed ) as $entry ) {
				$pretty  = json_decode( isset( $entry['body'] ) ? $entry['body'] : '' );
				$display = ( null !== $pretty ) ? wp_json_encode( $pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : (string) ( isset( $entry['body'] ) ? $entry['body'] : '' );
				echo '<details style="background:#fff;border:1px solid #c3c4c7;padding:0.5em 1em;margin:0.4em 0;max-width:980px;">';
				echo '<summary style="cursor:pointer;">' . esc_html( sprintf(
					'%s — [%s] their #%s — %s',
					isset( $entry['time'] ) ? $entry['time'] : '?',
					isset( $entry['slug'] ) ? $entry['slug'] : '?',
					isset( $entry['origin'] ) ? $entry['origin'] : '?',
					isset( $entry['error'] ) ? $entry['error'] : '?'
				) ) . '</summary>';
				echo '<pre style="overflow:auto;max-height:360px;font-size:11px;line-height:1.5;">' . esc_html( $display ) . '</pre>';
				echo '</details>';
			}
			echo '<form method="post" action="' . esc_url( $action_url ) . '">';
			wp_nonce_field( 'ulp_ds_capture' );
			echo '<input type="hidden" name="action" value="ulp_ds_capture" />';
			echo '<button type="submit" name="capture_do" value="clear_failed" class="button button-small">' . esc_html__( 'Clear failed payloads', 'ulp-ds' ) . '</button>';
			echo '</form>';
		}

		$this->render_recent_payloads();
	}

	/** Per-order payload dropdowns for the most recent imported orders. */
	private function render_recent_payloads() {
		$orders = wc_get_orders( array(
			'limit'    => 10,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'meta_key' => '_ulp_ds_raw_payload',
		) );
		if ( empty( $orders ) ) {
			return;
		}

		echo '<h3 style="margin-top:1em;">' . esc_html__( 'Recent order payloads (last 10 imported)', 'ulp-ds' ) . '</h3>';
		foreach ( $orders as $order ) {
			$raw = (string) $order->get_meta( '_ulp_ds_raw_payload' );
			if ( '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw );
			$display = ( null !== $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $raw;
			echo '<details style="background:#fff;border:1px solid #c3c4c7;padding:0.5em 1em;margin:0.4em 0;max-width:980px;">';
			echo '<summary style="cursor:pointer;">';
			printf(
				'<a href="%s">#%s</a> — %s — %s (their #%s)%s',
				esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ),
				esc_html( $order->get_order_number() ),
				esc_html( $order->get_meta( '_ulp_ds_partner' ) ),
				esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '?' ),
				esc_html( $order->get_meta( '_ulp_ds_origin_order' ) ),
				$order->get_meta( '_ulp_ds_simulated' ) ? ' <em>(simulated)</em>' : ''
			);
			echo '</summary>';
			echo '<pre style="overflow:auto;max-height:360px;font-size:11px;line-height:1.5;background:#f6f7f7;padding:8px;">' . esc_html( $display ) . '</pre>';
			echo '</details>';
		}
	}

	/** Dropship orders currently awaiting payment. */
	private function render_pending() {
		$orders = wc_get_orders( array(
			'status' => array( 'wc-' . ULP_DS_STATUS ),
			'limit'  => 20,
		) );

		if ( empty( $orders ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Awaiting payment / release:', 'ulp-ds' ) . '</strong></p><ul style="margin:0.4em 0 0.8em 1.4em;list-style:disc;">';
		foreach ( $orders as $order ) {
			$total  = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
			$credit = (float) $order->get_meta( '_ulp_ds_credit_applied' );
			$credit_info = $credit > 0
				? sprintf( ' — credit $%s applied, $%s due', number_format( $credit, 2 ), number_format( max( 0, (float) $order->get_total() - $credit ), 2 ) )
				: '';
			printf(
				'<li><a href="%s">#%s</a> — %s — %s (their #%s)%s%s</li>',
				esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ),
				esc_html( $order->get_order_number() ),
				esc_html( $order->get_meta( '_ulp_ds_partner' ) ),
				esc_html( $total ),
				esc_html( $order->get_meta( '_ulp_ds_origin_order' ) ),
				esc_html( $credit_info ),
				$order->get_meta( '_ulp_ds_simulated' ) ? ' <em>(simulated)</em>' : ''
			);
		}
		echo '</ul></div>';
	}

	private function render_log() {
		global $wpdb;
		$table    = ulp_ds_log_table();
		$per_page = 100;
		$page     = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $total ) {
			return;
		}
		$pages = (int) ceil( $total / $per_page );
		$page  = min( $page, $pages );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT log_time, level, message FROM ' . $table . ' ORDER BY id DESC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$per_page,
			( $page - 1 ) * $per_page
		), ARRAY_A );

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Activity log', 'ulp-ds' ) . ' <span style="font-weight:normal;font-size:13px;">' . esc_html( sprintf( __( '(%1$s entries — page %2$d of %3$d)', 'ulp-ds' ), number_format_i18n( $total ), $page, $pages ) ) . '</span></h2>';
		echo '<table class="widefat striped" style="max-width:980px;"><tbody>';
		foreach ( (array) $rows as $entry ) {
			$color = ( 'error' === $entry['level'] ) ? ' style="color:#b32d2e;"' : '';
			echo '<tr><td style="width:170px;">' . esc_html( $entry['log_time'] ) . '</td><td' . $color . '>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			$base = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
			echo '<p style="margin-top:0.6em;">';
			if ( $page > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'log_page', $page - 1, $base ) ) . '">' . esc_html__( '← Newer', 'ulp-ds' ) . '</a> ';
			}
			if ( $page < $pages ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'log_page', $page + 1, $base ) ) . '">' . esc_html__( 'Older →', 'ulp-ds' ) . '</a>';
			}
			echo '</p>';
		}
	}
}
