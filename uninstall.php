<?php
/**
 * Removes everything formconfirmed stored: its settings, the held messages and the daily clean-up.
 * The keys stay valid in your account; delete the protected site there if you no longer need it.
 * Short-lived values (rate limit, used answers of the backup check) expire on their own within an hour.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'formconfirmed_settings' );
delete_option( 'formconfirmed_notice_off' );
delete_transient( 'formconfirmed_msg' );
wp_clear_scheduled_hook( 'formconfirmed_purge' );

do {
	$formconfirmed_ids = get_posts( array(
		'post_type'      => 'formconfirmed_held',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 200,
		'no_found_rows'  => true,
	) );
	foreach ( $formconfirmed_ids as $formconfirmed_id ) {
		wp_delete_post( (int) $formconfirmed_id, true );
	}
} while ( $formconfirmed_ids );
