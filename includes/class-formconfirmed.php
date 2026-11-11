<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formconfirmed - the check of every protected form.
 *
 * On pages with a protected form the widget script of the service (FORMCONFIRMED_API/widget.js)
 * solves a small proof of work in the visitor's browser and puts a token into the form. On
 * submission this server sends the token to FORMCONFIRMED_API/verify together with the secret key,
 * the visitor's IP address, the trap field, the first e-mail address of the form and - only if
 * switched on - the message text. The service answers allow, flag or block.
 *
 * Outcome of a submission (the outage rule of the service):
 *   allow            the message goes through.
 *   block            refused (the form shows its error, or the message is filed as spam).
 *   flag             never waved through: held for review (default) or refused, as chosen.
 *   no clear answer  the service could not be reached, answered with an error code (wrong secret,
 *                    site paused, service switched off), answered something unknown, or the
 *                    visitor's browser reported that it could not reach the service. The message
 *                    goes through only if an independent check on this site passed (the backup
 *                    check, see Formconfirmed_Backup). Otherwise it is held or refused - never let
 *                    through silently.
 * The login form is the exception: there the password is the independent check, so only "block"
 * refuses a login.
 *
 * One adapter per form plugin. Each puts the widget element into the form (bound to a fixed
 * purpose, see PURPOSES) and decides on submission.
 */
class Formconfirmed_Core {

	const OPT     = 'formconfirmed_settings';

	/** Option of the formexposed plugin this class reads once, on the first activation (its form protection tab). */
	const OPTION_FORMEXPOSED_PROTECT = 'formexposed_protect';
	const FIELD   = 'fcp-response';
	const HP      = 'fcp-website';
	const TIMEOUT = 5;

	/** WordPress' own setting "Anyone can register". */
	const OPTION_USERS_CAN_REGISTER = 'users_can_register';

	/** Adapters in the order of the settings page. */
	const ADAPTERS = array( 'cf7', 'wpforms', 'gravityforms', 'elementor', 'fluentform', 'formidable', 'comments', 'register', 'woo_register', 'login' );

	/** Switched on for a new installation (the login form only on request). */
	const DEFAULT_ADAPTERS = array( 'cf7', 'wpforms', 'gravityforms', 'elementor', 'fluentform', 'formidable', 'comments', 'register', 'woo_register' );

	/** The purpose each adapter binds its token to (data-purpose / expected_purpose of the service). */
	const PURPOSES = array(
		'cf7'          => 'contact',
		'wpforms'      => 'contact',
		'gravityforms' => 'contact',
		'elementor'    => 'contact',
		'fluentform'   => 'contact',
		'formidable'   => 'contact',
		'comments'     => 'comment',
		'register'     => 'register',
		'woo_register' => 'register',
		'login'        => 'login',
	);

	/** Form plugins whose forms can be switched on and off one by one (Settings -> formconfirmed -> Forms). */
	const PER_FORM = array( 'cf7', 'wpforms', 'gravityforms', 'fluentform', 'formidable' );

	/** Error codes of the service that mean "no check took place" (not the visitor's fault). */
	const NO_CHECK_CODES = array( 'service-disabled', 'site-paused', 'invalid-input-secret', 'missing-input-secret', 'bad-request' );

	/** The result of the current submission. */
	private static $result = null;

	/** The decision for the current submission: pass, hold or reject. */
	private static $decision = null;

	/** The adapter the current submission was checked for. */
	private static $checked = '';

	/** Raw WPForms entry of the current submission (wpforms_process_before). */
	private static $wpforms_entry = array();

	public static function init() {
		add_shortcode( 'formconfirmed', array( __CLASS__, 'shortcode' ) );
		if ( ! self::on() || self::paused() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'async_tag' ), 10, 2 );
		foreach ( self::active_adapters() as $a ) {
			call_user_func( array( __CLASS__, 'adapter_' . $a ) );
		}
	}

	/** First activation: take over the keys of the form protection tab of the formexposed plugin. */
	public static function activate() {
		$o = get_option( self::OPT );
		if ( ! is_array( $o ) ) {
			$alt = get_option( self::OPTION_FORMEXPOSED_PROTECT );
			$neu = array();
			if ( is_array( $alt ) && ! empty( $alt['site_key'] ) && ! empty( $alt['secret'] ) ) {
				$neu = array(
					'site_key'  => (string) $alt['site_key'],
					'secret'    => (string) $alt['secret'],
					'adapters'  => isset( $alt['adapters'] ) ? (array) $alt['adapters'] : self::DEFAULT_ADAPTERS,
					'content'   => ! empty( $alt['content'] ),
					'uncertain' => isset( $alt['uncertain'] ) && 'reject' === $alt['uncertain'] ? 'reject' : 'hold',
					'imported'  => time(),
				);
			}
			add_option( self::OPT, $neu, '', false );
		}
		Formconfirmed_Held::schedule();
	}

	/** The saved settings, with defaults. */
	public static function opt() {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? $o : array();
		$o = wp_parse_args( $o, array(
			'site_key'  => '',
			'secret'    => '',
			'adapters'  => self::DEFAULT_ADAPTERS,
			'content'   => true,
			'uncertain' => 'hold',
			'backup'    => true,
			'forms'     => array(),
			'new_forms' => true,
			'stats'     => array(),
			'problem'   => array(),
			'ok_at'     => 0,
			'imported'  => 0,
		) );
		$o['adapters']  = array_values( array_intersect( self::ADAPTERS, (array) $o['adapters'] ) );
		$o['uncertain'] = 'reject' === $o['uncertain'] ? 'reject' : 'hold';
		$o['content']   = (bool) $o['content'];
		$o['backup']    = (bool) $o['backup'];
		$o['new_forms'] = (bool) $o['new_forms'];
		$forms          = array();
		foreach ( self::PER_FORM as $a ) {
			$forms[ $a ] = array();
			if ( isset( $o['forms'][ $a ] ) && is_array( $o['forms'][ $a ] ) ) {
				foreach ( $o['forms'][ $a ] as $id => $on ) {
					if ( ctype_digit( (string) $id ) && '0' !== (string) $id ) {
						$forms[ $a ][ (string) $id ] = (bool) $on;
					}
				}
			}
		}
		$o['forms']     = $forms;
		$o['stats']     = wp_parse_args( (array) $o['stats'], array( 'allow' => 0, 'flag' => 0, 'block' => 0, 'unreachable' => 0, 'fallback' => 0, 'held' => 0, 'rejected' => 0 ) );
		return $o;
	}

	public static function save_opt( $o ) {
		update_option( self::OPT, $o, false );
	}

	/** Both keys are there. */
	public static function on() {
		$o = self::opt();
		return '' !== $o['site_key'] && '' !== $o['secret'];
	}

	/**
	 * Switched off in the settings of a site where the form protection tab of the formexposed plugin
	 * would otherwise check the same submissions. That plugin stands down while this one is active
	 * (since formexposed 1.10.0); an older version would check every token twice, and a token is
	 * valid once - so this plugin waits until formexposed is updated.
	 */
	public static function paused() {
		return defined( 'FORMEXPOSED_VERSION' ) && version_compare( FORMEXPOSED_VERSION, '1.10.0', '<' )
			&& class_exists( 'Formexposed_Protect' ) && Formexposed_Protect::on();
	}

	/** The adapters that are switched on AND whose form plugin is active. */
	public static function active_adapters() {
		$out = array();
		foreach ( self::opt()['adapters'] as $a ) {
			if ( self::available( $a ) ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/** Is the form plugin (or core feature) of an adapter present? */
	public static function available( $a ) {
		switch ( $a ) {
			case 'cf7':
				return defined( 'WPCF7_VERSION' );
			case 'wpforms':
				return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' );
			case 'gravityforms':
				return class_exists( 'GFForms' );
			case 'elementor':
				return defined( 'ELEMENTOR_PRO_VERSION' );
			case 'fluentform':
				return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' );
			case 'formidable':
				return class_exists( 'FrmAppHelper' );
			case 'woo_register':
				return class_exists( 'WooCommerce' );
			case 'register':
				return (bool) get_option( self::OPTION_USERS_CAN_REGISTER );
			default:
				return true;   // comments, login
		}
	}

	/**
	 * Is one form protected? Forms of the plugins in PER_FORM follow their own switch; a form nobody
	 * has decided on yet follows "protect new forms automatically" (on by default). The other places
	 * (Elementor Pro forms, comments, registration, login) have one switch each.
	 */
	public static function form_enabled( $adapter, $id ) {
		if ( ! in_array( $adapter, self::PER_FORM, true ) ) {
			return true;
		}
		$o  = self::opt();
		$id = (string) $id;
		return isset( $o['forms'][ $adapter ][ $id ] ) ? $o['forms'][ $adapter ][ $id ] : $o['new_forms'];
	}

	/** The forms of a form plugin on this site: array(id => title). Empty when the plugin is not active. */
	public static function forms_of( $adapter ) {
		$out = array();
		if ( ! self::available( $adapter ) ) {
			return $out;
		}
		switch ( $adapter ) {
			case 'cf7':
			case 'wpforms':
				$posts = get_posts( array(
					'post_type'      => 'cf7' === $adapter ? 'wpcf7_contact_form' : 'wpforms',
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
				) );
				foreach ( $posts as $p ) {
					$out[ (string) $p->ID ] = '' !== $p->post_title ? $p->post_title : '#' . $p->ID;
				}
				break;
			case 'gravityforms':
				if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
					foreach ( (array) GFAPI::get_forms() as $f ) {
						if ( isset( $f['id'] ) ) {
							$out[ (string) $f['id'] ] = isset( $f['title'] ) ? (string) $f['title'] : '#' . $f['id'];
						}
					}
				}
				break;
			case 'fluentform':
				if ( function_exists( 'wpFluent' ) ) {
					$rows = wpFluent()->table( 'fluentform_forms' )->select( array( 'id', 'title' ) )->orderBy( 'title' )->get();
					foreach ( is_iterable( $rows ) ? $rows : array() as $f ) {
						$out[ (string) $f->id ] = (string) $f->title;
					}
				}
				break;
			case 'formidable':
				if ( class_exists( 'FrmForm' ) && method_exists( 'FrmForm', 'getAll' ) ) {
					foreach ( (array) FrmForm::getAll( array( 'is_template' => 0, 'status' => 'published' ), 'name' ) as $f ) {
						$out[ (string) $f->id ] = (string) $f->name;
					}
				}
				break;
		}
		return $out;
	}

	public static function labels() {
		return array(
			'cf7'          => 'Contact Form 7',
			'wpforms'      => 'WPForms',
			'gravityforms' => 'Gravity Forms',
			'elementor'    => __( 'Elementor Pro forms', 'formconfirmed' ),
			'fluentform'   => 'Fluent Forms',
			'formidable'   => 'Formidable Forms',
			'comments'     => __( 'Comments', 'formconfirmed' ),
			'register'     => __( 'WordPress registration', 'formconfirmed' ),
			'woo_register' => __( 'WooCommerce account registration', 'formconfirmed' ),
			'login'        => __( 'WordPress login', 'formconfirmed' ),
		);
	}

	// ------------------------------------------------------------------ front end

	/** The widget script is registered on every page, but only loaded where a protected form is printed. */
	public static function register_script() {
		wp_register_script( 'formconfirmed-widget', FORMCONFIRMED_API . '/widget.js', array(), FORMCONFIRMED_VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'formconfirmed-forms', plugins_url( 'assets/js/forms.js', FORMCONFIRMED_FILE ), array(), FORMCONFIRMED_VERSION, array( 'in_footer' => true ) );
	}

	/** Load the widget (and, for forms that are equipped in the browser, the small helper). */
	private static function load( $helper = false ) {
		if ( ! wp_script_is( 'formconfirmed-widget', 'registered' ) ) {
			self::register_script();
		}
		wp_enqueue_script( 'formconfirmed-widget' );
		Formconfirmed_Backup::load();
		if ( $helper && ! wp_script_is( 'formconfirmed-forms', 'enqueued' ) ) {
			wp_enqueue_script( 'formconfirmed-forms' );
			$sel = array();
			if ( in_array( 'elementor', self::active_adapters(), true ) ) {
				$sel[] = 'form.elementor-form';
			}
			if ( in_array( 'fluentform', self::active_adapters(), true ) ) {
				foreach ( array_keys( self::forms_of( 'fluentform' ) ) as $fid ) {
					if ( self::form_enabled( 'fluentform', $fid ) ) {
						$sel[] = 'form.frm-fluent-form[data-form_id="' . absint( $fid ) . '"]';
					}
				}
			}
			wp_add_inline_script( 'formconfirmed-forms', 'window.formconfirmedForms = ' . wp_json_encode( array(
				'sel'     => implode( ',', $sel ),
				'sitekey' => self::opt()['site_key'],
				'purpose' => self::PURPOSES['elementor'],
			) ) . ';', 'before' );
		}
	}

	public static function async_tag( $tag, $handle ) {
		return 'formconfirmed-widget' === $handle ? str_replace( ' src=', ' async defer src=', $tag ) : $tag;
	}

	/** The element the widget turns into the hidden token field, a trap field and the badge. */
	public static function element( $adapter, $purpose = '' ) {
		self::load();
		$purpose = '' !== $purpose ? $purpose : self::PURPOSES[ $adapter ];
		return '<div class="fcp-widget" data-sitekey="' . esc_attr( self::opt()['site_key'] ) . '" data-purpose="' . esc_attr( $purpose ) . '"></div>';
	}

	/** A purpose the service accepts: lower case letters, digits, - and _, starting with a letter. */
	public static function clean_purpose( $purpose ) {
		$purpose = strtolower( trim( (string) $purpose ) );
		return preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $purpose ) ? $purpose : 'contact';
	}

	/**
	 * Shortcode [formconfirmed purpose="contact"]: the widget for a form of your own (inside its <form>).
	 * The form's handler then calls formconfirmed_check() - see Settings -> formconfirmed -> Developers.
	 * Prints nothing while the keys are missing.
	 */
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'purpose' => 'contact' ), $atts, 'formconfirmed' );
		if ( ! self::on() || self::paused() ) {
			return '';
		}
		if ( ! wp_script_is( 'formconfirmed-widget', 'registered' ) ) {
			self::register_script();
		}
		return self::element( 'custom', self::clean_purpose( $atts['purpose'] ) );
	}

	/**
	 * Checks a submission of a form of your own (formconfirmed_check()). $args:
	 *   purpose  the purpose of the widget in that form (default contact)
	 *   content  the message text, for the content check (optional)
	 *   email    the e-mail address of the form (optional)
	 *   data     where the token is (default: the POST request)
	 *   form     a name for held copies, fields: list of array(label, value) - when given, a message
	 *            that is not passed is kept under Held messages
	 * Returns array(pass, decision pass|hold|reject, action, reasons, message).
	 */
	public static function check( $args = array() ) {
		$args    = wp_parse_args( (array) $args, array( 'purpose' => 'contact', 'content' => '', 'email' => '', 'data' => null, 'form' => '', 'fields' => array() ) );
		$purpose = self::clean_purpose( $args['purpose'] );
		$adapter = 'login' === $purpose ? 'login' : 'custom';
		if ( ! self::on() ) {
			$res = array( 'action' => 'none', 'score' => null, 'reasons' => array(), 'unavailable' => true, 'codes' => array( 'not-configured' ) );
		} else {
			$res = self::verify( $adapter, is_array( $args['data'] ) ? $args['data'] : null, (string) $args['content'], (string) $args['email'], $purpose );
		}
		if ( is_array( $args['data'] ) ) {
			Formconfirmed_Backup::use_data( $args['data'] );
		}
		$d = self::decide( $res, $adapter );
		if ( 'pass' !== $d && '' !== (string) $args['form'] && $args['fields'] ) {
			self::keep( 'custom', (string) $args['form'], (array) $args['fields'] );
		}
		return array(
			'pass'     => 'pass' === $d,
			'decision' => $d,
			'action'   => $res['action'],
			'reasons'  => $res['reasons'],
			'message'  => 'pass' === $d ? '' : self::message( $res ),
		);
	}

	/** Prints the element (for actions that print inside the form). */
	private static function print_element( $adapter ) {
		echo wp_kses( self::element( $adapter ), array( 'div' => array( 'class' => true, 'data-sitekey' => true, 'data-purpose' => true ) ) );
	}

	/** A field of the current POST request (the form plugins verify their own requests). */
	public static function posted( $name ) {
		$v = filter_input( INPUT_POST, $name, FILTER_UNSAFE_RAW );
		return is_string( $v ) ? $v : '';
	}

	/** The visitor's address. Behind a proxy or CDN the filter formconfirmed_client_ip can return the real one. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'formconfirmed_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	// ------------------------------------------------------------------ the check

	/**
	 * Asks the verify API.
	 * $data:    where the token and the trap field are (null: the POST request; Fluent Forms: its parsed data).
	 * $content: message text for the content check (only if switched on; briefly processed by the service, with a temporary queue for AI checks).
	 * $email:   the e-mail address of the form (disposable providers, domains without mail).
	 * Returns array(action, score, reasons, unavailable, codes).
	 */
	public static function verify( $adapter, $data = null, $content = '', $email = '', $purpose = '' ) {
		if ( null !== self::$result && self::$checked === $adapter ) {
			return self::$result;   // some form plugins run their validation filter twice; a token is valid once
		}
		self::$checked = $adapter;
		$o     = self::opt();
		$token = is_array( $data ) ? ( isset( $data[ self::FIELD ] ) && is_string( $data[ self::FIELD ] ) ? $data[ self::FIELD ] : '' ) : self::posted( self::FIELD );
		$hp    = is_array( $data ) ? ( isset( $data[ self::HP ] ) && is_string( $data[ self::HP ] ) ? $data[ self::HP ] : '' ) : self::posted( self::HP );
		$body  = array(
			'secret'           => $o['secret'],
			'response'         => substr( sanitize_text_field( $token ), 0, 4000 ),
			'remoteip'         => self::client_ip(),
			'hp'               => substr( sanitize_text_field( $hp ), 0, 2048 ),
			'expected_purpose' => '' !== $purpose ? $purpose : self::PURPOSES[ $adapter ],
			'form_mode'        => 'interactive',
		);
		$email = sanitize_email( (string) $email );
		if ( '' !== $email && is_email( $email ) ) {
			$body['email'] = $email;
		}
		if ( $o['content'] && 'login' !== $adapter && '' !== trim( (string) $content ) ) {
			$body['content'] = mb_substr( (string) $content, 0, 2000 );
		}
		$r = wp_remote_post( FORMCONFIRMED_API . '/verify', array(
			'timeout' => self::TIMEOUT,
			'body'    => $body,
		) );
		$res          = self::read_answer( $r );
		self::$result = $res;
		self::note( $res );
		return $res;
	}

	/**
	 * Turns the HTTP answer into a result. Anything that is not a well-formed answer of the service
	 * (no answer, HTTP error, unknown action, error codes that mean no check took place) is
	 * "unavailable" - never a pass. Public for the tests.
	 */
	public static function read_answer( $r ) {
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$j    = ( is_wp_error( $r ) || 200 !== $code ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$none = array(
			'action'      => 'none',
			'score'       => null,
			'reasons'     => array(),
			'unavailable' => true,
			'codes'       => array( is_wp_error( $r ) ? 'no-answer' : 'http-' . $code ),
		);
		if ( ! is_array( $j ) || ! isset( $j['action'] ) || ! is_string( $j['action'] ) ) {
			if ( is_array( $j ) ) {
				$none['codes'] = array( 'invalid-answer' );
			}
			return $none;
		}
		$codes   = array_values( array_filter( (array) ( isset( $j['error-codes'] ) ? $j['error-codes'] : array() ), 'is_string' ) );
		$reasons = array_values( array_filter( (array) ( isset( $j['reasons'] ) ? $j['reasons'] : array() ), 'is_string' ) );
		$own     = array_values( array_intersect( $codes, self::NO_CHECK_CODES ) );
		if ( $own ) {
			$none['codes'] = $own;
			return $none;
		}
		if ( ! in_array( $j['action'], array( 'allow', 'flag', 'block' ), true ) || ( 'allow' === $j['action'] && true !== ( isset( $j['success'] ) ? $j['success'] : null ) ) ) {
			$none['codes'] = array( 'invalid-answer' );
			return $none;
		}
		return array(
			'action'      => $j['action'],
			'score'       => isset( $j['score'] ) && is_numeric( $j['score'] ) ? (float) $j['score'] : null,
			'reasons'     => $reasons,
			// The browser could not reach the service: an unsigned claim, not a check.
			'unavailable' => 'block' !== $j['action'] && in_array( 'service_unavailable', $reasons, true ),
			'codes'       => $codes,
		);
	}

	/**
	 * pass, hold or reject. A clear "allow" passes; "block" rejects; "flag" and "no clear answer"
	 * pass only through the independent backup check (no clear answer) or not at all (flag).
	 */
	public static function decide( $res, $adapter ) {
		if ( null !== self::$decision ) {
			return self::$decision;
		}
		$mode = self::opt()['uncertain'];
		if ( 'block' === $res['action'] ) {
			$d = 'reject';
		} elseif ( $res['unavailable'] ) {
			if ( 'login' === $adapter ) {
				$d = 'pass';   // the password check is the independent check
			} elseif ( Formconfirmed_Backup::ready( $adapter ) && Formconfirmed_Backup::passed() ) {
				$d = 'pass';
				self::count( 'fallback' );
			} else {
				$d = $mode;
			}
		} elseif ( 'flag' === $res['action'] ) {
			$d = 'login' === $adapter ? 'pass' : $mode;
		} else {
			$d = 'pass';
		}
		if ( 'pass' !== $d ) {
			self::count( 'hold' === $d ? 'held' : 'rejected' );
		}
		self::$decision = $d;
		return $d;
	}

	/** For the tests: forget the current submission. */
	public static function reset() {
		self::$result   = null;
		self::$decision = null;
		self::$checked  = '';
	}

	/** Counts and remembers the last answer (for the settings page). */
	private static function note( $res ) {
		$o = self::opt();
		$k = $res['unavailable'] ? 'unreachable' : $res['action'];
		$o['stats'][ $k ] = (int) $o['stats'][ $k ] + 1;
		if ( $res['unavailable'] ) {
			$o['problem'] = array(
				'code' => implode( ',', $res['codes'] ? $res['codes'] : $res['reasons'] ),
				'at'   => time(),
			);
		} else {
			$o['ok_at'] = time();
		}
		self::save_opt( $o );
	}

	private static function count( $what ) {
		$o = self::opt();
		$o['stats'][ $what ] = (int) $o['stats'][ $what ] + 1;
		self::save_opt( $o );
	}

	/** Reason key of a result (held messages). */
	public static function reason_key( $res ) {
		if ( 'block' === $res['action'] ) {
			return 'fc_block';
		}
		return $res['unavailable'] ? 'fc_unavailable' : 'fc_flag';
	}

	/** Keeps a message that was not passed (14 days), so a person stopped by mistake is not lost. */
	private static function keep( $source, $form, $pairs ) {
		if ( self::$result ) {
			Formconfirmed_Held::store( $source, $form, self::reason_key( self::$result ), $pairs );
		}
	}

	/** The longest text of a list of pairs - usually the message. */
	private static function longest( $pairs ) {
		$best = '';
		foreach ( (array) $pairs as $p ) {
			if ( mb_strlen( (string) $p[1] ) > mb_strlen( $best ) ) {
				$best = (string) $p[1];
			}
		}
		return $best;
	}

	/** The first e-mail address of a list of pairs. */
	private static function first_email( $pairs ) {
		foreach ( (array) $pairs as $p ) {
			if ( is_email( trim( (string) $p[1] ) ) ) {
				return trim( (string) $p[1] );
			}
		}
		return '';
	}

	/** What the visitor sees when a message is refused. */
	private static function message( $res = null ) {
		$res = $res ? $res : self::$result;
		if ( $res && $res['unavailable'] ) {
			return __( 'Your message could not be checked right now. Please try again in a few minutes.', 'formconfirmed' );
		}
		return __( 'Your message could not be sent. Please reload the page and try again.', 'formconfirmed' );
	}

	/** Should a form field be left out of the kept copy (our own fields, tokens)? */
	private static function own_field( $name ) {
		$name = (string) $name;
		return 0 === strpos( $name, '_' ) || 0 === strpos( $name, 'fcp-' ) || 0 === strpos( $name, 'formconfirmed_' ) || 0 === strpos( $name, 'formexposed_' ) || 'g-recaptcha-response' === $name;
	}

	// ------------------------------------------------------------------ adapters

	/** Contact Form 7: element in the form; refuse = CF7 spam message; hold = no mail, the visitor sees the normal confirmation. */
	public static function adapter_cf7() {
		add_filter( 'wpcf7_form_elements', array( __CLASS__, 'cf7_element' ) );
		add_action( 'wpcf7_init', array( __CLASS__, 'cf7_tag_register' ) );
		add_filter( 'wpcf7_spam', array( __CLASS__, 'cf7_spam' ), 30, 2 );
		add_filter( 'wpcf7_skip_mail', array( __CLASS__, 'cf7_skip_mail' ), 30 );
	}

	/** The form tag [formconfirmed]: puts the widget where the tag stands (otherwise it goes at the end of the form). */
	public static function cf7_tag_register() {
		if ( function_exists( 'wpcf7_add_form_tag' ) ) {
			wpcf7_add_form_tag( 'formconfirmed', array( __CLASS__, 'cf7_tag' ) );
		}
	}

	public static function cf7_tag( $tag = null ) {
		return self::form_enabled( 'cf7', self::cf7_current() ) ? self::element( 'cf7' ) : '';
	}

	/** The ID of the Contact Form 7 form being rendered. */
	private static function cf7_current() {
		$f = class_exists( 'WPCF7_ContactForm' ) && method_exists( 'WPCF7_ContactForm', 'get_current' ) ? WPCF7_ContactForm::get_current() : null;
		return $f && method_exists( $f, 'id' ) ? (int) $f->id() : 0;
	}

	public static function cf7_element( $html ) {
		if ( ! self::form_enabled( 'cf7', self::cf7_current() ) ) {
			return $html;
		}
		return false === strpos( $html, 'fcp-widget' ) ? $html . self::element( 'cf7' ) : $html;
	}

	private static function cf7_pairs( $submission ) {
		$pairs = array();
		$data  = $submission && method_exists( $submission, 'get_posted_data' ) ? (array) $submission->get_posted_data() : array();
		foreach ( $data as $k => $v ) {
			if ( self::own_field( $k ) ) {
				continue;
			}
			$v = is_array( $v ) ? implode( ', ', array_filter( $v, 'is_scalar' ) ) : (string) $v;
			if ( '' !== $v ) {
				$pairs[] = array( (string) $k, $v );
			}
		}
		return $pairs;
	}

	public static function cf7_spam( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;   // another check already said spam
		}
		$form = $submission && method_exists( $submission, 'get_contact_form' ) ? $submission->get_contact_form() : null;
		if ( ! self::form_enabled( 'cf7', $form && method_exists( $form, 'id' ) ? $form->id() : 0 ) ) {
			return $spam;   // protection switched off for this form
		}
		$pairs = self::cf7_pairs( $submission );
		$res   = self::verify( 'cf7', null, self::longest( $pairs ), self::first_email( $pairs ) );
		$d     = self::decide( $res, 'cf7' );
		if ( 'pass' === $d ) {
			return false;
		}
		$title = $submission && method_exists( $submission, 'get_contact_form' ) && $submission->get_contact_form() ? (string) $submission->get_contact_form()->title() : 'Contact Form 7';
		self::keep( 'cf7', $title, $pairs );
		if ( 'hold' === $d ) {
			return false;   // wpcf7_skip_mail keeps the e-mail back
		}
		if ( $submission && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'formconfirmed', 'reason' => 'formconfirmed: ' . self::reason_key( $res ) ) );
		}
		return true;
	}

	public static function cf7_skip_mail( $skip ) {
		return 'hold' === self::$decision ? true : $skip;
	}

	/** WPForms: element before the submit button; refuse = error above the form; hold = no notification e-mail. */
	public static function adapter_wpforms() {
		add_action( 'wpforms_display_submit_before', array( __CLASS__, 'wpforms_element' ), 10, 1 );
		add_action( 'wpforms_process_before', array( __CLASS__, 'wpforms_entry' ), 10, 1 );
		add_filter( 'wpforms_process_initial_errors', array( __CLASS__, 'wpforms_errors' ), 30, 2 );
		add_filter( 'wpforms_entry_email', array( __CLASS__, 'wpforms_email' ), 30 );
	}

	public static function wpforms_element( $form_data = array() ) {
		if ( self::form_enabled( 'wpforms', isset( $form_data['id'] ) ? $form_data['id'] : 0 ) ) {
			self::print_element( 'wpforms' );
		}
	}

	public static function wpforms_entry( $entry ) {
		self::$wpforms_entry = is_array( $entry ) ? $entry : array();
	}

	public static function wpforms_errors( $errors, $form_data ) {
		$id = isset( $form_data['id'] ) ? $form_data['id'] : 0;
		if ( ! empty( $errors[ $id ] ) || ! self::form_enabled( 'wpforms', $id ) ) {
			return $errors;   // already refused, or protection switched off for this form
		}
		$pairs  = array();
		$fields = isset( self::$wpforms_entry['fields'] ) && is_array( self::$wpforms_entry['fields'] ) ? self::$wpforms_entry['fields'] : array();
		foreach ( $fields as $fid => $wert ) {
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$label   = isset( $form_data['fields'][ $fid ]['label'] ) ? (string) $form_data['fields'][ $fid ]['label'] : 'Field ' . $fid;
				$pairs[] = array( $label, wp_unslash( implode( ' ', $teile ) ) );
			}
		}
		$res = self::verify( 'wpforms', null, self::longest( $pairs ), self::first_email( $pairs ) );
		$d   = self::decide( $res, 'wpforms' );
		if ( 'pass' !== $d ) {
			self::keep( 'wpforms', isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : 'WPForms', $pairs );
		}
		if ( 'reject' === $d ) {
			$errors[ $id ]['header'] = esc_html( self::message( $res ) );
		}
		return $errors;
	}

	public static function wpforms_email( $send ) {
		return 'hold' === self::$decision ? false : $send;
	}

	/** Gravity Forms: element before the submit button; anything not passed is filed under Entries -> Spam (no notification). */
	public static function adapter_gravityforms() {
		add_filter( 'gform_submit_button', array( __CLASS__, 'gform_button' ), 10, 2 );
		add_filter( 'gform_entry_is_spam', array( __CLASS__, 'gform_spam' ), 30, 3 );
	}

	public static function gform_button( $button, $form = array() ) {
		return self::form_enabled( 'gravityforms', isset( $form['id'] ) ? $form['id'] : 0 ) ? self::element( 'gravityforms' ) . $button : $button;
	}

	public static function gform_spam( $is_spam, $form = array(), $entry = array() ) {
		if ( $is_spam || ! self::form_enabled( 'gravityforms', isset( $form['id'] ) ? $form['id'] : 0 ) ) {
			return $is_spam;
		}
		$pairs = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $f ) {
			if ( ! is_object( $f ) || ! isset( $f->id ) ) {
				continue;
			}
			$ids   = ! empty( $f->inputs ) && is_array( $f->inputs ) ? wp_list_pluck( $f->inputs, 'id' ) : array( $f->id );
			$teile = array();
			foreach ( $ids as $iid ) {
				if ( isset( $entry[ (string) $iid ] ) && is_string( $entry[ (string) $iid ] ) && '' !== $entry[ (string) $iid ] ) {
					$teile[] = $entry[ (string) $iid ];
				}
			}
			if ( $teile ) {
				$pairs[] = array( isset( $f->label ) && '' !== (string) $f->label ? (string) $f->label : 'Field ' . $f->id, implode( ' ', $teile ) );
			}
		}
		$res = self::verify( 'gravityforms', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'gravityforms' ) ) {
			return false;
		}
		self::keep( 'gravityforms', isset( $form['title'] ) ? (string) $form['title'] : 'Gravity Forms', $pairs );
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'set_spam_filter' ) ) {
			GFCommon::set_spam_filter( isset( $form['id'] ) ? $form['id'] : 0, 'formconfirmed', self::reason_key( $res ) );
		}
		return true;
	}

	/** Elementor Pro forms: the helper script puts the element into the form; anything not passed is refused. */
	public static function adapter_elementor() {
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'elementor_render' ), 10, 2 );
		add_action( 'elementor_pro/forms/validation', array( __CLASS__, 'elementor_validation' ), 30, 2 );
	}

	public static function elementor_render( $content, $widget = null ) {
		if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'form' === $widget->get_name() ) {
			self::load( true );
		}
		return $content;
	}

	public static function elementor_validation( $record, $ajax_handler ) {
		if ( is_object( $ajax_handler ) && ! empty( $ajax_handler->errors ) ) {
			return;   // already refused
		}
		$pairs = array();
		foreach ( is_object( $record ) && method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : array() as $id => $f ) {
			if ( isset( $f['value'] ) && is_string( $f['value'] ) && '' !== $f['value'] ) {
				$pairs[] = array( isset( $f['title'] ) && '' !== (string) $f['title'] ? (string) $f['title'] : (string) $id, $f['value'] );
			}
		}
		$res = self::verify( 'elementor', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'elementor' ) ) {
			return;
		}
		$name = is_object( $record ) && method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		self::keep( 'elementor', '' !== $name ? $name : 'Elementor', $pairs );
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error_message' ) ) {
			$ajax_handler->add_error_message( self::message( $res ) );
		}
		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_error' ) ) {
			$ajax_handler->add_error( 'formconfirmed', self::message( $res ) );
		}
	}

	/** Fluent Forms: element before the submit button; anything not passed is refused. */
	public static function adapter_fluentform() {
		add_action( 'fluentform/render_item_submit_button', array( __CLASS__, 'fluent_element' ), 5, 2 );
		add_action( 'fluentform/before_form_render', array( __CLASS__, 'fluent_load' ), 10, 1 );
		add_filter( 'fluentform/validation_errors', array( __CLASS__, 'fluent_errors' ), 30, 4 );
	}

	public static function fluent_element( $item = null, $form = null ) {
		if ( self::form_enabled( 'fluentform', is_object( $form ) && isset( $form->id ) ? $form->id : 0 ) ) {
			self::print_element( 'fluentform' );
		}
	}

	public static function fluent_load( $form = null ) {
		if ( self::form_enabled( 'fluentform', is_object( $form ) && isset( $form->id ) ? $form->id : 0 ) ) {
			self::load( true );   // multi-step forms have no submit button item: the helper equips them
		}
	}

	public static function fluent_errors( $errors, $form_data, $form = null, $fields = array() ) {
		if ( ! empty( $errors ) || ! is_array( $form_data ) || ! self::form_enabled( 'fluentform', is_object( $form ) && isset( $form->id ) ? $form->id : 0 ) ) {
			return $errors;
		}
		$pairs = array();
		foreach ( $form_data as $name => $wert ) {
			if ( self::own_field( $name ) ) {
				continue;
			}
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$pairs[] = array( (string) $name, implode( ' ', $teile ) );
			}
		}
		// Fluent Forms keeps only its own fields in $form_data; token, trap field and backup fields travel in the raw "data" string.
		$raw = array();
		wp_parse_str( self::posted( 'data' ), $raw );
		Formconfirmed_Backup::use_data( $raw );
		$src = array(
			self::FIELD => isset( $raw[ self::FIELD ] ) && is_string( $raw[ self::FIELD ] ) ? $raw[ self::FIELD ] : '',
			self::HP    => isset( $raw[ self::HP ] ) && is_string( $raw[ self::HP ] ) ? $raw[ self::HP ] : '',
		);
		$res = self::verify( 'fluentform', $src, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'fluentform' ) ) {
			return $errors;
		}
		self::keep( 'fluentform', is_object( $form ) && isset( $form->title ) ? (string) $form->title : 'Fluent Forms', $pairs );
		$errors['formconfirmed'] = array( self::message( $res ) );
		return $errors;
	}

	/** Formidable Forms: element inside the form; anything not passed is refused. */
	public static function adapter_formidable() {
		add_action( 'frm_entry_form', array( __CLASS__, 'formidable_element' ), 10, 1 );
		add_filter( 'frm_validate_entry', array( __CLASS__, 'formidable_errors' ), 30, 2 );
	}

	public static function formidable_element( $form = null ) {
		if ( self::form_enabled( 'formidable', is_object( $form ) && isset( $form->id ) ? $form->id : 0 ) ) {
			self::print_element( 'formidable' );
		}
	}

	public static function formidable_errors( $errors, $values ) {
		if ( ! empty( $errors ) || ! is_array( $values ) || ! self::form_enabled( 'formidable', isset( $values['form_id'] ) ? $values['form_id'] : 0 ) ) {
			return $errors;
		}
		$pairs = array();
		foreach ( isset( $values['item_meta'] ) && is_array( $values['item_meta'] ) ? $values['item_meta'] : array() as $fid => $wert ) {
			if ( ! is_numeric( $fid ) ) {
				continue;
			}
			$teile = array_filter( array_map( 'strval', array_filter( (array) $wert, 'is_scalar' ) ), 'strlen' );
			if ( $teile ) {
				$pairs[] = array( 'Field ' . $fid, wp_unslash( implode( ' ', $teile ) ) );
			}
		}
		$res = self::verify( 'formidable', null, self::longest( $pairs ), self::first_email( $pairs ) );
		if ( 'pass' === self::decide( $res, 'formidable' ) ) {
			return $errors;
		}
		self::keep( 'formidable', 'Formidable', $pairs );
		$errors['formconfirmed'] = self::message( $res );
		return $errors;
	}

	/**
	 * Comments: element above the submit button. Only comments from the comment form of the theme
	 * (wp-comments-post.php) - REST, XML-RPC, pingbacks carry no token. block = refused with an
	 * error page; hold = held for moderation; refuse (setting) = Comments -> Spam.
	 */
	public static function adapter_comments() {
		add_filter( 'comment_form_submit_field', array( __CLASS__, 'comment_field' ) );
		add_filter( 'pre_comment_approved', array( __CLASS__, 'comment_approved' ), 30, 2 );
	}

	public static function comment_field( $field ) {
		return self::element( 'comments' ) . $field;
	}

	public static function comment_approved( $approved, $commentdata = array() ) {
		if ( 'spam' === $approved || 'trash' === $approved || is_wp_error( $approved ) ) {
			return $approved;
		}
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-comments-post.php' !== $GLOBALS['pagenow'] ) {
			return $approved;
		}
		if ( is_user_logged_in() && current_user_can( 'moderate_comments' ) ) {
			return $approved;
		}
		$c = is_array( $commentdata ) ? $commentdata : array();
		if ( ! empty( $c['comment_type'] ) && 'comment' !== $c['comment_type'] ) {
			return $approved;
		}
		$res = self::verify( 'comments', null, isset( $c['comment_content'] ) ? (string) $c['comment_content'] : '', isset( $c['comment_author_email'] ) ? (string) $c['comment_author_email'] : '' );
		$d   = self::decide( $res, 'comments' );
		if ( 'block' === $res['action'] ) {
			wp_die( esc_html( self::message( $res ) ), '', array( 'response' => 400, 'back_link' => true ) );
		}
		if ( 'hold' === $d ) {
			return 0;
		}
		return 'reject' === $d ? 'spam' : $approved;
	}

	/** WordPress registration (wp-login.php?action=register): anything not passed is refused, no account. */
	public static function adapter_register() {
		add_action( 'register_form', array( __CLASS__, 'register_element' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'register_errors' ), 30, 3 );
	}

	public static function register_element() {
		self::print_element( 'register' );
	}

	public static function register_errors( $errors, $login = '', $email = '' ) {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] || ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return $errors;
		}
		$res = self::verify( 'register', null, '', (string) $email );
		if ( 'pass' !== self::decide( $res, 'register' ) ) {
			$errors->add( 'formconfirmed', esc_html( self::message( $res ) ) );
		}
		return $errors;
	}

	/** WooCommerce "My account" registration (not the account creation in the checkout). */
	public static function adapter_woo_register() {
		add_action( 'woocommerce_register_form', array( __CLASS__, 'woo_element' ) );
		add_action( 'woocommerce_register_post', array( __CLASS__, 'woo_register' ), 30, 3 );
	}

	public static function woo_element() {
		self::print_element( 'woo_register' );
	}

	public static function woo_register( $username, $email, $errors ) {
		if ( '' === self::posted( 'woocommerce-register-nonce' ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || doing_action( 'woocommerce_checkout_process' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_wp_error( $errors ) || $errors->has_errors() ) {
			return;
		}
		$res = self::verify( 'woo_register', null, '', (string) $email );
		if ( 'pass' !== self::decide( $res, 'woo_register' ) ) {
			$errors->add( 'formconfirmed', esc_html( self::message( $res ) ) );
		}
	}

	/** WordPress login (off by default): only a "block" refuses - the password is the independent check. */
	public static function adapter_login() {
		add_action( 'login_form', array( __CLASS__, 'login_element' ) );
		add_filter( 'authenticate', array( __CLASS__, 'login' ), 40, 3 );
	}

	public static function login_element() {
		self::print_element( 'login' );
	}

	public static function login( $user, $username = '', $password = '' ) {
		if ( ! ( $user instanceof WP_User ) || ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] || '' === self::posted( 'log' ) ) {
			return $user;   // only a correct login from the login page is checked
		}
		$res = self::verify( 'login', null, '', '' );
		if ( 'pass' === self::decide( $res, 'login' ) ) {
			return $user;
		}
		return new WP_Error( 'formconfirmed', '<strong>' . esc_html__( 'Error:', 'formconfirmed' ) . '</strong> ' . esc_html__( 'Please reload the login page and try again.', 'formconfirmed' ) );
	}
}
