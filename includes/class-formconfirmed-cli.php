<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WP-CLI: wp formconfirmed status | test
 */
class Formconfirmed_CLI {

	/**
	 * Shows whether the protection is active, which forms it covers and the counters.
	 *
	 * ## EXAMPLES
	 *
	 *     wp formconfirmed status
	 */
	public function status() {
		$o = Formconfirmed_Core::opt();
		WP_CLI::log( 'Active:        ' . ( Formconfirmed_Core::on() ? ( Formconfirmed_Core::paused() ? 'waiting (update formexposed to 1.10.0+)' : 'yes' ) : 'no (keys missing)' ) );
		WP_CLI::log( 'Service:       ' . FORMCONFIRMED_API );
		WP_CLI::log( 'Forms:         ' . implode( ', ', Formconfirmed_Core::active_adapters() ) );
		WP_CLI::log( 'Unclear:       ' . $o['uncertain'] );
		WP_CLI::log( 'Backup check:  ' . ( Formconfirmed_Backup::delegated() ? 'formexposed protection layer' : ( Formconfirmed_Backup::own() ? 'own' : 'off' ) ) );
		WP_CLI::log( 'Held (open):   ' . Formconfirmed_Held::count_open() );
		$m = Formconfirmed_Held::maintenance_status();
		WP_CLI::log( 'Cleanup query: ' . ( $m['available'] ? 'ok' : 'unavailable' ) );
		WP_CLI::log( 'Overdue held:  ' . $m['overdue'] );
		WP_CLI::log( 'Cleanup OK:    ' . ( $m['last_success'] ? gmdate( 'c', $m['last_success'] ) : 'not run yet' ) );
		WP_CLI::log( 'Cleanup next:  ' . ( $m['next_run'] ? gmdate( 'c', $m['next_run'] ) : 'not scheduled' ) );
		foreach ( $o['stats'] as $k => $v ) {
			WP_CLI::log( str_pad( $k . ':', 15 ) . (int) $v );
		}
	}

	/**
	 * Runs one bounded quarantine cleanup batch (remaining work is scheduled).
	 *
	 * ## EXAMPLES
	 *
	 *     wp formconfirmed cleanup
	 */
	public function cleanup() {
		$n = Formconfirmed_Held::purge();
		$s = Formconfirmed_Held::maintenance_status();
		if ( ! $s['available'] || $s['failed'] ) { WP_CLI::warning( 'Cleanup could not finish; check storage and the next scheduled run.' ); }
		WP_CLI::log( 'Deleted in this batch: ' . (int) $n );
		WP_CLI::log( 'Overdue remaining:     ' . $s['overdue'] );
	}

	/**
	 * Asks the service whether it answers and accepts the secret key, and runs the self-test of the backup check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp formconfirmed test
	 */
	public function test() {
		$h = wp_remote_get( FORMCONFIRMED_API . '/health', array( 'timeout' => Formconfirmed_Core::TIMEOUT ) );
		if ( is_wp_error( $h ) || 200 !== (int) wp_remote_retrieve_response_code( $h ) ) {
			WP_CLI::warning( 'The service did not answer.' );
		} else {
			$r     = wp_remote_post( FORMCONFIRMED_API . '/verify', array(
				'timeout' => Formconfirmed_Core::TIMEOUT,
				'body'    => array( 'secret' => Formconfirmed_Core::opt()['secret'], 'response' => '' ),
			) );
			$j     = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
			$codes = is_array( $j ) && isset( $j['error-codes'] ) ? array_filter( (array) $j['error-codes'], 'is_string' ) : array();
			if ( in_array( 'missing-input-response', $codes, true ) ) {
				WP_CLI::log( 'Service: answers and accepts the secret key.' );
			} else {
				WP_CLI::warning( 'Service: ' . Formconfirmed_Admin::problem_text( implode( ',', $codes ) ) );
			}
		}
		if ( Formconfirmed_Backup::own() ) {
			$t = Formconfirmed_Backup::self_test();
			if ( is_wp_error( $t ) ) {
				WP_CLI::warning( 'Backup check: ' . $t->get_error_message() );
			} else {
				WP_CLI::log( 'Backup check: self-test passed.' );
			}
		}
		WP_CLI::success( 'Done.' );
	}
}
