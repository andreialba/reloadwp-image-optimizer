<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'media_row_actions', 'image_compressor_media_row_actions', 10, 2 );

/**
 * @param array   $actions
 * @param WP_Post $post
 * @return array
 */
function image_compressor_media_row_actions( $actions, $post ) {
	if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! image_compressor_current_user_can_manage_media() ) {
		return $actions;
	}

	$file        = get_attached_file( $post->ID );
	$was_skipped = image_compressor_attachment_was_skipped_for_current_settings( $post->ID );
	if ( $file && '' !== image_compressor_get_supported_extension( $file ) && ! $was_skipped ) {
		$actions['ic_optimize'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'        => 'image_compressor_optimize_attachment',
							'attachment_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'image_compressor_optimize_attachment_' . $post->ID
				)
			),
			esc_html__( 'Optimize', 'image-compressor' )
		);
	}

	if ( image_compressor_attachment_has_backup( $post->ID ) ) {
		$actions['ic_restore'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'        => 'image_compressor_restore_attachment',
							'attachment_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'image_compressor_restore_attachment_' . $post->ID
				)
			),
			esc_html__( 'Restore Original', 'image-compressor' )
		);
	}

	return $actions;
}

// Add columns to the Media Library list view.
add_filter( 'manage_media_columns', 'image_compressor_media_column' );
add_action( 'manage_media_custom_column', 'image_compressor_media_column_content', 10, 2 );

/**
 * @param array $columns
 * @return array
 */
function image_compressor_media_column( $columns ) {
	$columns['ic_optimizer'] = __( 'Optimizer', 'image-compressor' );
	return $columns;
}

/**
 * @param string $column_name
 * @param int    $post_id
 * @return void
 */
function image_compressor_media_column_content( $column_name, $post_id ) {
	if ( 'ic_optimizer' !== $column_name ) {
		return;
	}

	$file        = get_attached_file( $post_id );
	$ext         = $file ? image_compressor_get_supported_extension( $file ) : '';
	$has_backup  = image_compressor_attachment_has_backup( $post_id );
	$was_skipped = image_compressor_attachment_was_skipped_for_current_settings( $post_id );

	// Show dash only for file types we can neither optimize nor restore.
	if ( '' === $ext && ! $has_backup ) {
		echo '&mdash;';
		return;
	}

	if ( $has_backup ) {
		$reduction_text = '';
		$backup_rel     = (string) get_post_meta( $post_id, '_ic_original_backup_rel', true );
		$uploads        = image_compressor_get_upload_dir();
		$backup_abs     = '' !== $backup_rel ? trailingslashit( $uploads['basedir'] ) . ltrim( $backup_rel, '/\\' ) : '';

		if ( $file && file_exists( $file ) && $backup_abs && file_exists( $backup_abs ) ) {
			$current_bytes  = filesize( $file );
			$original_bytes = filesize( $backup_abs );

			if ( false !== $current_bytes && false !== $original_bytes && $original_bytes > 0 && $current_bytes < $original_bytes ) {
				$reduction_text = sprintf(
					/* translators: %s: percentage reduced */
					__( ' (reduced by %s%%)', 'image-compressor' ),
					number_format_i18n( ( ( $original_bytes - $current_bytes ) / $original_bytes ) * 100, 1 )
				);
			}
		}

		echo '<span class="ic-media-status is-optimized">' . esc_html__( 'Optimized', 'image-compressor' ) . esc_html( $reduction_text ) . '</span>';

		if ( image_compressor_current_user_can_manage_media() ) {
			$restore_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'image_compressor_restore_attachment',
						'attachment_id' => $post_id,
					),
					admin_url( 'admin-post.php' )
				),
				'image_compressor_restore_attachment_' . $post_id
			);
			echo '<br><a class="ic-media-link is-restore" href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore Original', 'image-compressor' ) . '</a>';
		}
	} else {
		if ( $was_skipped ) {
			echo '<span class="ic-media-status is-optimized">' . esc_html__( 'Already optimal', 'image-compressor' ) . '</span>';
		} else {
			echo '<span class="ic-media-status is-pending">' . esc_html__( 'Not optimized', 'image-compressor' ) . '</span>';
		}

		if ( '' !== $ext && image_compressor_current_user_can_manage_media() && ! $was_skipped ) {
			$optimize_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'image_compressor_optimize_attachment',
						'attachment_id' => $post_id,
					),
					admin_url( 'admin-post.php' )
				),
				'image_compressor_optimize_attachment_' . $post_id
			);
			echo '<br><a class="ic-media-link" href="' . esc_url( $optimize_url ) . '">' . esc_html__( 'Optimize', 'image-compressor' ) . '</a>';
		}
	}
}
