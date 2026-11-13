<?php
/**
 * Plugin Name:       formconfirmed – Invisible spam protection for forms
 * Plugin URI:        https://formconfirmed.com/
 * Description:       Invisible spam protection for Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Formidable, comments, registration and login: proof of work, timing and trap-field signals and an optional message check, hosted in the EU. No puzzles, no cookies.
 * Version:           1.6.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            LW IT Solutions
 * Author URI:        https://www.lukaswojcik.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formconfirmed
 *
 * What the plugin sends to the service, and when, is described in readme.txt ("External services")
 * and at the top of includes/class-formconfirmed.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FORMCONFIRMED_VERSION', '1.6.0' );
define( 'FORMCONFIRMED_FILE', __FILE__ );

// The address of the service (includes/services.json). wp-config.php may override it for staging:
// define( 'FORMCONFIRMED_API', '<address of the staging service>/v1' );
$formconfirmed_services = wp_json_file_decode( __DIR__ . '/includes/services.json', array( 'associative' => true ) );
$formconfirmed_services = is_array( $formconfirmed_services ) ? $formconfirmed_services : array();
if ( ! defined( 'FORMCONFIRMED_API' ) ) {
	define( 'FORMCONFIRMED_API', isset( $formconfirmed_services['api'] ) ? (string) $formconfirmed_services['api'] : '' );
}
if ( ! defined( 'FORMCONFIRMED_APP' ) ) {
	define( 'FORMCONFIRMED_APP', isset( $formconfirmed_services['app'] ) ? (string) $formconfirmed_services['app'] : '' );
}
unset( $formconfirmed_services );

require_once __DIR__ . '/includes/class-formconfirmed.php';
require_once __DIR__ . '/includes/class-formconfirmed-backup.php';
require_once __DIR__ . '/includes/class-formconfirmed-held.php';
require_once __DIR__ . '/includes/class-formconfirmed-admin.php';
require_once __DIR__ . '/includes/class-formconfirmed-connect.php';
require_once __DIR__ . '/includes/functions.php';

register_activation_hook( __FILE__, array( 'Formconfirmed_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Formconfirmed_Held', 'unschedule' ) );
// Priority 25: after the protection layer of the formexposed plugin (20), if it is installed, so the
// fallback knows whether that layer passed the same submission.
add_action( 'plugins_loaded', array( 'Formconfirmed_Core', 'init' ), 25 );
add_action( 'plugins_loaded', array( 'Formconfirmed_Backup', 'init' ), 25 );
add_action( 'plugins_loaded', array( 'Formconfirmed_Held', 'init' ), 25 );
add_action( 'plugins_loaded', array( 'Formconfirmed_Admin', 'init' ), 25 );
add_action( 'plugins_loaded', array( 'Formconfirmed_Connect', 'init' ), 25 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-formconfirmed-cli.php';
	WP_CLI::add_command( 'formconfirmed', 'Formconfirmed_CLI' );
}

register_deactivation_hook( __FILE__, array( 'Formconfirmed_Core', 'stop_reporting' ) );
