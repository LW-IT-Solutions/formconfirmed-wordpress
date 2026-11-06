<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formconfirmed - the backup check: an independent check that runs on this site only.
 *
 * It matters only when the service gives no clear answer (it cannot be reached, answers with an
 * error, or the visitor's browser could not reach it). Then a message is delivered only if this
 * check passed for the same submission; otherwise it is held for review or refused.
 *
 * Nothing of it leaves this site. assets/js/backup.js adds to every protected contact form and
 * comment form:
 *   formconfirmed_t    the time the page was rendered
 *   formconfirmed_s    HMAC of that time with this site's salt (altered = fail)
 *   formconfirmed_j    empty in the HTML, set on the first real interaction (focus, key, pointer)
 *   formconfirmed_hp   a trap field, off-screen; filled = fail
 *   formconfirmed_pow  the answer to a proof of work this site handed out (admin-ajax, action
 *                      formconfirmed_pow): SHA-256, ALTCHA format, about 25,000 hashes on average,
 *                      solved in small steps so the page stays responsive
 * and on submission checks: trap field empty, signature valid, at least FILL_SECONDS since render,
 * interaction proof, proof of work valid and unused, at most RATE_MAX submissions per address in
 * RATE_WINDOW seconds.
 *
 * Registration and login forms have no backup check (the proof of work would hold the login page
 * back): during an outage a registration is refused and the visitor can try again; a login is
 * decided by the password.
 *
 * If the formexposed plugin runs its protection layer with the proof of work on this site, that
 * layer already checks every form the same way; then this class uses its result instead of adding
 * a second proof of work.
 */
class Formconfirmed_Backup {

	const ACTION       = 'formconfirmed_pow';
	const MAX          = 50000;
	const TTL          = 3600;
	const CHUNK        = 4000;
	const FILL_SECONDS = 3;
	const RATE_MAX     = 5;
	const RATE_WINDOW  = 600;

	/** Adapters without a backup check. */
	const WITHOUT = array( 'register', 'woo_register', 'login' );

	/** Fields of the current submission (POST, or the parsed data of Fluent Forms). */
	private static $data = null;

	/** Result of the check for the current submission (null = not checked yet). */
	private static $passed = null;

	/** One render time per page view. */
	private static $ts = null;

	public static function init() {
		if ( ! Formconfirmed_Core::on() || ! self::own() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	/** The protection layer of the formexposed plugin, with its proof of work, is on here. */
	public static function delegated() {
		return class_exists( 'Formexposed_Guard' ) && method_exists( 'Formexposed_Guard', 'passed_strict' ) && Formexposed_Guard::on()
			&& class_exists( 'Formexposed_Pow' ) && Formexposed_Pow::on();
	}

	/** This plugin runs its own backup check (switched on, and not delegated). */
	public static function own() {
		return Formconfirmed_Core::opt()['backup'] && ! self::delegated();
	}

	/** Can the backup check stand in for an adapter? */
	public static function ready( $adapter = 'cf7' ) {
		if ( in_array( $adapter, self::WITHOUT, true ) ) {
			return false;
		}
		return self::delegated() || self::own();
	}

	/** Did the current submission pass the backup check? */
	public static function passed() {
		if ( self::delegated() ) {
			return (bool) Formexposed_Guard::passed_strict();
		}
		if ( ! self::own() ) {
			return false;
		}
		if ( null === self::$passed ) {
			self::$passed = true === self::check( self::fields() );
		}
		return self::$passed;
	}

	/** Fluent Forms sends our fields inside its own "data" string. */
	public static function use_data( $raw ) {
		self::$data = is_array( $raw ) ? $raw : array();
	}

	/** For the tests: forget the current submission. */
	public static function reset() {
		self::$data   = null;
		self::$passed = null;
	}

	/** Our fields of the current submission. */
	private static function fields() {
		$names = array( 'formconfirmed_t', 'formconfirmed_s', 'formconfirmed_j', 'formconfirmed_hp', 'formconfirmed_pow' );
		$out   = array();
		foreach ( $names as $n ) {
			if ( is_array( self::$data ) ) {
				$out[ $n ] = isset( self::$data[ $n ] ) && is_string( self::$data[ $n ] ) ? self::$data[ $n ] : null;
			} else {
				$v         = filter_input( INPUT_POST, $n, FILTER_UNSAFE_RAW );
				$out[ $n ] = is_string( $v ) ? $v : null;
			}
		}
		return $out;
	}

	private static function sign( $what ) {
		return substr( hash_hmac( 'sha256', 'formconfirmed|' . $what, wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function js_value( $ts ) {
		return substr( self::sign( 'js|' . $ts ), 0, 16 );
	}

	/**
	 * The check. $p: our fields. Returns true, or a short reason (honeypot, token, too_fast,
	 * no_interaction, pow-<why>, rate_limit) for the tests.
	 */
	public static function check( $p ) {
		$hp = isset( $p['formconfirmed_hp'] ) ? (string) $p['formconfirmed_hp'] : '';
		if ( '' !== trim( $hp ) ) {
			return 'honeypot';
		}
		$ts  = isset( $p['formconfirmed_t'] ) ? (string) wp_unslash( $p['formconfirmed_t'] ) : '';
		$sig = isset( $p['formconfirmed_s'] ) ? (string) wp_unslash( $p['formconfirmed_s'] ) : '';
		if ( ! preg_match( '/^\d{9,11}$/', $ts ) || ! hash_equals( self::sign( 'time|' . $ts ), $sig ) ) {
			return 'token';
		}
		if ( time() - (int) $ts < self::FILL_SECONDS ) {
			return 'too_fast';
		}
		$js = isset( $p['formconfirmed_j'] ) ? (string) wp_unslash( $p['formconfirmed_j'] ) : '';
		if ( ! hash_equals( self::js_value( $ts ), $js ) ) {
			return 'no_interaction';
		}
		$pow = self::pow_verify( isset( $p['formconfirmed_pow'] ) ? (string) $p['formconfirmed_pow'] : '' );
		if ( true !== $pow[0] ) {
			return 'pow-' . $pow[0];
		}
		$key = 'formconfirmed_rl_' . substr( hash( 'sha256', Formconfirmed_Core::client_ip() . wp_salt( 'nonce' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_MAX ) {
			return 'rate_limit';
		}
		set_transient( $key, $n + 1, self::RATE_WINDOW );
		set_transient( $pow[1], 1, max( 60, $pow[2] - time() + 60 ) );   // this answer is spent
		return true;
	}

	// ------------------------------------------------------------------ proof of work

	private static function pow_key() {
		return hash_hmac( 'sha256', 'formconfirmed-pow', wp_salt( 'auth' ) );
	}

	/** A fresh challenge (ALTCHA format). The secret number is not part of it. */
	public static function challenge() {
		$max    = max( 1000, min( 1000000, (int) apply_filters( 'formconfirmed_pow_max', self::MAX ) ) );
		$salt   = bin2hex( random_bytes( 8 ) ) . '?expires=' . ( time() + self::TTL );
		$number = random_int( 0, $max );
		$ch     = hash( 'sha256', $salt . $number );
		return array(
			'algorithm' => 'SHA-256',
			'challenge' => $ch,
			'maxnumber' => $max,
			'salt'      => $salt,
			'signature' => hash_hmac( 'sha256', $ch, self::pow_key() ),
		);
	}

	/** admin-ajax: hands out one challenge. Stateless - nothing is stored until an answer comes back. */
	public static function ajax() {
		nocache_headers();
		wp_send_json( self::challenge() );
	}

	/**
	 * Checks an answer: the JSON {algorithm, challenge, number, salt, signature}.
	 * Returns array(true, transient key, expiry) or array(reason).
	 */
	public static function pow_verify( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array( 'missing' );
		}
		if ( strlen( $value ) > 1000 ) {
			return array( 'format' );
		}
		$j = json_decode( $value, true );
		if ( ! is_array( $j ) ) {
			$j = json_decode( wp_unslash( $value ), true );   // some form plugins hand it over slashed
		}
		if ( ! is_array( $j ) || ! isset( $j['algorithm'], $j['challenge'], $j['number'], $j['salt'], $j['signature'] ) || 'SHA-256' !== $j['algorithm'] ) {
			return array( 'format' );
		}
		$ch   = (string) $j['challenge'];
		$sig  = (string) $j['signature'];
		$salt = (string) $j['salt'];
		$num  = $j['number'];
		if ( ! ( is_int( $num ) || ( is_string( $num ) && ctype_digit( $num ) ) ) || (int) $num < 0 || (int) $num > 1000000
			|| ! preg_match( '/^[a-f0-9]{64}$/', $ch ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig )
			|| ! preg_match( '/^[a-f0-9]{8,64}\?expires=(\d{9,11})$/', $salt, $m ) ) {
			return array( 'format' );
		}
		$bis = (int) $m[1];
		if ( $bis < time() ) {
			return array( 'expired' );
		}
		if ( $bis > time() + self::TTL + 300 ) {
			return array( 'format' );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $ch, self::pow_key() ), $sig ) ) {
			return array( 'signature' );
		}
		if ( ! hash_equals( $ch, hash( 'sha256', $salt . (int) $num ) ) ) {
			return array( 'hash' );
		}
		$id = 'formconfirmed_pow_' . substr( $ch, 0, 32 );
		if ( false !== get_transient( $id ) ) {
			return array( 'used' );
		}
		return array( true, $id, $bis );
	}

	/** Search the number like a browser would (self-test, tests). */
	public static function solve( $c ) {
		$max = (int) $c['maxnumber'];
		for ( $n = 0; $n <= $max; $n++ ) {
			if ( hash( 'sha256', $c['salt'] . $n ) === $c['challenge'] ) {
				return $n;
			}
		}
		return null;
	}

	/** The answer a browser puts into the field. */
	public static function answer( $c, $n ) {
		return wp_json_encode( array( 'algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => (int) $n, 'salt' => $c['salt'], 'signature' => $c['signature'] ) );
	}

	/** Fields a browser would send for a page rendered at $ts (tests, self-test). */
	public static function fields_for( $ts, $pow ) {
		return array(
			'formconfirmed_t'   => (string) $ts,
			'formconfirmed_s'   => self::sign( 'time|' . $ts ),
			'formconfirmed_j'   => self::js_value( $ts ),
			'formconfirmed_hp'  => '',
			'formconfirmed_pow' => $pow,
		);
	}

	/**
	 * Self-test: make a challenge, solve it, check it, check that it cannot be used twice, and ask
	 * admin-ajax.php the way a visitor would (a security plugin that blocks it for visitors would
	 * make the backup check fail every time). Returns true or WP_Error.
	 */
	public static function self_test() {
		$c = self::challenge();
		$n = self::solve( $c );
		if ( null === $n ) {
			return new WP_Error( 'pow_solve', __( 'The backup check could not solve its own challenge.', 'formconfirmed' ) );
		}
		$v = self::pow_verify( self::answer( $c, $n ) );
		if ( true !== $v[0] ) {
			return new WP_Error( 'pow_verify', __( 'The backup check refused a correct answer.', 'formconfirmed' ) );
		}
		set_transient( $v[1], 1, 120 );
		if ( 'used' !== self::pow_verify( self::answer( $c, $n ) )[0] ) {
			return new WP_Error( 'pow_replay', __( 'The backup check accepted an answer twice (is the object cache working?).', 'formconfirmed' ) );
		}
		delete_transient( $v[1] );
		$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array(
			'timeout'     => 10,
			'redirection' => 0,
			'body'        => array( 'action' => self::ACTION ),
		) );
		if ( ! is_wp_error( $r ) ) {
			$code = (int) wp_remote_retrieve_response_code( $r );
			$j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( 401 !== $code && ( 200 !== $code || ! is_array( $j ) || empty( $j['challenge'] ) ) ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'pow_ajax', sprintf( __( 'Visitors cannot fetch the challenge of the backup check from admin-ajax.php (answer: HTTP %d). A security plugin or a server rule blocks it; during an outage of the service every message would then be held.', 'formconfirmed' ), $code ) );
			}
		}
		return true;
	}

	// ------------------------------------------------------------------ browser

	/** Loads assets/js/backup.js on a page with a protected form (once). */
	public static function load() {
		if ( ! self::own() || wp_script_is( 'formconfirmed-backup', 'enqueued' ) ) {
			return;
		}
		if ( null === self::$ts ) {
			self::$ts = (string) time();
		}
		wp_enqueue_script( 'formconfirmed-backup', plugins_url( 'assets/js/backup.js', FORMCONFIRMED_FILE ), array(), FORMCONFIRMED_VERSION, array( 'in_footer' => true ) );
		wp_add_inline_script( 'formconfirmed-backup', 'window.formconfirmedBackup = ' . wp_json_encode( array(
			'time'   => self::$ts,
			'token'  => self::sign( 'time|' . self::$ts ),
			'fe'     => self::js_value( self::$ts ),
			'label'  => __( 'Leave this field empty', 'formconfirmed' ),
			'nopow'  => '#loginform,#registerform,form.woocommerce-form-register',
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::ACTION,
			'ttl'    => self::TTL,
			'chunk'  => self::CHUNK,
		) ) . ';', 'before' );
	}
}
