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
	const MAX = 2000;
	const MAX_FIELDS = 30;
	const MAX_BYTES = 32768;
	const CRON    = 'formconfirmed_purge';
	const FOLLOWUP = 'formconfirmed_purge_followup';
	const PURGE_BATCH = 100;
	const PURGE_STATUS = 'formconfirmed_purge_status';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_type' ) );
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		add_action( self::FOLLOWUP, array( __CLASS__, 'purge' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'health_tests' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_formconfirmed_release', array( __CLASS__, 'action_release' ) );
			add_action( 'admin_post_formconfirmed_feedback', array( __CLASS__, 'action_feedback' ) );
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
		wp_clear_scheduled_hook( self::FOLLOWUP );
	}

	/** The quarantine of the formexposed plugin keeps them, if it is there. */
	public static function external() {
		return class_exists( 'Formexposed_Quarantine' ) && method_exists( 'Formexposed_Quarantine', 'ready' ) && Formexposed_Quarantine::ready();
	}

	/**
	 * Keep a message. $pairs: list of array(label, value). $reason: fc_block, fc_flag or fc_unavailable.
	 */
	/** Bounded, scalar field pairs only; credentials are never a held contact message. */
	public static function clean_pairs( $pairs ) {
		$clean = array();
		foreach ( array_slice( (array) $pairs, 0, self::MAX_FIELDS ) as $p ) {
			if ( ! is_array( $p ) || ! isset( $p[0], $p[1] ) || ! is_scalar( $p[0] ) || ! is_scalar( $p[1] ) ) {
				continue;
			}
			$label = sanitize_text_field( mb_substr( (string) $p[0], 0, 100 ) );
			if ( preg_match( '/pass(word|wort)?|secret|token|nonce|authorization|cvv/i', $label ) ) {
				continue;
			}
			$value = sanitize_textarea_field( mb_substr( (string) $p[1], 0, 5000 ) );
			$clean[] = array( $label, $value );
			while ( strlen( (string) wp_json_encode( $clean ) ) > self::MAX_BYTES ) {
				$i = count( $clean ) - 1;
				if ( '' === $clean[$i][1] ) { array_pop( $clean ); break; }
				$clean[$i][1] = mb_substr( $clean[$i][1], 0, (int) floor( mb_strlen( $clean[$i][1] ) / 2 ) );
			}
		}
		return $clean;
	}
	public static function store( $source, $form, $reason, $pairs, $feedback = array() ) {
		global $wpdb;
		if ( in_array( $source, array( 'register', 'woo_register', 'login' ), true ) ) {
			return false;
		}
		$clean = self::clean_pairs( $pairs );
		if ( ! $clean ) { return false; }
		// The DB connection owns the admission lock. Count and insert are one serialized operation;
		// no transient/object-cache race, no new table and no public lock identifier.
		$lock = 'fc-held-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
		if ( ! self::lock( $lock, 1 ) ) { return false; }
		try {
			if ( self::external() ) {
				if ( ! method_exists( 'Formexposed_Quarantine', 'counts' ) ) { return false; }
				$counts = Formexposed_Quarantine::counts( time() );
				if ( (int) $counts[0] >= self::MAX ) { return false; }
				return (bool) Formexposed_Quarantine::store( $source, $form, $reason, $clean );
			}
			// Include delivered entries: releasing a message must not reopen admission slots.
			if ( self::count_entries() >= self::MAX || self::count_entries( time() - DAY_IN_SECONDS, true ) >= self::PER_DAY ) { return false; }
			$id = wp_insert_post( array(
				'post_type' => self::TYPE, 'post_status' => 'private',
				'post_title' => sanitize_text_field( mb_substr( (string) $form, 0, 190 ) ),
				'meta_input' => array(
					'_formconfirmed_source' => sanitize_key( $source ),
					'_formconfirmed_reason' => sanitize_key( $reason ),
					'_formconfirmed_pairs' => $clean,
					'_formconfirmed_verdict' => ( isset( $feedback['id'], $feedback['key'] ) && self::feedback_context( $feedback['id'] ) === $feedback ) ? $feedback : array(),
				),
			), true );
			return ! is_wp_error( $id ) && $id > 0;
		} finally {
			self::unlock( $lock );
		}
	}

	/** Bounded filter input; dates always refer to UTC. */
	public static function filters( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out = array( 'status' => 'all', 'form' => '', 'after' => '', 'before' => '' );
		if ( is_string( $input['status'] ?? null ) && in_array( $input['status'], array( 'all', 'held', 'uncertain', 'sent' ), true ) ) { $out['status'] = $input['status']; }
		if ( is_string( $input['form'] ?? null ) ) { $out['form'] = sanitize_text_field( mb_substr( $input['form'], 0, 100 ) ); }
		foreach ( array( 'after', 'before' ) as $key ) {
			$v = $input[$key] ?? '';
			if ( is_string( $v ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { $out[$key] = $v; }
		}
		return $out;
	}

	private static function query_args( $limit, $filters, $page ) {
		global $wpdb;
		$f = self::filters( $filters );
		$args = array( 'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft' ), 'posts_per_page' => max( 1, min( 100, (int) $limit ) ), 'paged' => max( 1, min( self::MAX, (int) $page ) ), 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) );
		if ( '' !== $f['form'] ) { $args['s'] = $f['form']; $args['search_columns'] = array( 'post_title' ); }
		foreach ( array( 'after', 'before' ) as $key ) {
			if ( '' === $f[$key] ) { continue; }
			$parts = array_map( 'intval', explode( '-', $f[$key] ) );
			$args['date_query'][] = array( 'column' => 'post_date_gmt', 'inclusive' => true, $key => array( 'year' => $parts[0], 'month' => $parts[1], 'day' => $parts[2], 'hour' => 'before' === $key ? 23 : 0, 'minute' => 'before' === $key ? 59 : 0, 'second' => 'before' === $key ? 59 : 0 ) );
		}
		if ( 'all' !== $f['status'] ) {
			$ids = self::ids_with_status( $f['status'] );
			$args['post__in'] = $ids ? array_map( 'intval', $ids ) : array( 0 );
		}
		return $args;
	}

	/** Same state definition as rows(): draft, a delivery timestamp or the state "sent" mean passed to the mailer. */
	private static function ids_with_status( $status ) {
		global $wpdb;
		if ( 'sent' === $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type=%s AND p.post_status IN ('private','draft') AND (p.post_status='draft' OR EXISTS(SELECT 1 FROM %i m WHERE m.post_id=p.ID AND ((m.meta_key='_formconfirmed_released' AND CAST(m.meta_value AS UNSIGNED)>0) OR (m.meta_key='_formconfirmed_release_state' AND m.meta_value='sent')))) ORDER BY p.ID DESC LIMIT %d", $wpdb->posts, self::TYPE, $wpdb->postmeta, self::MAX ) );
		} elseif ( 'uncertain' === $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type=%s AND p.post_status IN ('private','draft') AND NOT (p.post_status='draft' OR EXISTS(SELECT 1 FROM %i m WHERE m.post_id=p.ID AND ((m.meta_key='_formconfirmed_released' AND CAST(m.meta_value AS UNSIGNED)>0) OR (m.meta_key='_formconfirmed_release_state' AND m.meta_value='sent')))) AND EXISTS(SELECT 1 FROM %i u WHERE u.post_id=p.ID AND u.meta_key='_formconfirmed_release_state' AND u.meta_value IN ('sending','uncertain')) ORDER BY p.ID DESC LIMIT %d", $wpdb->posts, self::TYPE, $wpdb->postmeta, $wpdb->postmeta, self::MAX ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p WHERE p.post_type=%s AND p.post_status IN ('private','draft') AND NOT (p.post_status='draft' OR EXISTS(SELECT 1 FROM %i m WHERE m.post_id=p.ID AND ((m.meta_key='_formconfirmed_released' AND CAST(m.meta_value AS UNSIGNED)>0) OR (m.meta_key='_formconfirmed_release_state' AND m.meta_value='sent')))) AND NOT EXISTS(SELECT 1 FROM %i u WHERE u.post_id=p.ID AND u.meta_key='_formconfirmed_release_state' AND u.meta_value IN ('sending','uncertain')) ORDER BY p.ID DESC LIMIT %d", $wpdb->posts, self::TYPE, $wpdb->postmeta, $wpdb->postmeta, self::MAX ) );
		}
		return is_array( $ids ) ? $ids : array();
	}

	/** Fresh number of quarantine entries (held and passed on), optionally only after or before a UTC time. */
	private static function count_entries( $time = 0, $after = false ) {
		$args = array( 'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft' ), 'fields' => 'ids', 'posts_per_page' => 1, 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
		if ( $time ) {
			$args['date_query'] = array( array( 'column' => 'post_date_gmt', 'inclusive' => $after, ( $after ? 'after' : 'before' ) => self::utc_parts( $time ) ) );
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/** Explicit UTC components avoid site-timezone/DST normalization of a naive date string. */
	private static function utc_parts( $time ) {
		return array_combine( array( 'year', 'month', 'day', 'hour', 'minute', 'second' ), array_map( 'intval', explode( '-', gmdate( 'Y-m-d-H-i-s', (int) $time ) ) ) );
	}

	/** Named MySQL lock owned by this DB connection; WordPress has no API for an atomic lock. */
	private static function lock( $name, $wait ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,%d)', $name, $wait ) );
	}

	private static function unlock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/** Backward-compatible list used by CLI and integrations. */
	public static function entries( $limit = 50, $filters = array(), $page = 1 ) {
		$args = self::query_args( $limit, $filters, $page ); $args['no_found_rows'] = true;
		$q = new WP_Query( $args );
		return self::rows( $q->posts );
	}

	public static function listing( $filters = array(), $page = 1, $limit = 50 ) {
		$args = self::query_args( $limit, $filters, $page ); $args['no_found_rows'] = false;
		$q = new WP_Query( $args ); $empty_page = empty( $q->posts ) && $args['paged'] > 1;
		// WP_Query skips FOUND_ROWS when an offset has no posts; recover the real page count.
		if ( $empty_page ) { $first = $args; $first['paged'] = 1; $q = new WP_Query( $first ); }
		$pages = max( 1, (int) $q->max_num_pages );
		if ( $empty_page || $args['paged'] > $pages ) { $args['paged'] = min( $args['paged'], $pages ); $q = new WP_Query( $args ); }
		return array( 'rows' => self::rows( $q->posts ), 'total' => (int) $q->found_posts, 'pages' => $pages, 'page' => $args['paged'], 'filters' => self::filters( $filters ) );
	}

	private static function rows( $posts ) {
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => (int) $p->ID,
				'form'     => (string) $p->post_title,
				'date'     => (string) $p->post_date_gmt,
				'source'   => (string) get_post_meta( $p->ID, '_formconfirmed_source', true ),
				'reason'   => (string) get_post_meta( $p->ID, '_formconfirmed_reason', true ),
				'pairs'    => (array) get_post_meta( $p->ID, '_formconfirmed_pairs', true ),
				'released' => ( 'draft' === $p->post_status || 'sent' === get_post_meta( $p->ID, '_formconfirmed_release_state', true ) ) ? max( 1, (int) get_post_meta( $p->ID, '_formconfirmed_released', true ) ) : (int) get_post_meta( $p->ID, '_formconfirmed_released', true ),
				'feedback_available' => self::feedback_available( $p->ID ),
				'feedback' => in_array( get_post_meta( $p->ID, '_formconfirmed_feedback', true ), array( 'spam', 'ham' ), true ) ? get_post_meta( $p->ID, '_formconfirmed_feedback', true ) : '',
				'release_state' => in_array( get_post_meta( $p->ID, '_formconfirmed_release_state', true ), array( 'sending', 'uncertain' ), true ) ? 'uncertain' : '',
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

	/** Serializes delivery; an interrupted/ambiguous attempt is never resent automatically. */
	public static function release( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id < 1 ) { return false; }
		$lock = 'fc-release-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id ), 0, 40 );
		if ( ! self::lock( $lock, 1 ) ) { return false; }
		try {
			clean_post_cache( $id );
			$p = get_post( $id );
			if ( ! $p || self::TYPE !== $p->post_type ) { return false; }
			$state = (string) get_post_meta( $id, '_formconfirmed_release_state', true );
			if ( 'draft' === $p->post_status || 'sent' === $state || (int) get_post_meta( $id, '_formconfirmed_released', true ) > 0 ) { return true; }
			if ( 'private' !== $p->post_status || in_array( $state, array( 'sending', 'uncertain' ), true ) ) { return false; }
			$pairs = self::clean_pairs( get_post_meta( $id, '_formconfirmed_pairs', true ) );
			if ( ! $pairs ) { return false; }
			$text = ''; $reply = '';
			foreach ( $pairs as $pair ) {
				$text .= $pair[0] . ': ' . $pair[1] . "\n";
				if ( '' === $reply && is_email( trim( $pair[1] ) ) ) { $reply = trim( $pair[1] ); }
			}
			/* translators: 1: date and time the message was sent */
			$text .= "\n-- \n" . sprintf( __( 'Sent on %1$s, held by formconfirmed and delivered by hand.', 'formconfirmed' ), get_date_from_gmt( $p->post_date_gmt ) ) . "\n";
			$headers = '' !== $reply ? array( 'Reply-To: ' . $reply ) : array();
			/* translators: 1: site name, 2: form name */
			$subject = sprintf( __( '[%1$s] Released message: %2$s', 'formconfirmed' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $p->post_title );
			if ( ! update_post_meta( $id, '_formconfirmed_release_state', 'sending' ) ) { return false; }
			try {
				$sent = wp_mail( self::recipient(), $subject, $text, $headers );
			} catch ( Throwable $e ) {
				update_post_meta( $id, '_formconfirmed_release_state', 'uncertain' );
				return false;
			}
			if ( ! $sent ) {
				update_post_meta( $id, '_formconfirmed_release_state', 'failed' );
				return false;
			}
			// If either final write fails, the earlier 'sending' marker still prevents resending.
			update_post_meta( $id, '_formconfirmed_released', time() );
			update_post_meta( $id, '_formconfirmed_release_state', 'sent' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			return true;
		} finally {
			self::unlock( $lock );
		}
	}

	/** Local retention diagnostics contain counts/times only, never message fields. */
	public static function maintenance_status() {
		global $wpdb;
		$cutoff = time() - self::DAYS * DAY_IN_SECONDS;
		$wpdb->flush();
		$overdue = self::count_entries( $cutoff );
		$first = new WP_Query( array( 'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft' ), 'posts_per_page' => 1, 'no_found_rows' => true, 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'orderby' => array( 'date' => 'ASC', 'ID' => 'ASC' ),
			'date_query' => array( array( 'column' => 'post_date_gmt', 'inclusive' => false, 'before' => self::utc_parts( $cutoff ) ) ) ) );
		$available = '' === (string) $wpdb->last_error;
		$oldest = $first->posts ? strtotime( $first->posts[0]->post_date_gmt . ' UTC' ) : 0;
		$s = get_option( self::PURGE_STATUS, array() );
		$s = is_array( $s ) ? $s : array();
		$daily = wp_next_scheduled( self::CRON );
		$followup = wp_next_scheduled( self::FOLLOWUP );
		$next = $daily && $followup ? min( $daily, $followup ) : ( $daily ?: $followup );
		return array(
			'available' => $available,
			'overdue' => $available ? max( 0, $overdue ) : 0,
			'oldest' => $available && $oldest ? (int) $oldest : 0,
			'last_run' => max( 0, (int) ( $s['last_run'] ?? 0 ) ),
			'last_success' => max( 0, (int) ( $s['last_success'] ?? 0 ) ),
			'deleted' => max( 0, (int) ( $s['deleted'] ?? 0 ) ),
			'failed' => max( 0, (int) ( $s['failed'] ?? 0 ) ),
			'next_run' => $next ? (int) $next : 0,
		);
	}

	/** At most one 100-entry batch; continuations cannot multiply or overlap. */
	public static function purge() {
		global $wpdb;
		$lock = 'fc-purge-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
		if ( ! self::lock( $lock, 0 ) ) { return 0; }
		try {
			$before = self::maintenance_status();
			$deleted = 0; $failed = 0;
			if ( $before['available'] ) {
				// Explicit UTC components avoid site-timezone/DST normalization of a naive date string.
				$utc = self::utc_parts( time() - self::DAYS * DAY_IN_SECONDS );
				$q = new WP_Query( array(
					'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft' ),
					'fields' => 'ids', 'posts_per_page' => self::PURGE_BATCH, 'no_found_rows' => true,
					'orderby' => 'ID', 'order' => 'ASC',
					'date_query' => array( array( 'column' => 'post_date_gmt', 'before' => $utc, 'inclusive' => false ) ),
				) );
				foreach ( $q->posts as $id ) {
					// Never delete underneath a release currently sending this message.
					$item_lock = 'fc-release-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . (int) $id ), 0, 40 );
					if ( ! self::lock( $item_lock, 0 ) ) { $failed++; continue; }
					try {
						if ( wp_delete_post( (int) $id, true ) ) { $deleted++; } else { $failed++; }
					} finally {
						self::unlock( $item_lock );
					}
				}
			} else { $failed++; }
			$after = self::maintenance_status();
			if ( ! $after['available'] ) { $failed++; }
			$now = time();
			update_option( self::PURGE_STATUS, array(
				'last_run' => $now, 'last_success' => 0 === $failed ? $now : $before['last_success'],
				'deleted' => $deleted, 'failed' => $failed,
			), false );
			if ( ( ! $after['available'] || $after['overdue'] > 0 ) && ! wp_next_scheduled( self::FOLLOWUP ) ) {
				// Progress: one minute. Failed/stalled storage: one hour, no tight retry loop.
				wp_schedule_single_event( $now + ( $deleted > 0 && 0 === $failed ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS ), self::FOLLOWUP );
			}
			return $deleted;
		} finally {
			self::unlock( $lock );
		}
	}

	public static function health_tests( $tests ) {
		$tests['direct']['formconfirmed_retention'] = array(
			'label' => __( 'formconfirmed quarantine retention', 'formconfirmed' ),
			'test' => array( __CLASS__, 'health_test' ),
		);
		return $tests;
	}

	public static function health_test() {
		$s = self::maintenance_status();
		$late = ! $s['available'] || ! $s['next_run'] || $s['next_run'] < time() - DAY_IN_SECONDS || $s['failed'] > 0
			|| ( $s['last_success'] && $s['last_success'] < time() - 2 * DAY_IN_SECONDS )
			|| ( $s['oldest'] && $s['oldest'] < time() - ( self::DAYS + 1 ) * DAY_IN_SECONDS );
		return array(
			'label' => $late ? __( 'Check formconfirmed quarantine cleanup', 'formconfirmed' ) : __( 'formconfirmed quarantine cleanup is scheduled', 'formconfirmed' ),
			'status' => $late ? 'recommended' : 'good',
			'badge' => array( 'label' => 'formconfirmed', 'color' => 'blue' ),
			'description' => '<p>' . esc_html__( 'The local quarantine keeps messages for 14 days. Cleanup runs in bounded batches. On low-traffic sites, use a system scheduler for WordPress cron.', 'formconfirmed' ) . '</p>',
			'actions' => '',
			'test' => 'formconfirmed_retention',
		);
	}

	/** Bind an opaque verdict ID to the currently configured site without storing its secret. */
	public static function feedback_context( $verdict ) {
		$o = Formconfirmed_Core::opt();
		if ( ! is_string( $verdict ) || ! preg_match( '/^[a-f0-9]{32}$/D', $verdict ) || empty( $o['site_key'] ) || empty( $o['secret'] ) ) { return array(); }
		return array( 'id' => $verdict, 'key' => hash_hmac( 'sha256', $o['site_key'] . "\0" . $o['secret'], wp_salt( 'auth' ) ) );
	}

	public static function feedback_available( $id ) {
		$stored = get_post_meta( $id, '_formconfirmed_verdict', true );
		$current = self::feedback_context( is_array( $stored ) ? ( $stored['id'] ?? '' ) : '' );
		return $current && is_string( $stored['key'] ?? null ) && hash_equals( $current['key'], $stored['key'] );
	}

	/** Explicit moderation only: delivery, deletion and API failure never imply a label. */
	public static function feedback( $id, $label ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! current_user_can( 'manage_options' ) || $id < 1 || ! in_array( $label, array( 'spam', 'ham' ), true ) ) { return false; }
		$lock = 'fc-release-' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id ), 0, 40 );
		if ( ! self::lock( $lock, 1 ) ) { return false; }
		try {
			clean_post_cache( $id );
			$p = get_post( $id );
			if ( ! $p || self::TYPE !== $p->post_type || ! in_array( $p->post_status, array( 'private', 'draft' ), true ) || ! self::feedback_available( $id ) ) { return false; }
			if ( get_post_meta( $id, '_formconfirmed_feedback', true ) === $label ) { return true; }
			$context = get_post_meta( $id, '_formconfirmed_verdict', true );
			$o = Formconfirmed_Core::opt();
			$response = wp_remote_post( FORMCONFIRMED_API . '/feedback', array(
				'timeout' => Formconfirmed_Core::TIMEOUT, 'redirection' => 0, 'limit_response_size' => 8192,
				'body' => array( 'secret' => $o['secret'], 'id' => $context['id'], 'label' => $label, 'source' => 'manual' ),
			) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return false; }
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || true !== ( $data['success'] ?? null ) || array() !== ( $data['error-codes'] ?? null ) ) { return false; }
			return false !== update_post_meta( $id, '_formconfirmed_feedback', $label );
		} finally {
			self::unlock( $lock );
		}
	}

	public static function action_feedback() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) ); }
		if ( ! Formconfirmed_Admin::is_post() ) { wp_die( esc_html__( 'Use the feedback buttons.', 'formconfirmed' ) ); }
		$id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$label = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_key( wp_unslash( $_POST['label'] ) ) : '';
		if ( ! in_array( $label, array( 'spam', 'ham' ), true ) ) { wp_die( esc_html__( 'Invalid feedback.', 'formconfirmed' ) ); }
		check_admin_referer( 'formconfirmed_feedback_' . $id . '_' . $label );
		$ok = self::feedback( $id, $label );
		set_transient( 'formconfirmed_msg', array( $ok ? 'ok' : 'error', $ok ? __( 'Feedback saved. The message was not sent or deleted.', 'formconfirmed' ) : __( 'Feedback could not be saved. Check the connection and site keys.', 'formconfirmed' ) ), 120 );
		wp_safe_redirect( Formconfirmed_Admin::url( array( 'tab' => 'held' ) ) ); exit;
	}

	public static function action_release() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		if ( ! Formconfirmed_Admin::is_post() ) { wp_die( esc_html__( 'Please use the delivery button.', 'formconfirmed' ) ); }
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'formconfirmed_release_' . $id );
		$ok = self::release( $id );
		set_transient( 'formconfirmed_msg', $ok ? array( 'ok', __( 'Passed to the site\'s mailer. Delivery to the recipient is not confirmed.', 'formconfirmed' ) ) : array( 'error', __( 'Delivery could not be confirmed. Check the message status and the mail setup before trying again.', 'formconfirmed' ) ), 120 );
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
