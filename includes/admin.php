<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'image_compressor_load_textdomain' );
add_action( 'admin_enqueue_scripts', 'image_compressor_enqueue_admin_assets' );
add_action( 'admin_init', 'image_compressor_register_settings' );
add_action( 'admin_menu', 'image_compressor_add_options_page' );
add_action( 'admin_menu', 'image_compressor_add_bulk_optimize_page' );
add_action( 'admin_init', 'image_compressor_maybe_reset_stats' );

/**
 * @return void
 */
function image_compressor_load_textdomain() {
	load_plugin_textdomain(
		'image-compressor',
		false,
		dirname( IMAGE_COMPRESSOR_BASENAME ) . '/languages'
	);
}

/**
 * @param string $hook_suffix
 * @return void
 */
function image_compressor_enqueue_admin_assets( $hook_suffix ) {
	$allowed_hooks = array(
		'settings_page_image-compressor',
		'media_page_ic-bulk-optimize',
		'upload.php',
	);

	if ( ! in_array( $hook_suffix, $allowed_hooks, true ) ) {
		return;
	}

	wp_enqueue_style(
		'image-compressor-admin',
		IMAGE_COMPRESSOR_URL . 'assets/css/admin.css',
		array(),
		IMAGE_COMPRESSOR_VERSION
	);

	wp_enqueue_script(
		'image-compressor-admin',
		IMAGE_COMPRESSOR_URL . 'assets/js/admin.js',
		array(),
		IMAGE_COMPRESSOR_VERSION,
		true
	);

	$lib           = image_compressor_get_library_stats();
	$restore_stats = image_compressor_get_restore_stats();

	wp_localize_script(
		'image-compressor-admin',
		'imageCompressorAdmin',
		array(
			'optimize' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'image_compressor_bulk_optimize_batch',
				'nonce'   => wp_create_nonce( 'image_compressor_bulk_optimize' ),
				'counts'  => array(
					'total'      => (int) $lib['pending'],
					'completed'  => 0,
					'checked'    => (int) $lib['skipped'],
					'optimized'  => (int) $lib['optimized'],
					'skipped'    => (int) $lib['skipped'],
					'pending'    => (int) $lib['pending'],
				),
				'strings' => array(
					'starting'      => __( 'Starting bulk optimization...', 'image-compressor' ),
					'inProgress'    => __( 'Processing %1$s/%2$s images...', 'image-compressor' ),
					'etaPending'    => __( 'Estimated time will appear after the first batch finishes.', 'image-compressor' ),
					'etaLabel'      => __( 'Estimated time remaining: %s', 'image-compressor' ),
					'finished'      => __( 'Bulk optimization complete.', 'image-compressor' ),
					'buttonRunning' => __( 'Processing...', 'image-compressor' ),
					'buttonDone'    => __( 'Bulk Optimization Complete', 'image-compressor' ),
					'buttonStart'   => __( 'Start Bulk Optimization', 'image-compressor' ),
					'complete'      => __( 'Done! %1$s image(s) optimized, %2$s KB saved, %3$s skipped, %4$s error(s).', 'image-compressor' ),
					'error'         => __( 'Bulk optimization stopped before finishing. Please try again.', 'image-compressor' ),
				),
			),
			'restore'  => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'image_compressor_restore_all_batch',
				'nonce'   => wp_create_nonce( 'image_compressor_restore_all' ),
				'counts'  => array(
					'total'      => (int) $restore_stats['total'],
					'completed'  => 0,
					'remaining'  => (int) $restore_stats['remaining'],
				),
				'strings' => array(
					'starting'      => __( 'Starting restore...', 'image-compressor' ),
					'inProgress'    => __( 'Restoring %1$s/%2$s images...', 'image-compressor' ),
					'etaPending'    => __( 'Estimated time will appear after the first batch finishes.', 'image-compressor' ),
					'etaLabel'      => __( 'Estimated time remaining: %s', 'image-compressor' ),
					'finished'      => __( 'Restore complete.', 'image-compressor' ),
					'buttonRunning' => __( 'Restoring...', 'image-compressor' ),
					'buttonDone'    => __( 'Restore Complete', 'image-compressor' ),
					'buttonStart'   => __( 'Restore All Originals', 'image-compressor' ),
					'complete'      => __( 'Restore finished: %1$s image(s) restored to original, %2$s error(s).', 'image-compressor' ),
					'error'         => __( 'Restore stopped before finishing. Please try again.', 'image-compressor' ),
				),
			),
		)
	);
}

/**
 * @return void
 */
function image_compressor_register_settings() {
	register_setting(
		'image_compressor_settings',
		'image_compressor_output_format',
		array(
			'type'              => 'string',
			'default'           => 'webp',
			'sanitize_callback' => 'image_compressor_sanitize_output_format',
		)
	);

	register_setting(
		'image_compressor_settings',
		'image_compressor_quality',
		array(
			'type'              => 'integer',
			'default'           => 82,
			'sanitize_callback' => 'image_compressor_sanitize_quality',
		)
	);

	register_setting(
		'image_compressor_settings',
		'image_compressor_strip_exif',
		array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => 'image_compressor_sanitize_checkbox',
		)
	);

	register_setting(
		'image_compressor_settings',
		'image_compressor_max_width',
		array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		)
	);

	register_setting(
		'image_compressor_settings',
		'image_compressor_max_height',
		array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => 'absint',
		)
	);

	add_settings_section(
		'image_compressor_main',
		__( 'Compression Settings', 'image-compressor' ),
		static function () {
			echo '<p>' . esc_html__( 'JPEG and PNG files are re-encoded for a smaller size. GIF uploads are not changed.', 'image-compressor' ) . '</p>';
		},
		'image-compressor'
	);

	add_settings_field(
		'image_compressor_output_format',
		__( 'Output Format', 'image-compressor' ),
		'image_compressor_field_output_format',
		'image-compressor',
		'image_compressor_main'
	);

	add_settings_field(
		'image_compressor_quality',
		__( 'Compression Quality', 'image-compressor' ),
		'image_compressor_field_quality',
		'image-compressor',
		'image_compressor_main'
	);

	add_settings_field(
		'image_compressor_strip_exif',
		__( 'Strip EXIF Metadata', 'image-compressor' ),
		'image_compressor_field_strip_exif',
		'image-compressor',
		'image_compressor_main'
	);

	add_settings_section(
		'image_compressor_resize',
		__( 'Max Image Dimensions', 'image-compressor' ),
		static function () {
			echo '<p>' . esc_html__( 'Images larger than the limits below will be resized on upload. Set to 0 to disable.', 'image-compressor' ) . '</p>';
		},
		'image-compressor'
	);

	add_settings_field(
		'image_compressor_max_width',
		__( 'Max Width (px)', 'image-compressor' ),
		'image_compressor_field_max_width',
		'image-compressor',
		'image_compressor_resize'
	);

	add_settings_field(
		'image_compressor_max_height',
		__( 'Max Height (px)', 'image-compressor' ),
		'image_compressor_field_max_height',
		'image-compressor',
		'image_compressor_resize'
	);
}

/**
 * @param mixed $value
 * @return string 'none'|'webp'
 */
function image_compressor_sanitize_output_format( $value ) {
	return in_array( $value, array( 'none', 'webp' ), true ) ? $value : 'webp';
}

/**
 * @param mixed $value
 * @return int
 */
function image_compressor_sanitize_quality( $value ) {
	return min( 100, max( 1, (int) $value ) );
}

/**
 * @param mixed $value
 * @return string
 */
function image_compressor_sanitize_checkbox( $value ) {
	return ( '1' === $value || true === $value ) ? '1' : '0';
}

/**
 * @return void
 */
function image_compressor_field_output_format() {
	$current = get_option( 'image_compressor_output_format', 'webp' );
	$webp_ok = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );

	$formats = array(
		'none' => __( 'Keep original format (JPEG / PNG, re-compressed only)', 'image-compressor' ),
		'webp' => __( 'Convert to WebP', 'image-compressor' ),
	);

	foreach ( $formats as $value => $label ) {
		?>
		<label class="ic-radio-option">
			<input
				type="radio"
				name="image_compressor_output_format"
				value="<?php echo esc_attr( $value ); ?>"
				<?php checked( $current, $value ); ?>
				<?php disabled( 'webp' === $value && ! $webp_ok ); ?>
			/>
			<?php echo esc_html( $label ); ?>
			<?php if ( 'webp' === $value && ! $webp_ok ) : ?>
				<span class="ic-inline-warning"><?php esc_html_e( '(not supported on this server)', 'image-compressor' ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}
}

/**
 * @return void
 */
function image_compressor_field_quality() {
	$quality = (int) get_option( 'image_compressor_quality', 82 );
	?>
	<input
		type="range"
		name="image_compressor_quality"
		id="image_compressor_quality"
		class="ic-quality-range"
		min="1"
		max="100"
		step="1"
		value="<?php echo esc_attr( $quality ); ?>"
		data-ic-quality-target="ic_quality_val"
	/>
	<span id="ic_quality_val" class="ic-quality-value"><?php echo esc_html( $quality ); ?></span>
	<p class="description">
		<?php esc_html_e( 'Higher = better quality, larger file. Lower = smaller file, more compression. Default: 82.', 'image-compressor' ); ?>
	</p>
	<?php
}

/**
 * @return void
 */
function image_compressor_field_strip_exif() {
	$opt = get_option( 'image_compressor_strip_exif', '1' );
	?>
	<input type="hidden" name="image_compressor_strip_exif" value="0" />
	<label for="image_compressor_strip_exif">
		<input type="checkbox" name="image_compressor_strip_exif" id="image_compressor_strip_exif" value="1" <?php checked( $opt, '1' ); ?> />
		<?php esc_html_e( 'Remove EXIF, GPS, and other metadata from uploaded images', 'image-compressor' ); ?>
	</label>
	<p class="description">
		<?php esc_html_e( 'Reduces file size and protects privacy. Requires Imagick to preserve EXIF when unchecked; GD always strips metadata on re-encode.', 'image-compressor' ); ?>
	</p>
	<?php
}

function image_compressor_field_max_width() {
	$val = (int) get_option( 'image_compressor_max_width', 0 );
	?>
	<input
		type="number"
		name="image_compressor_max_width"
		id="image_compressor_max_width"
		class="small-text"
		min="0"
		step="1"
		value="<?php echo esc_attr( $val ); ?>"
	/> <?php esc_html_e( 'px', 'image-compressor' ); ?>
	<p class="description"><?php esc_html_e( 'Set to 0 to disable width limiting.', 'image-compressor' ); ?></p>
	<?php
}

/**
 * @return void
 */
function image_compressor_field_max_height() {
	$val = (int) get_option( 'image_compressor_max_height', 0 );
	?>
	<input
		type="number"
		name="image_compressor_max_height"
		id="image_compressor_max_height"
		class="small-text"
		min="0"
		step="1"
		value="<?php echo esc_attr( $val ); ?>"
	/> <?php esc_html_e( 'px', 'image-compressor' ); ?>
	<p class="description"><?php esc_html_e( 'Set to 0 to disable height limiting.', 'image-compressor' ); ?></p>
	<?php
}

/**
 * @return void
 */
function image_compressor_add_options_page() {
	add_options_page(
		__( 'ReloadWP Image Optimizer', 'image-compressor' ),
		__( 'ReloadWP Image Optimizer', 'image-compressor' ),
		image_compressor_get_settings_capability(),
		'image-compressor',
		'image_compressor_render_options_page'
	);
}

/**
 * @return void
 */
function image_compressor_add_bulk_optimize_page() {
	add_submenu_page(
		'upload.php',
		__( 'Bulk Optimize', 'image-compressor' ),
		__( 'Bulk Optimize', 'image-compressor' ),
		image_compressor_get_media_capability(),
		'ic-bulk-optimize',
		'image_compressor_render_bulk_optimize_page'
	);
}

/**
 * @return array{optimized:int,pending:int,total:int}
 */
function image_compressor_get_library_stats() {
	$base_args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	$optimized = (int) ( new WP_Query(
		array_merge(
			$base_args,
			array(
				'post_mime_type' => 'image',
				'meta_query'     => array(
					array(
						'key'     => '_ic_original_backup_rel',
						'compare' => 'EXISTS',
					),
				),
			)
		)
	) )->found_posts;

	$skipped = (int) ( new WP_Query(
		array_merge(
			$base_args,
			array(
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'meta_query'     => array(
					array(
						'key'   => '_ic_last_attempt_signature',
						'value' => image_compressor_get_current_settings_signature(),
					),
					array(
						'key'     => '_ic_original_backup_rel',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		)
	) )->found_posts;

	$pending = (int) ( new WP_Query(
		array_merge(
			$base_args,
			array(
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_ic_original_backup_rel',
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_ic_last_attempt_signature',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ic_last_attempt_signature',
							'value'   => image_compressor_get_current_settings_signature(),
							'compare' => '!=',
						),
					),
				),
			)
		)
	) )->found_posts;

	return array(
		'optimized' => $optimized,
		'skipped'   => $skipped,
		'pending'   => $pending,
		'total'     => $optimized + $pending + $skipped,
	);
}

/**
 * @return array{total:int,remaining:int}
 */
function image_compressor_get_restore_stats() {
	$base_args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'post_mime_type'         => 'image',
		'meta_query'             => array(
			array(
				'key'     => '_ic_original_backup_rel',
				'compare' => 'EXISTS',
			),
		),
	);

	$remaining = (int) ( new WP_Query( $base_args ) )->found_posts;

	return array(
		'total'     => $remaining,
		'remaining' => $remaining,
	);
}

/**
 * @return void
 */
function image_compressor_render_bulk_optimize_page() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		return;
	}

	$lib     = image_compressor_get_library_stats();
	$format  = get_option( 'image_compressor_output_format', 'webp' );
	$quality = (int) get_option( 'image_compressor_quality', 82 );
	$max_w   = (int) get_option( 'image_compressor_max_width', 0 );
	$max_h   = (int) get_option( 'image_compressor_max_height', 0 );

	$notice       = isset( $_GET['ic_notice'] ) ? sanitize_key( wp_unslash( $_GET['ic_notice'] ) ) : '';
	$is_error     = in_array( $notice, array( 'bulk_error' ), true );
	$notice_class = $is_error ? 'notice-error' : 'notice-success';
	$pct          = $lib['total'] > 0 ? round( ( $lib['optimized'] / $lib['total'] ) * 100 ) : 0;
	?>
	<div class="wrap ic-admin-page">
		<h1><?php esc_html_e( 'Bulk Optimize Media', 'image-compressor' ); ?></h1>
		<p><?php esc_html_e( 'Optimize all existing JPEG and PNG images in your Media Library using your current plugin settings. Originals are backed up before any changes.', 'image-compressor' ); ?></p>

		<?php if ( $notice ) : ?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<p>
				<?php if ( 'bulk_complete' === $notice ) : ?>
					<?php
					printf(
						/* translators: 1: optimized, 2: KB saved, 3: skipped, 4: errors */
						esc_html__( 'Done! %1$s image(s) optimized, %2$s KB saved, %3$s skipped, %4$s error(s).', 'image-compressor' ),
						'<strong>' . number_format_i18n( (int) ( $_GET['optimized'] ?? 0 ) ) . '</strong>',
						'<strong>' . esc_html( number_format( (int) ( $_GET['saved_bytes'] ?? 0 ) / 1024, 1 ) ) . '</strong>',
						number_format_i18n( (int) ( $_GET['skipped'] ?? 0 ) ),
						number_format_i18n( (int) ( $_GET['errors'] ?? 0 ) )
					);
					?>
				<?php else : ?>
					<?php echo esc_html( isset( $_GET['ic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ic_message'] ) ) : __( 'An error occurred.', 'image-compressor' ) ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php endif; ?>

		<div class="ic-stat-grid">
			<?php
			$cards = array(
				array(
					'value' => $lib['total'],
					'label' => __( 'Total Images', 'image-compressor' ),
					'class' => 'is-total',
				),
				array(
					'value' => $lib['optimized'],
					'label' => __( 'Optimized', 'image-compressor' ),
					'class' => 'is-optimized',
				),
				array(
					'value' => $lib['skipped'],
					'label' => __( 'Checked / Unchanged', 'image-compressor' ),
					'class' => 'is-neutral',
				),
				array(
					'value' => $lib['pending'],
					'label' => __( 'Pending Optimization', 'image-compressor' ),
					'class' => $lib['pending'] > 0 ? 'is-pending' : 'is-neutral',
				),
			);
			foreach ( $cards as $card ) :
				?>
				<div class="ic-stat-card">
					<div class="ic-stat-value <?php echo esc_attr( $card['class'] ); ?>"><?php echo number_format_i18n( $card['value'] ); ?></div>
					<div class="ic-stat-label"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $lib['total'] > 0 ) : ?>
		<div class="ic-progress-block" id="ic-progress-block">
			<div class="ic-progress-header">
				<span><?php esc_html_e( 'Optimization progress', 'image-compressor' ); ?></span>
				<span id="ic-progress-percent"><?php echo esc_html( $pct ); ?>%</span>
			</div>
			<div class="ic-progress-track">
				<div
					class="ic-progress-bar"
					id="ic-progress-bar"
					style="width:<?php echo esc_attr( $pct ); ?>%;"
					data-total="<?php echo esc_attr( $lib['pending'] ); ?>"
					data-initial-optimized="<?php echo esc_attr( $lib['optimized'] ); ?>"
					data-initial-pending="<?php echo esc_attr( $lib['pending'] ); ?>"
				></div>
			</div>
			<p class="ic-progress-status" id="ic-progress-status">
				<?php
				if ( $lib['pending'] > 0 ) {
					printf(
						/* translators: 1: processed count, 2: total count */
						esc_html__( 'Processing %1$s/%2$s images...', 'image-compressor' ),
						0,
						number_format_i18n( $lib['pending'] )
					);
				} else {
					esc_html_e( 'All supported images are already optimized.', 'image-compressor' );
				}
				?>
			</p>
			<p class="ic-progress-meta" id="ic-progress-meta">
				<?php esc_html_e( 'Estimated time will appear after the first batch finishes.', 'image-compressor' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<div class="ic-panel">
			<h3><?php esc_html_e( 'Current Settings', 'image-compressor' ); ?></h3>
			<table class="ic-settings-summary">
				<tr>
					<td><?php esc_html_e( 'Output Format', 'image-compressor' ); ?></td>
					<td><strong><?php echo 'webp' === $format ? esc_html__( 'Convert to WebP', 'image-compressor' ) : esc_html__( 'Keep original (JPEG / PNG)', 'image-compressor' ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Quality', 'image-compressor' ); ?></td>
					<td><strong><?php echo esc_html( $quality ); ?>/100</strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Max Dimensions', 'image-compressor' ); ?></td>
					<td>
						<strong>
							<?php
							if ( $max_w > 0 || $max_h > 0 ) {
								$parts = array();
								if ( $max_w > 0 ) {
									$parts[] = sprintf( __( '%dpx wide', 'image-compressor' ), $max_w );
								}
								if ( $max_h > 0 ) {
									$parts[] = sprintf( __( '%dpx tall', 'image-compressor' ), $max_h );
								}
								echo esc_html( implode( ', ', $parts ) );
							} else {
								esc_html_e( 'No resize limit', 'image-compressor' );
							}
							?>
						</strong>
					</td>
				</tr>
			</table>
			<p class="ic-panel-link">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=image-compressor' ) ); ?>"><?php esc_html_e( 'Change settings ->', 'image-compressor' ); ?></a>
			</p>
		</div>

		<?php if ( $lib['pending'] > 0 ) : ?>
		<div class="notice inline ic-live-notice" id="ic-live-notice" hidden></div>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-ic-batch-form="optimize">
			<input type="hidden" name="action" value="image_compressor_bulk_optimize" />
			<input type="hidden" name="ic_return" value="media" />
			<?php wp_nonce_field( 'image_compressor_bulk_optimize' ); ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of images pending */
					esc_html__( '%d image(s) will be processed. This may take a while for large libraries. Please keep this browser tab open until the batch is complete.', 'image-compressor' ),
					$lib['pending']
				);
				?>
			</p>
			<?php submit_button( __( 'Start Bulk Optimization', 'image-compressor' ), 'primary large', 'submit', false ); ?>
		</form>
		<?php else : ?>
		<p class="ic-success-text">
			<?php
			if ( ! empty( $lib['skipped'] ) ) {
				esc_html_e( 'All remaining supported images have already been checked for the current settings. Some were already optimal and were left unchanged.', 'image-compressor' );
			} else {
				esc_html_e( 'All supported images are already optimized.', 'image-compressor' );
			}
			?>
		</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * @return void
 */
function image_compressor_render_options_page() {
	if ( ! image_compressor_current_user_can_manage_settings() ) {
		return;
	}

	$stats       = get_option( 'image_compressor_stats', array( 'count' => 0, 'saved_bytes' => 0 ) );
	$count       = (int) ( $stats['count'] ?? 0 );
	$saved_bytes = (int) ( $stats['saved_bytes'] ?? 0 );
	$saved_kb    = number_format( $saved_bytes / 1024, 1 );
	$restore     = image_compressor_get_restore_stats();
	?>
	<div class="wrap ic-admin-page">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php if ( isset( $_GET['ic_notice'] ) ) : ?>
			<?php
			$notice = sanitize_key( wp_unslash( $_GET['ic_notice'] ) );
			if ( in_array( $notice, array( 'bulk_error', 'optimize_error', 'restore_error', 'delete_backups_error' ), true ) ) {
				$class = 'notice notice-error';
			} elseif ( 'optimize_skipped' === $notice ) {
				$class = 'notice notice-warning';
			} else {
				$class = 'notice notice-success';
			}
			?>
			<div class="<?php echo esc_attr( $class ); ?> inline">
				<p>
					<?php
					if ( 'bulk_complete' === $notice ) {
						printf(
							/* translators: 1: optimized images count, 2: KB saved, 3: skipped items, 4: errors */
							esc_html__( 'Bulk optimization finished: %1$s image(s) optimized, %2$s KB saved, %3$s skipped, %4$s errors.', 'image-compressor' ),
							number_format_i18n( (int) ( $_GET['optimized'] ?? 0 ) ),
							esc_html( number_format( (int) ( $_GET['saved_bytes'] ?? 0 ) / 1024, 1 ) ),
							number_format_i18n( (int) ( $_GET['skipped'] ?? 0 ) ),
							number_format_i18n( (int) ( $_GET['errors'] ?? 0 ) )
						);
					} elseif ( 'restore_all_complete' === $notice ) {
						printf(
							/* translators: 1: restored images count, 2: errors */
							esc_html__( 'Restore all finished: %1$s image(s) restored to original, %2$s error(s).', 'image-compressor' ),
							number_format_i18n( (int) ( $_GET['restored'] ?? 0 ) ),
							number_format_i18n( (int) ( $_GET['errors'] ?? 0 ) )
						);
					} elseif ( 'optimized' === $notice ) {
						printf(
							/* translators: %s: attachment ID */
							esc_html__( 'Attachment #%s was optimized successfully.', 'image-compressor' ),
							number_format_i18n( (int) ( $_GET['ic_attachment_id'] ?? 0 ) )
						);
					} elseif ( 'restored' === $notice ) {
						printf(
							/* translators: %s: attachment ID */
							esc_html__( 'Attachment #%s was restored from its original backup.', 'image-compressor' ),
							number_format_i18n( (int) ( $_GET['ic_attachment_id'] ?? 0 ) )
						);
					} elseif ( 'backups_deleted' === $notice ) {
						esc_html_e( 'All backups have been deleted successfully.', 'image-compressor' );
					} elseif ( 'optimize_skipped' === $notice ) {
						echo esc_html( isset( $_GET['ic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ic_message'] ) ) : __( 'This image is already optimal for the current settings, so it was left unchanged.', 'image-compressor' ) );
					} elseif ( 'bulk_error' === $notice || 'optimize_error' === $notice || 'restore_error' === $notice || 'delete_backups_error' === $notice ) {
						echo esc_html( isset( $_GET['ic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ic_message'] ) ) : __( 'The requested action could not be completed.', 'image-compressor' ) );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'image_compressor_settings' );
			do_settings_sections( 'image-compressor' );
			submit_button();
			?>
		</form>

		<hr class="ic-section-divider" />

		<h2><?php esc_html_e( 'Restore All Original Images', 'image-compressor' ); ?></h2>
		<p><?php esc_html_e( 'Restore all optimized images back to their originals from backup. This will undo all optimizations for images that have a saved backup. Images without a backup will not be affected.', 'image-compressor' ); ?></p>
		<?php if ( $restore['total'] > 0 ) : ?>
		<div class="ic-progress-block" id="ic-restore-progress-block" hidden>
			<div class="ic-progress-header">
				<span><?php esc_html_e( 'Restore progress', 'image-compressor' ); ?></span>
				<span id="ic-restore-progress-percent">0%</span>
			</div>
			<div class="ic-progress-track">
				<div
					class="ic-progress-bar"
					id="ic-restore-progress-bar"
					style="width:0%;"
				></div>
			</div>
			<p class="ic-progress-status" id="ic-restore-progress-status">
				<?php
				printf(
					/* translators: 1: processed count, 2: total count */
					esc_html__( 'Restoring %1$s/%2$s images...', 'image-compressor' ),
					0,
					number_format_i18n( $restore['total'] )
				);
				?>
			</p>
			<p class="ic-progress-meta" id="ic-restore-progress-meta">
				<?php esc_html_e( 'Estimated time will appear after the first batch finishes.', 'image-compressor' ); ?>
			</p>
		</div>
		<div class="notice inline ic-live-notice" id="ic-restore-live-notice" hidden></div>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to restore all images to their originals? This will undo all optimizations.', 'image-compressor' ) ); ?>');" data-ic-batch-form="restore">
			<input type="hidden" name="action" value="image_compressor_restore_all" />
			<?php wp_nonce_field( 'image_compressor_restore_all' ); ?>
			<?php submit_button( __( 'Restore All Originals', 'image-compressor' ), 'delete', 'submit', false ); ?>
		</form>
		<?php else : ?>
		<p class="ic-muted-text"><?php esc_html_e( 'No optimized images with backups are currently available to restore.', 'image-compressor' ); ?></p>
		<?php endif; ?>

		<hr class="ic-section-divider" />

		<h2><?php esc_html_e( 'Delete Backups', 'image-compressor' ); ?></h2>
		<?php
		$backup_root      = image_compressor_get_backup_root();
		$backup_exists    = is_dir( $backup_root );
		$backup_size_text = '';
		if ( $backup_exists ) {
			$backup_bytes     = image_compressor_dir_size( $backup_root );
			$backup_size_text = $backup_bytes >= 1048576
				? number_format( $backup_bytes / 1048576, 2 ) . ' MB'
				: number_format( $backup_bytes / 1024, 1 ) . ' KB';
		}
		?>
		<p><?php esc_html_e( 'Original backups are stored so you can restore images at any time. Once you are happy with the optimization results, you can delete all backups to free up disk space. This action cannot be undone - restoring individual images or all originals will no longer be possible afterwards.', 'image-compressor' ); ?></p>
		<?php if ( $backup_exists && $backup_size_text ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: formatted backup folder size */
				esc_html__( 'Current backup folder size: %s', 'image-compressor' ),
				'<strong>' . esc_html( $backup_size_text ) . '</strong>'
			);
			?>
		</p>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure? This will permanently delete all original backups. Images cannot be restored afterwards.', 'image-compressor' ) ); ?>');">
			<input type="hidden" name="action" value="image_compressor_delete_backups" />
			<?php wp_nonce_field( 'image_compressor_delete_backups' ); ?>
			<?php submit_button( __( 'Delete All Backups', 'image-compressor' ), 'delete', 'submit', false, $backup_exists ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php if ( ! $backup_exists ) : ?>
		<p class="ic-muted-text"><?php esc_html_e( 'No backup folder found - nothing to delete.', 'image-compressor' ); ?></p>
		<?php endif; ?>

		<hr class="ic-section-divider" />

		<h2><?php esc_html_e( 'Compression Stats', 'image-compressor' ); ?></h2>
		<?php if ( $count > 0 ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: number of images, 2: KB saved */
				esc_html__( '%1$s image(s) compressed, saving %2$s KB total.', 'image-compressor' ),
				'<strong>' . number_format_i18n( $count ) . '</strong>',
				'<strong>' . esc_html( $saved_kb ) . '</strong>'
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ic_reset_stats', '1' ), 'ic_reset_stats' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Reset Stats', 'image-compressor' ); ?>
			</a>
		</p>
		<?php else : ?>
		<p class="ic-muted-text"><?php esc_html_e( 'No compression data recorded yet.', 'image-compressor' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * @return void
 */
function image_compressor_maybe_reset_stats() {
	if (
		isset( $_GET['ic_reset_stats'] ) &&
		'1' === $_GET['ic_reset_stats'] &&
		image_compressor_current_user_can_manage_settings() &&
		isset( $_GET['page'] ) &&
		'image-compressor' === $_GET['page'] &&
		isset( $_GET['_wpnonce'] ) &&
		wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'ic_reset_stats' )
	) {
		update_option( 'image_compressor_stats', array( 'count' => 0, 'saved_bytes' => 0 ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=image-compressor' ) );
		exit;
	}
}
