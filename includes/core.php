<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_handle_upload', 'image_compressor_capture_upload_backup', 20, 2 );
add_action( 'add_attachment', 'image_compressor_finalize_upload_backup' );
add_action( 'admin_post_image_compressor_bulk_optimize', 'image_compressor_handle_bulk_optimize' );
add_action( 'admin_post_image_compressor_optimize_attachment', 'image_compressor_handle_optimize_attachment' );
add_action( 'admin_post_image_compressor_restore_attachment', 'image_compressor_handle_restore_attachment' );
add_action( 'admin_post_image_compressor_restore_all', 'image_compressor_handle_restore_all' );
add_action( 'admin_post_image_compressor_delete_backups', 'image_compressor_handle_delete_backups' );
add_action( 'wp_ajax_image_compressor_bulk_optimize_batch', 'image_compressor_ajax_bulk_optimize_batch' );
add_action( 'wp_ajax_image_compressor_restore_all_batch', 'image_compressor_ajax_restore_all_batch' );
add_filter( 'bulk_actions-upload', 'image_compressor_register_media_bulk_actions' );
add_filter( 'handle_bulk_actions-upload', 'image_compressor_handle_media_bulk_action', 10, 3 );
add_action( 'admin_notices', 'image_compressor_render_admin_notice' );

/**
 * @return void
 */
function image_compressor_render_admin_notice() {
	if ( isset( $_GET['page'] ) && 'image-compressor' === $_GET['page'] ) {
		return;
	}

	if ( ! isset( $_GET['ic_notice'] ) ) {
		return;
	}

	$notice = sanitize_key( wp_unslash( $_GET['ic_notice'] ) );
	if ( ! in_array( $notice, array( 'optimized', 'optimize_skipped', 'restored', 'optimize_error', 'restore_error', 'bulk_complete', 'bulk_restore_complete' ), true ) ) {
		return;
	}

	if ( in_array( $notice, array( 'optimize_error', 'restore_error' ), true ) ) {
		$class = 'notice notice-error';
	} elseif ( 'optimize_skipped' === $notice ) {
		$class = 'notice notice-warning';
	} else {
		$class = 'notice notice-success';
	}
	?>
	<div class="<?php echo esc_attr( $class ); ?> is-dismissible">
		<p>
			<?php
			if ( 'bulk_complete' === $notice ) {
				printf(
					/* translators: 1: optimized images count, 2: KB saved, 3: skipped items, 4: errors */
					esc_html__( 'ReloadWP Image Optimizer: %1$s image(s) optimized, %2$s KB saved, %3$s skipped, %4$s error(s).', 'image-compressor' ),
					number_format_i18n( (int) ( $_GET['optimized'] ?? 0 ) ),
					esc_html( number_format( (int) ( $_GET['saved_bytes'] ?? 0 ) / 1024, 1 ) ),
					number_format_i18n( (int) ( $_GET['skipped'] ?? 0 ) ),
					number_format_i18n( (int) ( $_GET['errors'] ?? 0 ) )
				);
			} elseif ( 'bulk_restore_complete' === $notice ) {
				printf(
					/* translators: 1: restored images count, 2: skipped items, 3: errors */
					esc_html__( 'ReloadWP Image Optimizer: %1$s image(s) restored to original, %2$s skipped (no backup), %3$s error(s).', 'image-compressor' ),
					number_format_i18n( (int) ( $_GET['restored'] ?? 0 ) ),
					number_format_i18n( (int) ( $_GET['skipped'] ?? 0 ) ),
					number_format_i18n( (int) ( $_GET['errors'] ?? 0 ) )
				);
			} elseif ( 'optimized' === $notice ) {
				printf(
					/* translators: %s: attachment ID */
					esc_html__( 'Attachment #%s was optimized successfully.', 'image-compressor' ),
					number_format_i18n( (int) ( $_GET['ic_attachment_id'] ?? 0 ) )
				);
			} elseif ( 'optimize_skipped' === $notice ) {
				echo esc_html( isset( $_GET['ic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ic_message'] ) ) : __( 'This image is already optimal for the current settings, so it was left unchanged.', 'image-compressor' ) );
			} elseif ( 'restored' === $notice ) {
				printf(
					/* translators: %s: attachment ID */
					esc_html__( 'Attachment #%s was restored from its original backup.', 'image-compressor' ),
					number_format_i18n( (int) ( $_GET['ic_attachment_id'] ?? 0 ) )
				);
			} else {
				echo esc_html( isset( $_GET['ic_message'] ) ? sanitize_text_field( wp_unslash( $_GET['ic_message'] ) ) : __( 'The requested action could not be completed.', 'image-compressor' ) );
			}
			?>
		</p>
	</div>
	<?php
}

/**
 * Compress (and optionally convert to WebP) on the PHP upload temp file,
 * then let core store the file and generate metadata.
 *
 * GIF is left unchanged so animations are not flattened to a single frame.
 */
add_filter( 'wp_handle_upload_prefilter', 'image_compressor_upload_prefilter' );

// Apply the same quality to all WordPress image operations (sub-sizes, big-image scaling, etc.).
add_filter( 'jpeg_quality', 'image_compressor_filter_quality', 10, 2 );
add_filter( 'wp_editor_set_quality', 'image_compressor_filter_quality', 10, 3 );

/**
 * @param int                    $quality
 * @param string                 $context_or_mime
 * @param array<int|string, int> $size
 * @return int
 */
function image_compressor_filter_quality( $quality = 82, $context_or_mime = '', $size = array() ) {
	unset( $context_or_mime, $size );

	if ( ! image_compressor_quality_filters_enabled() ) {
		return (int) $quality;
	}

	return (int) apply_filters( 'image_compressor_quality', (int) get_option( 'image_compressor_quality', 82 ) );
}

/**
 * @param array $file {
 *     @type string $tmp_name
 *     @type string $name
 *     @type string $type
 *     @type int    $size
 *     @type int    $error
 * }
 * @return array
 */
function image_compressor_upload_prefilter( $file ) {
	if ( (int) ( $file['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
		return $file;
	}

	if ( empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
		return $file;
	}

	if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
		return $file;
	}

	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	$ext     = isset( $checked['ext'] ) ? strtolower( (string) $checked['ext'] ) : '';

	if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
		return $file;
	}

	$backup_temp = image_compressor_create_temp_backup( $file['tmp_name'], $file['name'] );
	if ( false === $backup_temp ) {
		return $file;
	}

	$result = image_compressor_optimize_image_file( $file['tmp_name'], $file['name'] );
	if ( is_wp_error( $result ) || empty( $result['changed'] ) ) {
		unlink( $backup_temp );
		return $file;
	}

	if ( ! image_compressor_move_file( $result['temp_path'], $file['tmp_name'] ) ) {
		unlink( $backup_temp );
		return $file;
	}

	image_compressor_queue_upload_backup(
		array(
			'temp_path'     => $backup_temp,
			'original_ext'  => $ext,
			'original_name' => (string) $file['name'],
		)
	);

	image_compressor_record_stats( 1, (int) $result['saved_bytes'] );

	if ( 'webp' === $result['output_format'] ) {
		$file['name'] = preg_replace( '/\.[^.]+$/i', '.webp', $file['name'] );
	}

	$file['type'] = (string) $result['output_mime'];
	$file['size'] = (int) $result['new_size'];

	return $file;
}

/**
 * @param array  $upload
 * @param string $context
 * @return array
 */
function image_compressor_capture_upload_backup( $upload, $context ) {
	unset( $context );

	$backup_info = image_compressor_shift_upload_backup();
	if ( null === $backup_info || empty( $upload['file'] ) ) {
		return $upload;
	}

	image_compressor_store_pending_upload_backup( (string) $upload['file'], $backup_info );

	return $upload;
}

/**
 * @param int $attachment_id
 * @return void
 */
function image_compressor_finalize_upload_backup( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return;
	}

	$backup_info = image_compressor_take_pending_upload_backup( $file );
	if ( null === $backup_info || empty( $backup_info['temp_path'] ) || ! file_exists( $backup_info['temp_path'] ) ) {
		return;
	}

	$current_rel  = _wp_relative_upload_path( $file );
	$original_ext = sanitize_key( (string) ( $backup_info['original_ext'] ?? '' ) );

	if ( '' === $current_rel || '' === $original_ext ) {
		unlink( $backup_info['temp_path'] );
		return;
	}

	$original_rel = preg_replace( '/\.[^.]+$/', '.' . $original_ext, $current_rel );
	$stored       = image_compressor_store_attachment_backup( $attachment_id, $backup_info['temp_path'], (string) $original_rel );

	unlink( $backup_info['temp_path'] );

	if ( true === $stored && '' === get_post_meta( $attachment_id, '_ic_original_attached_rel', true ) ) {
		update_post_meta( $attachment_id, '_ic_original_attached_rel', $current_rel );
	}
}

/**
 * @param int $attachment_id
 * @return bool
 */
function image_compressor_attachment_has_backup( $attachment_id ) {
	$backup_rel = (string) get_post_meta( $attachment_id, '_ic_original_backup_rel', true );
	if ( '' === $backup_rel ) {
		return false;
	}

	$backup_abs = trailingslashit( image_compressor_get_upload_dir()['basedir'] ) . ltrim( $backup_rel, '/\\' );

	return file_exists( $backup_abs );
}

/**
 * @param int $attachment_id
 * @return array<string,mixed>|WP_Error
 */
function image_compressor_optimize_existing_attachment( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'ic_missing_file', __( 'Attachment file is missing.', 'image-compressor' ) );
	}

	$ext = image_compressor_get_supported_extension( $file );
	if ( '' === $ext ) {
		return new WP_Error( 'ic_skip_type', __( 'Only JPEG and PNG attachments can be optimized.', 'image-compressor' ) );
	}

	$current_rel = _wp_relative_upload_path( $file );
	if ( '' === $current_rel ) {
		return new WP_Error( 'ic_relative_path', __( 'Could not determine the attachment upload path.', 'image-compressor' ) );
	}

	$result = image_compressor_optimize_image_file( $file, wp_basename( $file ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['changed'] ) ) {
		image_compressor_mark_attachment_skipped( $attachment_id );
		return array(
			'changed'     => false,
			'saved_bytes' => 0,
		);
	}

	image_compressor_clear_attachment_skipped( $attachment_id );
	$backup_result = image_compressor_store_attachment_backup( $attachment_id, $file, $current_rel );
	if ( is_wp_error( $backup_result ) ) {
		if ( ! empty( $result['temp_path'] ) && file_exists( $result['temp_path'] ) ) {
			unlink( $result['temp_path'] );
		}
		return $backup_result;
	}

	$target_path = $file;
	if ( 'webp' === $result['output_format'] ) {
		$target_path = image_compressor_build_target_path( $file, 'webp' );
	}

	$old_meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! image_compressor_move_file( $result['temp_path'], $target_path ) ) {
		return new WP_Error( 'ic_move_failed', __( 'Could not replace the attachment with the optimized file.', 'image-compressor' ) );
	}

	if ( $target_path !== $file && file_exists( $file ) ) {
		unlink( $file );
	}

	if ( $target_path !== $file ) {
		wp_delete_attachment_files( $attachment_id, $old_meta, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ), $file );
	}

	if ( $target_path !== $file ) {
		update_attached_file( $attachment_id, $target_path );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => (string) $result['output_mime'],
			)
		);
	}

	image_compressor_require_media_dependencies();
	image_compressor_enable_quality_filters();
	$new_meta = wp_generate_attachment_metadata( $attachment_id, $target_path );
	image_compressor_disable_quality_filters();
	wp_update_attachment_metadata( $attachment_id, $new_meta );

	image_compressor_record_stats( 1, (int) $result['saved_bytes'] );

	return array(
		'changed'     => true,
		'saved_bytes' => (int) $result['saved_bytes'],
	);
}

/**
 * @param int $attachment_id
 * @return true|WP_Error
 */
function image_compressor_restore_attachment_from_backup( $attachment_id ) {
	$backup_rel   = (string) get_post_meta( $attachment_id, '_ic_original_backup_rel', true );
	$original_rel = (string) get_post_meta( $attachment_id, '_ic_original_attached_rel', true );

	if ( '' === $backup_rel || '' === $original_rel ) {
		return new WP_Error( 'ic_no_backup', __( 'No original backup is available for this attachment.', 'image-compressor' ) );
	}

	$uploads    = image_compressor_get_upload_dir();
	$backup_abs = trailingslashit( $uploads['basedir'] ) . ltrim( $backup_rel, '/\\' );
	$target_abs = trailingslashit( $uploads['basedir'] ) . ltrim( $original_rel, '/\\' );
	$current    = get_attached_file( $attachment_id );

	if ( ! image_compressor_is_path_within_dir( $uploads['basedir'], $backup_abs ) || ! image_compressor_is_path_within_dir( $uploads['basedir'], $target_abs ) ) {
		return new WP_Error( 'ic_invalid_restore_path', __( 'The restore path is invalid.', 'image-compressor' ) );
	}

	if ( ! file_exists( $backup_abs ) ) {
		return new WP_Error( 'ic_missing_backup', __( 'The original backup file could not be found.', 'image-compressor' ) );
	}

	if ( ! wp_mkdir_p( dirname( $target_abs ) ) ) {
		return new WP_Error( 'ic_restore_dir', __( 'Could not prepare the restore directory.', 'image-compressor' ) );
	}

	$temp_restore = image_compressor_create_temp_backup( $backup_abs, wp_basename( $backup_abs ) );
	if ( false === $temp_restore ) {
		return new WP_Error( 'ic_restore_temp', __( 'Could not create a temporary restore file.', 'image-compressor' ) );
	}

	if ( ! image_compressor_move_file( $temp_restore, $target_abs ) ) {
		if ( file_exists( $temp_restore ) ) {
			unlink( $temp_restore );
		}
		return new WP_Error( 'ic_restore_move', __( 'Could not restore the original image.', 'image-compressor' ) );
	}

	$old_meta = wp_get_attachment_metadata( $attachment_id );
	if ( $current && $current !== $target_abs && file_exists( $current ) ) {
		unlink( $current );
	}

	if ( $current && $current !== $target_abs ) {
		wp_delete_attachment_files( $attachment_id, $old_meta, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ), $current );
	}

	update_attached_file( $attachment_id, $target_abs );

	$restored_mime = wp_check_filetype( wp_basename( $target_abs ) );
	wp_update_post(
		array(
			'ID'             => $attachment_id,
			'post_mime_type' => (string) ( $restored_mime['type'] ?? 'image/jpeg' ),
		)
	);

	image_compressor_require_media_dependencies();
	image_compressor_enable_quality_filters();
	$new_meta = wp_generate_attachment_metadata( $attachment_id, $target_abs );
	image_compressor_disable_quality_filters();
	wp_update_attachment_metadata( $attachment_id, $new_meta );

	// Clean up the backup file and its directory, then remove the meta so the
	// Optimizer column reflects the restored (unoptimized) state immediately.
	if ( file_exists( $backup_abs ) ) {
		unlink( $backup_abs );
		image_compressor_cleanup_empty_dirs( dirname( $backup_abs ), image_compressor_get_backup_root() );
	}
	image_compressor_clear_attachment_skipped( $attachment_id );
	delete_post_meta( $attachment_id, '_ic_original_backup_rel' );
	delete_post_meta( $attachment_id, '_ic_original_attached_rel' );

	return true;
}

/**
 * @param WP_Post $post
 * @return string
 */
function image_compressor_get_referer_base() {
	$referer = wp_get_referer();
	if ( $referer ) {
		// Strip any previous ic_* params from the referer so they don't accumulate.
		$referer = remove_query_arg( array( 'ic_notice', 'ic_message', 'ic_attachment_id' ), $referer );
		return $referer;
	}
	return admin_url( 'upload.php' );
}

/**
 * @return void
 */
function image_compressor_handle_bulk_optimize() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'image-compressor' ) );
	}

	check_admin_referer( 'image_compressor_bulk_optimize' );

	// Detect whether the request originated from the Media page or Settings page.
	$from_media = isset( $_REQUEST['ic_return'] ) && 'media' === $_REQUEST['ic_return'];

	$optimized  = isset( $_REQUEST['optimized'] ) ? absint( $_REQUEST['optimized'] ) : 0;
	$skipped    = isset( $_REQUEST['skipped'] ) ? absint( $_REQUEST['skipped'] ) : 0;
	$errors     = isset( $_REQUEST['errors'] ) ? absint( $_REQUEST['errors'] ) : 0;
	$saved      = isset( $_REQUEST['saved_bytes'] ) ? absint( $_REQUEST['saved_bytes'] ) : 0;

	$batch = image_compressor_process_bulk_batch();
	$optimized += (int) $batch['optimized'];
	$skipped   += (int) $batch['skipped'];
	$errors    += (int) $batch['errors'];
	$saved     += (int) $batch['saved_bytes'];

	if ( ! empty( $batch['processed'] ) && empty( $batch['done'] ) ) {
		$next_url = add_query_arg(
			array(
				'action'      => 'image_compressor_bulk_optimize',
				'ic_return'   => $from_media ? 'media' : 'settings',
				'optimized'   => $optimized,
				'skipped'     => $skipped,
				'errors'      => $errors,
				'saved_bytes' => $saved,
				'_wpnonce'    => wp_create_nonce( 'image_compressor_bulk_optimize' ),
			),
			admin_url( 'admin-post.php' )
		);

		wp_safe_redirect( $next_url );
		exit;
	}

	if ( $from_media ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'ic-bulk-optimize',
					'ic_notice'   => 'bulk_complete',
					'optimized'   => $optimized,
					'skipped'     => $skipped,
					'errors'      => $errors,
					'saved_bytes' => $saved,
				),
				admin_url( 'upload.php' )
			)
		);
	} else {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'image-compressor',
					'ic_notice'   => 'bulk_complete',
					'optimized'   => $optimized,
					'skipped'     => $skipped,
					'errors'      => $errors,
					'saved_bytes' => $saved,
				),
				admin_url( 'options-general.php' )
			)
		);
	}
	exit;
}

/**
 * @return int
 */
function image_compressor_get_bulk_batch_size() {
	return max( 1, (int) apply_filters( 'image_compressor_bulk_batch_size', 5 ) );
}

/**
 * @return int
 */
function image_compressor_get_restore_batch_size() {
	return max( 1, (int) apply_filters( 'image_compressor_restore_batch_size', 5 ) );
}

/**
 * @param int $batch_size
 * @return int[]
 */
function image_compressor_get_pending_attachment_ids( $batch_size ) {
	return get_posts(
		array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
			'posts_per_page'         => max( 1, (int) $batch_size ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
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
	);
}

/**
 * @return array<string,int|bool>
 */
function image_compressor_process_bulk_batch() {
	$optimized      = 0;
	$skipped        = 0;
	$errors         = 0;
	$saved_bytes    = 0;
	$attachment_ids = image_compressor_get_pending_attachment_ids( image_compressor_get_bulk_batch_size() );

	foreach ( $attachment_ids as $attachment_id ) {
		$result = image_compressor_optimize_existing_attachment( (int) $attachment_id );
		if ( is_wp_error( $result ) ) {
			if ( 'ic_skip_type' === $result->get_error_code() ) {
				++$skipped;
			} else {
				++$errors;
			}
			continue;
		}

		if ( ! empty( $result['changed'] ) ) {
			++$optimized;
			$saved_bytes += (int) $result['saved_bytes'];
		} else {
			++$skipped;
		}
	}

	$library_stats = image_compressor_get_library_stats();

	return array(
		'processed'       => count( $attachment_ids ),
		'optimized'       => $optimized,
		'skipped'         => $skipped,
		'errors'          => $errors,
		'saved_bytes'     => $saved_bytes,
		'library_total'   => (int) $library_stats['total'],
		'library_pending' => (int) $library_stats['pending'],
		'library_done'    => (int) $library_stats['optimized'],
		'done'            => 0 === (int) $library_stats['pending'],
	);
}

/**
 * @param int $batch_size
 * @return int[]
 */
function image_compressor_get_restore_attachment_ids( $batch_size ) {
	return get_posts(
		array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => max( 1, (int) $batch_size ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_ic_original_backup_rel',
					'compare' => 'EXISTS',
				),
			),
		)
	);
}

/**
 * @return array<string,int|bool>
 */
function image_compressor_process_restore_batch() {
	$restored       = 0;
	$errors         = 0;
	$attachment_ids = image_compressor_get_restore_attachment_ids( image_compressor_get_restore_batch_size() );

	foreach ( $attachment_ids as $attachment_id ) {
		$result = image_compressor_restore_attachment_from_backup( (int) $attachment_id );
		if ( is_wp_error( $result ) ) {
			++$errors;
		} else {
			++$restored;
		}
	}

	$restore_stats = image_compressor_get_restore_stats();

	return array(
		'processed'  => count( $attachment_ids ),
		'restored'   => $restored,
		'errors'     => $errors,
		'remaining'  => (int) $restore_stats['remaining'],
		'total'      => (int) $restore_stats['total'],
		'done'       => 0 === (int) $restore_stats['remaining'],
	);
}

/**
 * @return void
 */
function image_compressor_ajax_bulk_optimize_batch() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_send_json_error(
			array(
				'message' => __( 'You are not allowed to do that.', 'image-compressor' ),
			),
			403
		);
	}

	check_ajax_referer( 'image_compressor_bulk_optimize', 'nonce' );

	$optimized = isset( $_POST['optimized'] ) ? absint( wp_unslash( $_POST['optimized'] ) ) : 0;
	$skipped   = isset( $_POST['skipped'] ) ? absint( wp_unslash( $_POST['skipped'] ) ) : 0;
	$errors    = isset( $_POST['errors'] ) ? absint( wp_unslash( $_POST['errors'] ) ) : 0;
	$saved     = isset( $_POST['saved_bytes'] ) ? absint( wp_unslash( $_POST['saved_bytes'] ) ) : 0;

	$batch = image_compressor_process_bulk_batch();

	$optimized += (int) $batch['optimized'];
	$skipped   += (int) $batch['skipped'];
	$errors    += (int) $batch['errors'];
	$saved     += (int) $batch['saved_bytes'];

	wp_send_json_success(
		array(
			'optimized'       => $optimized,
			'skipped'         => $skipped,
			'errors'          => $errors,
			'saved_bytes'     => $saved,
			'batch_processed' => (int) $batch['processed'],
			'library_total'   => (int) $batch['library_total'],
			'library_pending' => (int) $batch['library_pending'],
			'library_done'    => (int) $batch['library_done'],
			'done'            => ! empty( $batch['done'] ),
		)
	);
}

/**
 * @return void
 */
function image_compressor_ajax_restore_all_batch() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_send_json_error(
			array(
				'message' => __( 'You are not allowed to do that.', 'image-compressor' ),
			),
			403
		);
	}

	check_ajax_referer( 'image_compressor_restore_all', 'nonce' );

	$restored = isset( $_POST['restored'] ) ? absint( wp_unslash( $_POST['restored'] ) ) : 0;
	$errors   = isset( $_POST['errors'] ) ? absint( wp_unslash( $_POST['errors'] ) ) : 0;

	$batch = image_compressor_process_restore_batch();

	$restored += (int) $batch['restored'];
	$errors   += (int) $batch['errors'];

	wp_send_json_success(
		array(
			'restored'        => $restored,
			'errors'          => $errors,
			'batch_processed' => (int) $batch['processed'],
			'remaining'       => (int) $batch['remaining'],
			'total'           => (int) $batch['total'],
			'done'            => ! empty( $batch['done'] ),
		)
	);
}

/**
 * @return void
 */
function image_compressor_handle_optimize_attachment() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'image-compressor' ) );
	}

	$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
	check_admin_referer( 'image_compressor_optimize_attachment_' . $attachment_id );

	$result = image_compressor_optimize_existing_attachment( $attachment_id );
	$target = image_compressor_get_referer_base();

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'ic_notice'  => 'optimize_error',
					'ic_message' => $result->get_error_message(),
				),
				$target
			)
		);
		exit;
	}

	if ( empty( $result['changed'] ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'ic_notice'  => 'optimize_skipped',
					'ic_message' => __( 'This image is already optimal for the current settings, so it was left unchanged.', 'image-compressor' ),
				),
				$target
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'ic_notice'        => 'optimized',
				'ic_attachment_id' => $attachment_id,
			),
			$target
		)
	);
	exit;
}

/**
 * @return void
 */
function image_compressor_handle_restore_attachment() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'image-compressor' ) );
	}

	$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
	check_admin_referer( 'image_compressor_restore_attachment_' . $attachment_id );

	$result = image_compressor_restore_attachment_from_backup( $attachment_id );
	$target = image_compressor_get_referer_base();

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'ic_notice'  => 'restore_error',
					'ic_message' => $result->get_error_message(),
				),
				$target
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'ic_notice'        => 'restored',
				'ic_attachment_id' => $attachment_id,
			),
			$target
		)
	);
	exit;
}

/**
 * @param array $bulk_actions
 * @return array
 */
function image_compressor_register_media_bulk_actions( $bulk_actions ) {
	$bulk_actions['ic_bulk_optimize'] = __( 'Optimize images', 'image-compressor' );
	$bulk_actions['ic_bulk_restore']  = __( 'Restore optimized images', 'image-compressor' );
	return $bulk_actions;
}

/**
 * @param string $redirect_url
 * @param string $action
 * @param int[]  $post_ids
 * @return string
 */
function image_compressor_handle_media_bulk_action( $redirect_url, $action, $post_ids ) {
	if ( ! in_array( $action, array( 'ic_bulk_optimize', 'ic_bulk_restore' ), true ) ) {
		return $redirect_url;
	}

	if ( ! image_compressor_current_user_can_manage_media() ) {
		return $redirect_url;
	}

	if ( 'ic_bulk_restore' === $action ) {
		$restored = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! image_compressor_attachment_has_backup( (int) $post_id ) ) {
				++$skipped;
				continue;
			}
			$result = image_compressor_restore_attachment_from_backup( (int) $post_id );
			if ( is_wp_error( $result ) ) {
				++$errors;
			} else {
				++$restored;
			}
		}

		return add_query_arg(
			array(
				'ic_notice' => 'bulk_restore_complete',
				'restored'  => $restored,
				'skipped'   => $skipped,
				'errors'    => $errors,
			),
			$redirect_url
		);
	}

	$optimized = 0;
	$skipped   = 0;
	$errors    = 0;
	$saved     = 0;

	foreach ( $post_ids as $post_id ) {
		$result = image_compressor_optimize_existing_attachment( (int) $post_id );
		if ( is_wp_error( $result ) ) {
			if ( 'ic_skip_type' === $result->get_error_code() ) {
				++$skipped;
			} else {
				++$errors;
			}
		} elseif ( ! empty( $result['changed'] ) ) {
			++$optimized;
			$saved += (int) $result['saved_bytes'];
		} else {
			++$skipped;
		}
	}

	return add_query_arg(
		array(
			'ic_notice'   => 'bulk_complete',
			'optimized'   => $optimized,
			'skipped'     => $skipped,
			'errors'      => $errors,
			'saved_bytes' => $saved,
		),
		$redirect_url
	);
}

/**
 * @param string $dir
 * @return int
 */
function image_compressor_dir_size( $dir ) {
	$size = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$size += $file->getSize();
	}
	return $size;
}

/**
 * @param string $dir
 * @return bool
 */
function image_compressor_delete_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return true;
	}

	if ( ! image_compressor_is_path_within_dir( image_compressor_get_upload_dir()['basedir'], $dir ) ) {
		return false;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getRealPath() );
		} else {
			unlink( $item->getRealPath() );
		}
	}
	return rmdir( $dir );
}

/**
 * @return void
 */
function image_compressor_handle_delete_backups() {
	if ( ! image_compressor_current_user_can_manage_settings() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'image-compressor' ) );
	}

	check_admin_referer( 'image_compressor_delete_backups' );

	$backup_root = image_compressor_get_backup_root();

	if ( ! is_dir( $backup_root ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'image-compressor',
					'ic_notice' => 'backups_deleted',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	if ( ! image_compressor_delete_dir( $backup_root ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'image-compressor',
					'ic_notice'  => 'delete_backups_error',
					'ic_message' => __( 'Could not fully delete the backup folder. Check file permissions.', 'image-compressor' ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// Clear backup meta from all attachments so the Optimizer column updates.
	delete_post_meta_by_key( '_ic_original_backup_rel' );
	delete_post_meta_by_key( '_ic_original_attached_rel' );
	delete_post_meta_by_key( '_ic_last_attempt_signature' );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'image-compressor',
				'ic_notice' => 'backups_deleted',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * @return void
 */
function image_compressor_handle_restore_all() {
	if ( ! image_compressor_current_user_can_manage_media() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'image-compressor' ) );
	}

	check_admin_referer( 'image_compressor_restore_all' );

	$restored   = isset( $_REQUEST['restored'] ) ? absint( $_REQUEST['restored'] ) : 0;
	$errors     = isset( $_REQUEST['errors'] ) ? absint( $_REQUEST['errors'] ) : 0;

	$batch = image_compressor_process_restore_batch();
	$restored += (int) $batch['restored'];
	$errors   += (int) $batch['errors'];

	if ( ! empty( $batch['processed'] ) && empty( $batch['done'] ) ) {
		$next_url = add_query_arg(
			array(
				'action'    => 'image_compressor_restore_all',
				'restored'  => $restored,
				'errors'    => $errors,
				'_wpnonce'  => wp_create_nonce( 'image_compressor_restore_all' ),
			),
			admin_url( 'admin-post.php' )
		);

		wp_safe_redirect( $next_url );
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'      => 'image-compressor',
				'ic_notice' => 'restore_all_complete',
				'restored'  => $restored,
				'errors'    => $errors,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

