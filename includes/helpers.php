
<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return void
 */
function image_compressor_activate() {
	add_option( 'image_compressor_output_format', 'webp' );
	add_option( 'image_compressor_quality', 82 );
	add_option( 'image_compressor_strip_exif', '1' );
	add_option( 'image_compressor_max_width', 0 );
	add_option( 'image_compressor_max_height', 0 );
}

/**
 * @return void
 */
function image_compressor_uninstall() {
	delete_option( 'image_compressor_output_format' );
	delete_option( 'image_compressor_quality' );
	delete_option( 'image_compressor_strip_exif' );
	delete_option( 'image_compressor_max_width' );
	delete_option( 'image_compressor_max_height' );
	delete_option( 'image_compressor_stats' );
}

/**
 * @return array{basedir:string,baseurl:string,subdir:string}
 */
function image_compressor_get_upload_dir() {
	$uploads = wp_get_upload_dir();

	return array(
		'basedir' => (string) ( $uploads['basedir'] ?? '' ),
		'baseurl' => (string) ( $uploads['baseurl'] ?? '' ),
		'subdir'  => (string) ( $uploads['subdir'] ?? '' ),
	);
}

/**
 * @return string
 */
function image_compressor_get_backup_root() {
	$uploads = image_compressor_get_upload_dir();

	return trailingslashit( $uploads['basedir'] ) . 'image-compressor-backups';
}

/**
 * @param string $base_dir
 * @param string $path
 * @return bool
 */
function image_compressor_is_path_within_dir( $base_dir, $path ) {
	$base_dir = wp_normalize_path( trailingslashit( (string) $base_dir ) );
	$path     = wp_normalize_path( (string) $path );

	return '' !== $base_dir && '' !== $path && 0 === strpos( $path, $base_dir );
}

/**
 * @param string $directory
 * @param string $stop_at
 * @return void
 */
function image_compressor_cleanup_empty_dirs( $directory, $stop_at ) {
	$directory = wp_normalize_path( (string) $directory );
	$stop_at   = wp_normalize_path( trailingslashit( (string) $stop_at ) );

	while ( '' !== $directory && image_compressor_is_path_within_dir( $stop_at, $directory ) && untrailingslashit( $directory ) !== untrailingslashit( $stop_at ) ) {
		if ( ! is_dir( $directory ) ) {
			$directory = wp_normalize_path( dirname( $directory ) );
			continue;
		}

		$iterator = new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS );
		if ( $iterator->valid() ) {
			break;
		}

		rmdir( $directory );
		$directory = wp_normalize_path( dirname( $directory ) );
	}
}

/**
 * @return bool
 */
function image_compressor_ensure_backup_root() {
	return wp_mkdir_p( image_compressor_get_backup_root() );
}

/**
 * @return void
 */
function image_compressor_require_media_dependencies() {
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}

/**
 * Capability used for image optimization actions in the Media Library.
 *
 * @return string
 */
function image_compressor_get_media_capability() {
	return (string) apply_filters( 'image_compressor_media_capability', 'upload_files' );
}

/**
 * Capability used for plugin settings and destructive backup management.
 *
 * @return string
 */
function image_compressor_get_settings_capability() {
	return (string) apply_filters( 'image_compressor_settings_capability', 'manage_options' );
}

/**
 * @return bool
 */
function image_compressor_current_user_can_manage_media() {
	return current_user_can( image_compressor_get_media_capability() );
}

/**
 * @return bool
 */
function image_compressor_current_user_can_manage_settings() {
	return current_user_can( image_compressor_get_settings_capability() );
}

/**
 * @return string
 */
function image_compressor_get_current_settings_signature() {
	$settings = array(
		'format'     => (string) get_option( 'image_compressor_output_format', 'webp' ),
		'quality'    => (int) get_option( 'image_compressor_quality', 82 ),
		'strip_exif' => (string) get_option( 'image_compressor_strip_exif', '1' ),
		'max_width'  => (int) get_option( 'image_compressor_max_width', 0 ),
		'max_height' => (int) get_option( 'image_compressor_max_height', 0 ),
	);

	return md5( wp_json_encode( $settings ) );
}

/**
 * @param int $attachment_id
 * @return void
 */
function image_compressor_mark_attachment_skipped( $attachment_id ) {
	update_post_meta( $attachment_id, '_ic_last_attempt_signature', image_compressor_get_current_settings_signature() );
}

/**
 * @param int $attachment_id
 * @return void
 */
function image_compressor_clear_attachment_skipped( $attachment_id ) {
	delete_post_meta( $attachment_id, '_ic_last_attempt_signature' );
}

/**
 * @param int $attachment_id
 * @return bool
 */
function image_compressor_attachment_was_skipped_for_current_settings( $attachment_id ) {
	return image_compressor_get_current_settings_signature() === (string) get_post_meta( $attachment_id, '_ic_last_attempt_signature', true );
}

/**
 * @return void
 */
function image_compressor_enable_quality_filters() {
	$GLOBALS['image_compressor_quality_filters_enabled'] = true;
}

/**
 * @return void
 */
function image_compressor_disable_quality_filters() {
	$GLOBALS['image_compressor_quality_filters_enabled'] = false;
}

/**
 * @return bool
 */
function image_compressor_quality_filters_enabled() {
	return ! empty( $GLOBALS['image_compressor_quality_filters_enabled'] );
}

/**
 * @param string $path
 * @return string
 */
function image_compressor_get_supported_extension( $path ) {
	$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

	return in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ? $ext : '';
}

/**
 * @param string $ext
 * @return array{mime:string,format:string}
 */
function image_compressor_get_output_target( $ext ) {
	$output_format = get_option( 'image_compressor_output_format', 'webp' );

	if ( 'webp' === $output_format && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		$output_format = 'none';
	}

	if ( 'webp' === $output_format ) {
		return array(
			'mime'   => 'image/webp',
			'format' => 'webp',
		);
	}

	return array(
		'mime'   => ( 'png' === $ext ) ? 'image/png' : 'image/jpeg',
		'format' => 'none',
	);
}

/**
 * @param int $count
 * @param int $saved_bytes
 * @return void
 */
function image_compressor_record_stats( $count, $saved_bytes ) {
	$stats                = get_option( 'image_compressor_stats', array( 'count' => 0, 'saved_bytes' => 0 ) );
	$stats['count']       = (int) ( $stats['count'] ?? 0 ) + max( 0, (int) $count );
	$stats['saved_bytes'] = (int) ( $stats['saved_bytes'] ?? 0 ) + max( 0, (int) $saved_bytes );

	update_option( 'image_compressor_stats', $stats, false );
}

/**
 * @param string $source_path
 * @param string $label
 * @return string|false
 */
function image_compressor_create_temp_backup( $source_path, $label ) {
	$temp_path = wp_tempnam( 'ic-orig-' . sanitize_file_name( $label ) );
	if ( ! $temp_path ) {
		return false;
	}

	if ( ! copy( $source_path, $temp_path ) ) {
		if ( file_exists( $temp_path ) ) {
			unlink( $temp_path );
		}
		return false;
	}

	return $temp_path;
}

/**
 * @param string $source_path
 * @param string $label
 * @return array<string,mixed>|WP_Error
 */
function image_compressor_optimize_image_file( $source_path, $label ) {
	$ext = image_compressor_get_supported_extension( $label ?: $source_path );
	if ( '' === $ext || ! file_exists( $source_path ) ) {
		return new WP_Error( 'ic_unsupported', __( 'Unsupported image type.', 'image-compressor' ) );
	}

	$target = image_compressor_get_output_target( $ext );
	if ( ! wp_image_editor_supports( array( 'mime_type' => $target['mime'] ) ) ) {
		return new WP_Error( 'ic_editor_unsupported', __( 'This server cannot write the requested image format.', 'image-compressor' ) );
	}

	$strip_exif = get_option( 'image_compressor_strip_exif', '1' ) === '1';
	if ( ! $strip_exif ) {
		add_filter( 'image_strip_meta', '__return_false' );
	}

	image_compressor_enable_quality_filters();
	$editor = wp_get_image_editor( $source_path );

	if ( ! $strip_exif ) {
		remove_filter( 'image_strip_meta', '__return_false' );
	}

	if ( is_wp_error( $editor ) ) {
		image_compressor_disable_quality_filters();
		return $editor;
	}

	$max_w = (int) get_option( 'image_compressor_max_width', 0 );
	$max_h = (int) get_option( 'image_compressor_max_height', 0 );
	if ( $max_w > 0 || $max_h > 0 ) {
		$size        = $editor->get_size();
		$cur_w       = isset( $size['width'] ) ? (int) $size['width'] : 0;
		$cur_h       = isset( $size['height'] ) ? (int) $size['height'] : 0;
		$need_resize = ( $max_w > 0 && $cur_w > $max_w ) || ( $max_h > 0 && $cur_h > $max_h );
		if ( $need_resize ) {
			$editor->resize( $max_w ?: null, $max_h ?: null, false );
		}
	}

	$quality = min( 100, max( 1, image_compressor_filter_quality() ) );
	$editor->set_quality( $quality );

	$temp_path = wp_tempnam( 'img-' . sanitize_file_name( wp_basename( $label ) ) );
	if ( ! $temp_path ) {
		image_compressor_disable_quality_filters();
		return new WP_Error( 'ic_temp_file', __( 'Could not create a temporary file for optimization.', 'image-compressor' ) );
	}

	$saved = $editor->save( $temp_path, $target['mime'] );
	image_compressor_disable_quality_filters();
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_readable( $saved['path'] ) ) {
		if ( file_exists( $temp_path ) ) {
			unlink( $temp_path );
		}
		return is_wp_error( $saved ) ? $saved : new WP_Error( 'ic_save_failed', __( 'Failed to save optimized image.', 'image-compressor' ) );
	}

	$original_size = filesize( $source_path );
	$new_size      = filesize( $saved['path'] );
	if ( false === $new_size ) {
		unlink( $saved['path'] );
		if ( $temp_path !== $saved['path'] && file_exists( $temp_path ) ) {
			unlink( $temp_path );
		}
		return new WP_Error( 'ic_size_failed', __( 'Could not read optimized image size.', 'image-compressor' ) );
	}

	if ( $original_size > 0 && $new_size >= $original_size ) {
		unlink( $saved['path'] );
		if ( $temp_path !== $saved['path'] && file_exists( $temp_path ) ) {
			unlink( $temp_path );
		}
		return array(
			'changed'       => false,
			'output_format' => $target['format'],
			'output_mime'   => $target['mime'],
			'original_size' => (int) $original_size,
			'new_size'      => (int) $original_size,
			'saved_bytes'   => 0,
		);
	}

	return array(
		'changed'       => true,
		'temp_path'     => $saved['path'],
		'output_format' => $target['format'],
		'output_mime'   => $target['mime'],
		'original_size' => (int) $original_size,
		'new_size'      => (int) $new_size,
		'saved_bytes'   => max( 0, (int) $original_size - (int) $new_size ),
	);
}

/**
 * @param int    $attachment_id
 * @param string $source_path
 * @param string $original_relative_path
 * @return true|WP_Error
 */
function image_compressor_store_attachment_backup( $attachment_id, $source_path, $original_relative_path ) {
	$attachment_id = (int) $attachment_id;
	if ( $attachment_id <= 0 || ! file_exists( $source_path ) ) {
		return new WP_Error( 'ic_backup_missing_source', __( 'Backup source file is missing.', 'image-compressor' ) );
	}

	$existing_rel = (string) get_post_meta( $attachment_id, '_ic_original_backup_rel', true );
	if ( '' !== $existing_rel ) {
		$existing_abs = trailingslashit( image_compressor_get_upload_dir()['basedir'] ) . ltrim( $existing_rel, '/\\' );
		if ( file_exists( $existing_abs ) ) {
			if ( '' === get_post_meta( $attachment_id, '_ic_original_attached_rel', true ) && '' !== $original_relative_path ) {
				update_post_meta( $attachment_id, '_ic_original_attached_rel', $original_relative_path );
			}
			return true;
		}
	}

	if ( ! image_compressor_ensure_backup_root() ) {
		return new WP_Error( 'ic_backup_dir', __( 'Could not create the backup directory.', 'image-compressor' ) );
	}

	$normalized_rel = ltrim( wp_normalize_path( (string) $original_relative_path ), '/' );
	$path_segments  = array_values(
		array_filter(
			explode( '/', $normalized_rel ),
			static function ( $segment ) {
				return '' !== $segment && '.' !== $segment && '..' !== $segment;
			}
		)
	);

	// Preserve the full relative uploads path to avoid collisions in nested/custom directories.
	if ( ! empty( $path_segments ) ) {
		$backup_rel = 'image-compressor-backups/' . implode( '/', $path_segments );
	} else {
		$ext        = strtolower( (string) pathinfo( $source_path, PATHINFO_EXTENSION ) );
		$backup_rel = 'image-compressor-backups/attachment-' . $attachment_id . '/original' . ( $ext ? '.' . $ext : '' );
	}

	$backup_abs = trailingslashit( image_compressor_get_upload_dir()['basedir'] ) . $backup_rel;
	if ( ! image_compressor_is_path_within_dir( image_compressor_get_upload_dir()['basedir'], $backup_abs ) ) {
		return new WP_Error( 'ic_backup_path_invalid', __( 'The backup path is invalid.', 'image-compressor' ) );
	}

	if ( ! wp_mkdir_p( dirname( $backup_abs ) ) ) {
		return new WP_Error( 'ic_backup_subdir', __( 'Could not create the attachment backup directory.', 'image-compressor' ) );
	}

	if ( ! copy( $source_path, $backup_abs ) ) {
		return new WP_Error( 'ic_backup_copy_failed', __( 'Could not save the original image backup.', 'image-compressor' ) );
	}

	update_post_meta( $attachment_id, '_ic_original_backup_rel', $backup_rel );
	if ( '' !== $original_relative_path ) {
		update_post_meta( $attachment_id, '_ic_original_attached_rel', $original_relative_path );
	}

	return true;
}

/**
 * @param string $file_path
 * @param string $new_extension
 * @return string
 */
function image_compressor_build_target_path( $file_path, $new_extension ) {
	$info = pathinfo( $file_path );

	return trailingslashit( $info['dirname'] ) . $info['filename'] . '.' . $new_extension;
}

/**
 * @param string $source_path
 * @param string $target_path
 * @return bool
 */
function image_compressor_move_file( $source_path, $target_path ) {
	if ( $source_path === $target_path ) {
		return true;
	}

	if ( file_exists( $target_path ) && ! unlink( $target_path ) ) {
		return false;
	}

	$moved = rename( $source_path, $target_path );
	if ( $moved ) {
		return true;
	}

	$moved = copy( $source_path, $target_path );
	if ( $moved ) {
		unlink( $source_path );
	}

	return $moved;
}

/**
 * @return void
 */
function image_compressor_queue_upload_backup( $backup_info ) {
	if ( ! isset( $GLOBALS['image_compressor_upload_backup_queue'] ) || ! is_array( $GLOBALS['image_compressor_upload_backup_queue'] ) ) {
		$GLOBALS['image_compressor_upload_backup_queue'] = array();
	}

	$GLOBALS['image_compressor_upload_backup_queue'][] = $backup_info;
}

/**
 * @return array<string,mixed>|null
 */
function image_compressor_shift_upload_backup() {
	if ( empty( $GLOBALS['image_compressor_upload_backup_queue'] ) || ! is_array( $GLOBALS['image_compressor_upload_backup_queue'] ) ) {
		return null;
	}

	return array_shift( $GLOBALS['image_compressor_upload_backup_queue'] );
}

/**
 * @param string $final_path
 * @param array  $backup_info
 * @return void
 */
function image_compressor_store_pending_upload_backup( $final_path, $backup_info ) {
	if ( ! isset( $GLOBALS['image_compressor_pending_upload_backups'] ) || ! is_array( $GLOBALS['image_compressor_pending_upload_backups'] ) ) {
		$GLOBALS['image_compressor_pending_upload_backups'] = array();
	}

	$GLOBALS['image_compressor_pending_upload_backups'][ $final_path ] = $backup_info;
}

/**
 * @param string $final_path
 * @return array<string,mixed>|null
 */
function image_compressor_take_pending_upload_backup( $final_path ) {
	if ( empty( $GLOBALS['image_compressor_pending_upload_backups'][ $final_path ] ) ) {
		return null;
	}

	$backup_info = $GLOBALS['image_compressor_pending_upload_backups'][ $final_path ];
	unset( $GLOBALS['image_compressor_pending_upload_backups'][ $final_path ] );

	return is_array( $backup_info ) ? $backup_info : null;
}

