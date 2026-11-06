<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formconfirmed - held messages.
 *
 * A message that was not passed (blocked, flagged, or no clear answer and no backup check) is kept
 * on this site for DAYS days, with the reason, so a person stopped by mistake is not lost: one
 * click sends it to the site's e-mail address. Comments are not kept here (WordPress holds them for
 * moderation or files them as spam itself), registrations and logins never (they carry passwords).
 *
 * Stored as posts of the type formconfirmed_held (not shown anywhere on the site): status private
 * while held, draft once delivered; the form fields in the post meta _formconfirmed_pairs. If the formexposed plugin is active, its quarantine
 * keeps them instead - one place for every stopped message.
 */
class Formconfirmed_Held {

	const TYPE    = 'formconfirmed_held';
	const DAYS    = 14;
	const PER_DAY = 200;
	const CRON    = 'formconfirmed_purge';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_type' ) );
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_formconfirmed_release', array( __CLASS__, 'action_release' ) );
			add_action( 'admin_post_formconfirmed_delete', array( __CLASS__, 'action_delete' ) );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			self::schedule();
		}
	}

	/** The private post type the held messages are stored as (on init: its label is translated). */
	public static function register_type() {
		register_post_type( self::TYPE, array(
			'public'              => false,
			'show_ui'             => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_in_rest'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			'label'               => __( 'Held messages', 'formconfirmed' ),
		) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** The quarantine of the formexposed plugin keeps them, if it is there. */
	public static function external() {
		return class_exists( 'Formexposed_Quarantine' ) && method_exists( 'Formexposed_Quarantine', 'ready' ) && Formexposed_Quarantine::ready();
	}

	/**
	 * Keep a message. $pairs: list of array(label, value). $reason: fc_block, fc_flag or fc_unavailable.
	 */
	public static function store( $source, $form, $reason, $pairs ) {
		if ( self::external() ) {
			return (bool) Formexposed_Quarantine::store( $source, $form, $reason, $pairs );
		}
		$heute = new WP_Query( array(
			'post_type'      => self::TYPE,
			'post_status'    => 'private',
			'date_query'     => array( array( 'after' => '24 hours ago' ) ),
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
		) );
		if ( (int) $heute->found_posts >= self::PER_DAY ) {
			return false;   // a flood: the counters still show it, the store stays small
		}
		$clean = array();
		foreach ( (array) $pairs as $p ) {
			$clean[] = array( mb_substr( sanitize_text_field( (string) $p[0] ), 0, 100 ), mb_substr( sanitize_textarea_field( (string) $p[1] ), 0, 5000 ) );
		}
		$id = wp_insert_post( array(
			'post_type'   => self::TYPE,
			'post_status' => 'private',
			'post_title'  => mb_substr( sanitize_text_field( (string) $form ), 0, 190 ),
			'meta_input'  => array(
				'_formconfirmed_source' => sanitize_key( $source ),
				'_formconfirmed_reason' => sanitize_key( $reason ),
				'_formconfirmed_pairs'  => $clean,
			),
		), true );
		return ! is_wp_error( $id ) && $id > 0;
	}

	/** Held messages, newest first. */
	public static function entries( $limit = 50 ) {
		$q   = new WP_Query( array(
			'post_type'      => self::TYPE,
			'post_status'    => array( 'private', 'draft' ),
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'       => (int) $p->ID,
				'form'     => (string) $p->post_title,
				'date'     => (string) $p->post_date_gmt,
				'source'   => (string) get_post_meta( $p->ID, '_formconfirmed_source', true ),
				'reason'   => (string) get_post_meta( $p->ID, '_formconfirmed_reason', true ),
				'pairs'    => (array) get_post_meta( $p->ID, '_formconfirmed_pairs', true ),
				'released' => 'draft' === $p->post_status ? (int) get_post_meta( $p->ID, '_formconfirmed_released', true ) : 0,
			);
		}
		return $out;
	}

	/** Number of held messages not delivered yet. */
	public static function count_open() {
		if ( self::external() ) {
			return method_exists( 'Formexposed_Quarantine', 'count_open' ) ? (int) Formexposed_Quarantine::count_open() : 0;
		}
		$q = new WP_Query( array(
			'post_type'      => self::TYPE,
			'post_status'    => 'private',
			'fields'         => 'ids',
			'posts_per_page' => 1,
		) );
		return (int) $q->found_posts;
	}

	/** Why a message was held, in words. */
	public static function reason_text( $key ) {
		switch ( $key ) {
			case 'fc_block':
				return __( 'The service blocked it.', 'formconfirmed' );
			case 'fc_flag':
				return __( 'The service flagged it as suspicious.', 'formconfirmed' );
			default:
				return __( 'The service gave no clear answer and the backup check did not pass.', 'formconfirmed' );
		}
	}

	/** Where delivered messages go: the site's e-mail address (filter formconfirmed_held_recipient). */
	public static function recipient() {
		return (string) apply_filters( 'formconfirmed_held_recipient', get_option( 'admin_email' ) );
	}

	/** Sends a held message to the site's e-mail address. */
	public static function release( $id ) {
		$p = get_post( (int) $id );
		if ( ! $p || self::TYPE !== $p->post_type ) {
			return false;
		}
		$pairs = (array) get_post_meta( $p->ID, '_formconfirmed_pairs', true );
		$text  = '';
		$reply = '';
		foreach ( $pairs as $pair ) {
			$text .= $pair[0] . ': ' . $pair[1] . "\n";
			if ( '' === $reply && is_email( trim( (string) $pair[1] ) ) ) {
				$reply = trim( (string) $pair[1] );
			}
		}
		/* translators: 1: date and time the message was sent */
		$text .= "\n-- \n" . sprintf( __( 'Sent on %1$s, held by formconfirmed and delivered by hand.', 'formconfirmed' ), get_date_from_gmt( $p->post_date_gmt ) ) . "\n";
		$kopf  = '' !== $reply ? array( 'Reply-To: ' . $reply ) : array();
		/* translators: 1: site name, 2: form name */
		$subject = sprintf( __( '[%1$s] Released message: %2$s', 'formconfirmed' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->post_title );
		if ( ! wp_mail( self::recipient(), $subject, $text, $kopf ) ) {
			return false;
		}
		update_post_meta( $p->ID, '_formconfirmed_released', time() );
		wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );   // draft = delivered, private = still held
		return true;
	}

	/** Removes messages older than DAYS days (daily). */
	public static function purge() {
		$q = new WP_Query( array(
			'post_type'      => self::TYPE,
			'post_status'    => array( 'private', 'draft' ),
			'fields'         => 'ids',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'date_query'     => array( array( 'before' => self::DAYS . ' days ago' ) ),
		) );
		foreach ( $q->posts as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $q->posts );
	}

	public static function action_release() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_release' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$ok = self::release( $id );
		set_transient( 'formconfirmed_msg', $ok ? array( 'ok', __( 'Delivered to the site\'s e-mail address.', 'formconfirmed' ) ) : array( 'error', __( 'The message could not be sent (check the e-mail setup of this site).', 'formconfirmed' ) ), 120 );
		wp_safe_redirect( Formconfirmed_Admin::url( array( 'tab' => 'held' ) ) );
		exit;
	}

	public static function action_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_delete' );
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$p  = get_post( $id );
		if ( $p && self::TYPE === $p->post_type ) {
			wp_delete_post( $id, true );
		}
		set_transient( 'formconfirmed_msg', array( 'ok', __( 'Deleted.', 'formconfirmed' ) ), 120 );
		wp_safe_redirect( Formconfirmed_Admin::url( array( 'tab' => 'held' ) ) );
		exit;
	}
}
