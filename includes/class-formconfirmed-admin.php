<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formconfirmed - Settings -> formconfirmed.
 *
 * Tabs: Overview (status, counters, connection test), Forms (switch every form on or off),
 * Settings (keys, message check, backup check, what happens to unclear messages), Held messages,
 * Developers (shortcode, PHP functions, Contact Form 7 tag, filters). Also the notice while the
 * service gives no clear answer, and the paragraph for the privacy policy guide.
 */
class Formconfirmed_Admin {

	const PAGE = 'formconfirmed';
	const TABS = array( 'overview', 'forms', 'settings', 'held', 'developers' );

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_formconfirmed_save', array( __CLASS__, 'action_save' ) );
		add_action( 'admin_post_formconfirmed_save_forms', array( __CLASS__, 'action_save_forms' ) );
		add_action( 'admin_post_formconfirmed_test', array( __CLASS__, 'action_test' ) );
		add_action( 'admin_post_formconfirmed_connect', array( __CLASS__, 'action_connect' ) );
		add_action( 'admin_post_formconfirmed_dismiss', array( __CLASS__, 'action_dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FORMCONFIRMED_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function menu() {
		add_options_page( 'formconfirmed', 'formconfirmed', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'formconfirmed-admin', plugins_url( 'assets/css/admin.css', FORMCONFIRMED_FILE ), array(), FORMCONFIRMED_VERSION );
		}
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'formconfirmed' ) . '</a>' );
		return $links;
	}

	/** The site is connected to a formexposed account through the formexposed plugin (one-click keys). */
	public static function formexposed_linked() {
		return class_exists( 'Formexposed_Core' ) && method_exists( 'Formexposed_Core', 'linked' ) && Formexposed_Core::linked()
			&& defined( 'FORMEXPOSED_API' ) && '' !== FORMEXPOSED_API;
	}

	// ------------------------------------------------------------------ problems

	/** A problem with the service in the last 24 hours, after which no clear answer came. */
	public static function recent_problem() {
		$o = Formconfirmed_Core::opt();
		if ( empty( $o['problem']['at'] ) || (int) $o['problem']['at'] < time() - DAY_IN_SECONDS ) {
			return null;
		}
		if ( (int) $o['ok_at'] > (int) $o['problem']['at'] ) {
			return null;
		}
		return $o['problem'];
	}

	public static function problem_text( $code ) {
		$codes = explode( ',', (string) $code );
		if ( array_intersect( $codes, array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
			return __( 'The service does not accept the secret key. Enter it again or create new keys in your account.', 'formconfirmed' );
		}
		if ( in_array( 'site-paused', $codes, true ) ) {
			return __( 'The protected site is paused in your account (or its plan ended). Activate it again there.', 'formconfirmed' );
		}
		if ( in_array( 'service-disabled', $codes, true ) ) {
			return __( 'The service is switched off at the moment.', 'formconfirmed' );
		}
		if ( in_array( 'service_unavailable', $codes, true ) ) {
			return __( 'A visitor\'s browser could not reach the service (for example a network filter blocks it).', 'formconfirmed' );
		}
		return __( 'The service could not be reached or did not give a valid answer.', 'formconfirmed' );
	}

	/** The state of the protection: off, waiting, problem or on. */
	public static function state() {
		if ( ! Formconfirmed_Core::on() ) {
			return 'off';
		}
		if ( Formconfirmed_Core::paused() ) {
			return 'waiting';
		}
		return self::recent_problem() ? 'problem' : 'on';
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		if ( Formconfirmed_Core::paused() ) {
			echo '<div class="notice notice-warning"><p><strong>formconfirmed:</strong> '
				. esc_html__( 'Waiting: the form protection tab of the formexposed plugin still checks your forms. Update formexposed to 1.10.0 or later - then formconfirmed takes over with the same keys.', 'formconfirmed' ) . '</p></div>';
			return;
		}
		if ( ! Formconfirmed_Core::on() && 'plugins' === $screen->id ) {
			echo '<div class="notice notice-info"><p><strong>formconfirmed:</strong> '
				. esc_html__( 'Add your keys to protect your forms.', 'formconfirmed' )
				. ' <a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Set up formconfirmed', 'formconfirmed' ) . '</a></p></div>';
			return;
		}
		$p = self::recent_problem();
		if ( ! $p || (int) get_option( 'formconfirmed_notice_off', 0 ) > time() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>formconfirmed:</strong> '
			. esc_html( self::problem_text( $p['code'] ) ) . ' '
			. esc_html__( 'Messages without a clear check are delivered only if the backup check on this site passed; otherwise they are held or refused, never let through unseen.', 'formconfirmed' )
			. ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open formconfirmed', 'formconfirmed' ) . '</a> · '
			. '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formconfirmed_dismiss' ), 'formconfirmed_dismiss' ) ) . '">' . esc_html__( 'Hide for a day', 'formconfirmed' ) . '</a></p></div>';
	}

	public static function action_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_dismiss' );
		update_option( 'formconfirmed_notice_off', time() + DAY_IN_SECONDS, false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	// ------------------------------------------------------------------ page

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW ) );   // only picks the tab that is shown
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';
		$msg = get_transient( 'formconfirmed_msg' );
		delete_transient( 'formconfirmed_msg' );
		$open   = Formconfirmed_Held::count_open();
		$labels = array(
			'overview'   => __( 'Overview', 'formconfirmed' ),
			'forms'      => __( 'Forms', 'formconfirmed' ),
			'settings'   => __( 'Settings', 'formconfirmed' ),
			'held'       => __( 'Held messages', 'formconfirmed' ),
			'developers' => __( 'Developers', 'formconfirmed' ),
		);
		?>
		<div class="wrap fc-wrap">
			<h1 class="fc-title"><span class="fc-mark" aria-hidden="true"></span>formconfirmed</h1>
			<nav class="nav-tab-wrapper fc-tabs" aria-label="<?php esc_attr_e( 'formconfirmed sections', 'formconfirmed' ); ?>">
				<?php foreach ( $labels as $k => $l ) : ?>
					<a class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( 'overview' === $k ? array() : array( 'tab' => $k ) ) ); ?>"<?php echo $tab === $k ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $l ); ?>
						<?php if ( 'held' === $k && $open > 0 ) : ?>
							<span class="fc-count"><?php echo esc_html( number_format_i18n( $open ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( is_array( $msg ) && isset( $msg[0], $msg[1] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'ok' === $msg[0] ? 'success' : ( 'warning' === $msg[0] ? 'warning' : 'error' ) ); ?> is-dismissible"><p><?php echo esc_html( $msg[1] ); ?></p></div>
			<?php endif; ?>
			<?php
			switch ( $tab ) {
				case 'forms':
					self::page_forms();
					break;
				case 'settings':
					self::page_settings();
					break;
				case 'held':
					self::page_held();
					break;
				case 'developers':
					self::page_developers();
					break;
				default:
					self::page_overview();
			}
			?>
		</div>
		<?php
	}

	/** Status badge. */
	private static function badge( $state ) {
		$t = array(
			'off'     => __( 'Not active', 'formconfirmed' ),
			'waiting' => __( 'Waiting', 'formconfirmed' ),
			'problem' => __( 'Active, with a problem', 'formconfirmed' ),
			'on'      => __( 'Active', 'formconfirmed' ),
		);
		return '<span class="fc-badge fc-badge-' . esc_attr( $state ) . '">' . esc_html( $t[ $state ] ) . '</span>';
	}

	private static function page_overview() {
		$o     = Formconfirmed_Core::opt();
		$state = self::state();
		$p     = self::recent_problem();
		$s     = $o['stats'];
		?>
		<div class="fc-card fc-hero">
			<div class="fc-hero-head">
				<?php echo wp_kses( self::badge( $state ), array( 'span' => array( 'class' => true ) ) ); ?>
				<h2><?php esc_html_e( 'Invisible spam protection for your forms', 'formconfirmed' ); ?></h2>
			</div>
			<?php if ( 'off' === $state ) : ?>
				<p><?php esc_html_e( 'Three steps and your forms are protected - without puzzles for your visitors.', 'formconfirmed' ); ?></p>
				<ol class="fc-steps">
					<li><a href="<?php echo esc_url( FORMCONFIRMED_APP . '/register' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create a free account at formconfirmed.com', 'formconfirmed' ); ?></a> <?php esc_html_e( 'and add this site. You get a site key and a secret key.', 'formconfirmed' ); ?></li>
					<li><a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Enter both keys', 'formconfirmed' ); ?></a><?php echo self::formexposed_linked() ? ' ' . esc_html__( '(or fetch them with one click there).', 'formconfirmed' ) : ''; ?></li>
					<li><?php esc_html_e( 'Choose which forms to protect, then test the connection.', 'formconfirmed' ); ?></li>
				</ol>
			<?php elseif ( 'waiting' === $state ) : ?>
				<p><?php esc_html_e( 'The form protection tab of the formexposed plugin still checks your forms. Update formexposed to 1.10.0 or later - then formconfirmed takes over with the same keys.', 'formconfirmed' ); ?></p>
			<?php elseif ( 'problem' === $state ) : ?>
				<p><?php echo esc_html( self::problem_text( $p['code'] ) ); ?>
				<?php
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( '(last seen %s)', 'formconfirmed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $p['at'] ) ) );
				?>
				</p>
			<?php else : ?>
				<p>
				<?php
				$n = count( self::protected_list() );
				/* translators: %d: number of protected forms and places */
				echo esc_html( sprintf( _n( '%d form or place is protected.', '%d forms and places are protected.', $n, 'formconfirmed' ), $n ) ) . ' ';
				if ( $o['ok_at'] ) {
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Last answer of the service: %s.', 'formconfirmed' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $o['ok_at'] ) ) );
				} else {
					esc_html_e( 'No message has been checked yet.', 'formconfirmed' );
				}
				?>
				</p>
			<?php endif; ?>
			<?php if ( Formconfirmed_Core::on() ) : ?>
				<div class="fc-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'formconfirmed_test' ); ?>
						<input type="hidden" name="action" value="formconfirmed_test">
						<button class="button button-primary"><?php esc_html_e( 'Test the connection', 'formconfirmed' ); ?></button>
					</form>
					<a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'forms' ) ) ); ?>"><?php esc_html_e( 'Choose forms', 'formconfirmed' ); ?></a>
					<a class="button" href="<?php echo esc_url( FORMCONFIRMED_APP . '/protection' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open your account', 'formconfirmed' ); ?></a>
				</div>
			<?php endif; ?>
			<?php if ( $o['imported'] ) : ?>
				<p class="description"><?php esc_html_e( 'The keys and settings were taken over from the form protection tab of the formexposed plugin.', 'formconfirmed' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( Formconfirmed_Core::on() ) : ?>
		<h2 class="fc-h"><?php esc_html_e( 'Since activation', 'formconfirmed' ); ?></h2>
		<div class="fc-tiles">
			<?php
			$tiles = array(
				array( $s['allow'], __( 'allowed', 'formconfirmed' ), 'ok' ),
				array( $s['block'], __( 'blocked', 'formconfirmed' ), 'bad' ),
				array( $s['flag'], __( 'flagged', 'formconfirmed' ), 'warn' ),
				array( $s['unreachable'], __( 'without a clear answer', 'formconfirmed' ), 'warn' ),
				array( $s['fallback'], __( 'delivered by the backup check', 'formconfirmed' ), 'ok' ),
				array( $s['held'], __( 'held for review', 'formconfirmed' ), 'warn' ),
				array( $s['rejected'], __( 'refused (a copy is kept)', 'formconfirmed' ), 'bad' ),
			);
			foreach ( $tiles as $t ) :
				?>
				<div class="fc-tile fc-tile-<?php echo esc_attr( $t[2] ); ?>"><span class="fc-tile-n"><?php echo esc_html( number_format_i18n( (int) $t[0] ) ); ?></span><span class="fc-tile-l"><?php echo esc_html( $t[1] ); ?></span></div>
			<?php endforeach; ?>
		</div>

		<div class="fc-card">
			<h2><?php esc_html_e( 'When the service cannot be reached', 'formconfirmed' ); ?></h2>
			<p>
			<?php
			if ( Formconfirmed_Backup::delegated() ) {
				esc_html_e( 'Backup check ready: the protection layer of the formexposed plugin checks every form on this site. Messages that pass it are delivered; everything else is held for review or refused. Nothing is let through unseen.', 'formconfirmed' );
			} elseif ( Formconfirmed_Backup::own() ) {
				esc_html_e( 'Backup check ready: contact messages and comments that pass an independent check on this site (trap field, fill time, interaction, proof of work, rate limit) are delivered. Everything else is held for review or refused. Nothing is let through unseen.', 'formconfirmed' );
			} else {
				esc_html_e( 'Backup check off: every message is held for review or refused while the service cannot give a clear answer.', 'formconfirmed' );
			}
			?>
			<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>"><?php esc_html_e( 'Change', 'formconfirmed' ); ?></a></p>
		</div>
		<?php endif; ?>
		<?php
	}

	/** Protected forms and places, for the overview: list of labels. */
	private static function protected_list() {
		$out    = array();
		$labels = Formconfirmed_Core::labels();
		foreach ( Formconfirmed_Core::active_adapters() as $a ) {
			if ( in_array( $a, Formconfirmed_Core::PER_FORM, true ) ) {
				foreach ( Formconfirmed_Core::forms_of( $a ) as $id => $title ) {
					if ( Formconfirmed_Core::form_enabled( $a, $id ) ) {
						$out[] = $labels[ $a ] . ': ' . $title;
					}
				}
			} else {
				$out[] = $labels[ $a ];
			}
		}
		return $out;
	}

	/** Where a form is edited in its own plugin. */
	private static function edit_url( $adapter, $id ) {
		$id = absint( $id );
		switch ( $adapter ) {
			case 'cf7':
				return admin_url( 'admin.php?page=wpcf7&post=' . $id . '&action=edit' );
			case 'wpforms':
				return admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . $id );
			case 'gravityforms':
				return admin_url( 'admin.php?page=gf_edit_forms&id=' . $id );
			case 'fluentform':
				return admin_url( 'admin.php?page=fluent_forms&route=editor&form_id=' . $id );
			case 'formidable':
				return admin_url( 'admin.php?page=formidable&frm_action=edit&id=' . $id );
		}
		return '';
	}

	/** A switch: a real checkbox with its label, styled as a toggle. */
	private static function toggle( $name, $checked, $label, $id ) {
		?>
		<label class="fc-switch" for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
			<span class="fc-switch-ui" aria-hidden="true"></span>
			<span class="fc-switch-l"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	private static function page_forms() {
		$o      = Formconfirmed_Core::opt();
		$labels = Formconfirmed_Core::labels();
		$notes  = array(
			'elementor'    => __( 'Every Elementor Pro form on the site.', 'formconfirmed' ),
			'comments'     => __( 'The comment form of your theme. Logged-in moderators are never checked.', 'formconfirmed' ),
			'register'     => __( 'wp-login.php?action=register. During an outage of the service a registration is refused and the visitor can try again.', 'formconfirmed' ),
			'woo_register' => __( 'The registration on the WooCommerce "My account" page (not the account creation in the checkout).', 'formconfirmed' ),
			'login'        => __( 'Off by default. Only "block" stops a login with the correct password; when the service gives no clear answer, the password decides.', 'formconfirmed' ),
			'gravityforms' => __( 'Messages that are not passed are filed under Entries -> Spam.', 'formconfirmed' ),
		);
		?>
		<p class="fc-lead"><?php esc_html_e( 'Choose where formconfirmed checks submissions. Switch a form plugin on or off as a whole, or single forms inside it.', 'formconfirmed' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'formconfirmed_save_forms' ); ?>
			<input type="hidden" name="action" value="formconfirmed_save_forms">
			<div class="fc-card fc-card-tight">
				<?php self::toggle( 'new_forms', $o['new_forms'], __( 'Protect new forms automatically', 'formconfirmed' ), 'fc-new-forms' ); ?>
				<p class="description"><?php esc_html_e( 'Applies to forms you create later in Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Formidable.', 'formconfirmed' ); ?></p>
			</div>
			<?php foreach ( Formconfirmed_Core::ADAPTERS as $a ) : ?>
				<?php
				$avail = Formconfirmed_Core::available( $a );
				$forms = in_array( $a, Formconfirmed_Core::PER_FORM, true ) ? Formconfirmed_Core::forms_of( $a ) : array();
				?>
				<section class="fc-card fc-place<?php echo $avail ? '' : ' fc-place-off'; ?>" aria-labelledby="fc-h-<?php echo esc_attr( $a ); ?>">
					<div class="fc-place-head">
						<h2 id="fc-h-<?php echo esc_attr( $a ); ?>"><?php echo esc_html( $labels[ $a ] ); ?></h2>
						<?php if ( $avail ) : ?>
							<?php self::toggle( 'adapters[' . $a . ']', in_array( $a, $o['adapters'], true ), __( 'Protect', 'formconfirmed' ), 'fc-a-' . $a ); ?>
						<?php else : ?>
							<span class="fc-muted"><?php echo 'register' === $a ? esc_html__( 'Registration is closed on this site (Settings -> General).', 'formconfirmed' ) : esc_html__( 'Not installed', 'formconfirmed' ); ?></span>
							<?php if ( in_array( $a, $o['adapters'], true ) ) : ?>
								<input type="hidden" name="adapters[<?php echo esc_attr( $a ); ?>]" value="1">
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<?php if ( $avail && isset( $notes[ $a ] ) ) : ?>
						<p class="description"><?php echo esc_html( $notes[ $a ] ); ?></p>
					<?php endif; ?>
					<?php if ( $avail && in_array( $a, Formconfirmed_Core::PER_FORM, true ) ) : ?>
						<?php if ( ! $forms ) : ?>
							<p class="fc-muted"><?php esc_html_e( 'No forms yet.', 'formconfirmed' ); ?></p>
						<?php else : ?>
							<table class="widefat striped fc-forms">
								<thead><tr><th scope="col"><?php esc_html_e( 'Protected', 'formconfirmed' ); ?></th><th scope="col"><?php esc_html_e( 'Form', 'formconfirmed' ); ?></th><th scope="col"><?php esc_html_e( 'ID', 'formconfirmed' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $forms as $id => $title ) : ?>
									<tr>
										<td>
											<input type="hidden" name="seen[<?php echo esc_attr( $a ); ?>][]" value="<?php echo esc_attr( $id ); ?>">
											<?php self::toggle( 'forms[' . $a . '][' . $id . ']', Formconfirmed_Core::form_enabled( $a, $id ), '', 'fc-f-' . $a . '-' . $id ); ?>
										</td>
										<td><label for="<?php echo esc_attr( 'fc-f-' . $a . '-' . $id ); ?>"><?php echo esc_html( $title ); ?></label>
											<?php $edit = self::edit_url( $a, $id ); ?>
											<?php if ( $edit ) : ?>
												<a class="fc-edit" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'formconfirmed' ); ?><span class="screen-reader-text"> <?php echo esc_html( $title ); ?></span></a>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( $id ); ?></code></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
			<div class="fc-card fc-card-tight">
				<h2><?php esc_html_e( 'Forms of your own', 'formconfirmed' ); ?></h2>
				<p><?php esc_html_e( 'Theme forms or forms of other plugins: put the shortcode [formconfirmed] into the form and check the submission with formconfirmed_check().', 'formconfirmed' ); ?>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'developers' ) ) ); ?>"><?php esc_html_e( 'How it works', 'formconfirmed' ); ?></a></p>
			</div>
			<?php submit_button( __( 'Save forms', 'formconfirmed' ) ); ?>
		</form>
		<?php
	}

	private static function page_settings() {
		$o = Formconfirmed_Core::opt();
		?>
		<?php Formconfirmed_Connect::panel(); ?>
		<?php if ( self::formexposed_linked() ) : ?>
		<div class="fc-card fc-card-tight">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'formconfirmed_connect' ); ?>
				<input type="hidden" name="action" value="formconfirmed_connect">
				<p><button class="button button-primary"><?php esc_html_e( 'Get keys from my account', 'formconfirmed' ); ?></button>
				<span class="description"><?php esc_html_e( 'This site is connected to your account through the formexposed plugin: one click creates the protected site (or a new secret key for it) and fills in the keys.', 'formconfirmed' ); ?></span></p>
			</form>
		</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'formconfirmed_save' ); ?>
			<input type="hidden" name="action" value="formconfirmed_save">
			<div class="fc-card">
				<h2><?php esc_html_e( 'Keys', 'formconfirmed' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Both keys are shown in your account under Protection.', 'formconfirmed' ); ?>
					<a href="<?php echo esc_url( FORMCONFIRMED_APP . ( Formconfirmed_Core::on() ? '/protection' : '/register' ) ); ?>" target="_blank" rel="noopener"><?php echo Formconfirmed_Core::on() ? esc_html__( 'Open your account', 'formconfirmed' ) : esc_html__( 'Create a free account', 'formconfirmed' ); ?></a>
				</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="fc-site"><?php esc_html_e( 'Site key', 'formconfirmed' ); ?></label></th>
						<td><input id="fc-site" name="site_key" class="regular-text code" value="<?php echo esc_attr( $o['site_key'] ); ?>" autocomplete="off" spellcheck="false" placeholder="fcp_pub_...">
						<p class="description"><?php esc_html_e( 'Public, printed in the form.', 'formconfirmed' ); ?></p></td></tr>
					<tr><th scope="row"><label for="fc-secret"><?php esc_html_e( 'Secret key', 'formconfirmed' ); ?></label></th>
						<td><input id="fc-secret" name="secret" type="password" class="regular-text code" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $o['secret'] ? esc_attr__( 'saved - leave empty to keep', 'formconfirmed' ) : 'fcp_sec_...'; ?>">
						<?php if ( $o['secret'] ) : ?>
							<label class="fc-block"><input type="checkbox" name="remove_keys" value="1"> <?php esc_html_e( 'Remove both keys (switches the protection off)', 'formconfirmed' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Only this server uses it, when it asks the service about a submission. It is never printed on your pages.', 'formconfirmed' ); ?></p></td></tr>
				</table>
			</div>
			<div class="fc-card">
				<h2><?php esc_html_e( 'Checks', 'formconfirmed' ); ?></h2>
				<div class="fc-row">
					<?php self::toggle( 'content', $o['content'], __( 'Check the message text', 'formconfirmed' ), 'fc-content' ); ?>
					<p class="description"><?php esc_html_e( 'Sends the message text along: spam filter in every plan, AI check where your plan includes it. Processed briefly on the service servers. AI checks use a temporary internal queue.', 'formconfirmed' ); ?></p>
				</div>
				<div class="fc-row">
					<?php self::toggle( 'backup', $o['backup'], __( 'Backup check on this site', 'formconfirmed' ), 'fc-backup' ); ?>
					<p class="description"><?php esc_html_e( 'An independent check on your own server, so contact messages and comments are still delivered when the service cannot give a clear answer (outage, network filter). It adds a small proof of work from this site to protected forms.', 'formconfirmed' ); ?>
					<?php if ( Formconfirmed_Backup::delegated() ) : ?>
						<?php esc_html_e( 'Not needed here: the protection layer of the formexposed plugin already does this on every form.', 'formconfirmed' ); ?>
					<?php endif; ?>
					</p>
				</div>
			</div>
			<div class="fc-card">
				<h2><?php esc_html_e( 'Not a clear pass', 'formconfirmed' ); ?></h2>
				<fieldset>
					<legend class="description"><?php esc_html_e( 'When the service flags a message as suspicious, or gives no clear answer and the backup check did not pass:', 'formconfirmed' ); ?></legend>
					<label class="fc-choice"><input type="radio" name="uncertain" value="hold" <?php checked( 'hold', $o['uncertain'] ); ?>>
						<span><strong><?php esc_html_e( 'Hold for review', 'formconfirmed' ); ?></strong> <?php esc_html_e( '(recommended) - the message waits 14 days under Held messages and is delivered with one click; comments wait for moderation. Contact Form 7 and WPForms show the visitor the normal confirmation; Elementor, Fluent Forms and Formidable cannot hold a message back, there the visitor sees an error.', 'formconfirmed' ); ?></span></label>
					<label class="fc-choice"><input type="radio" name="uncertain" value="reject" <?php checked( 'reject', $o['uncertain'] ); ?>>
						<span><strong><?php esc_html_e( 'Refuse', 'formconfirmed' ); ?></strong> <?php esc_html_e( '- the visitor sees an error and can try again; comments go to spam. A copy still waits under Held messages.', 'formconfirmed' ); ?></span></label>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Never: let it through unseen. Registrations are always refused in that case; logins are decided by the password.', 'formconfirmed' ); ?></p>
			</div>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function page_held() {
		if ( Formconfirmed_Held::external() ) {
			echo '<div class="fc-card"><p>' . esc_html__( 'The formexposed plugin is active: held messages wait in its quarantine (Settings -> formexposed -> Quarantine), together with the messages its protection layer stopped.', 'formconfirmed' ) . '</p></div>';
			return;
		}
		$rows = Formconfirmed_Held::entries( 100 );
		/* translators: 1: number of days, 2: e-mail address */
		echo '<p class="fc-lead">' . esc_html( sprintf( __( 'Messages that were not passed wait here for %1$d days. "Deliver" sends one to %2$s.', 'formconfirmed' ), Formconfirmed_Held::DAYS, Formconfirmed_Held::recipient() ) ) . '</p>';
		if ( ! $rows ) {
			echo '<div class="fc-card fc-empty"><p>' . esc_html__( 'No held messages.', 'formconfirmed' ) . '</p></div>';
			return;
		}
		?>
		<table class="widefat striped fc-held">
			<thead><tr><th scope="col"><?php esc_html_e( 'Received', 'formconfirmed' ); ?></th><th scope="col"><?php esc_html_e( 'Form', 'formconfirmed' ); ?></th><th scope="col"><?php esc_html_e( 'Why', 'formconfirmed' ); ?></th><th scope="col"><?php esc_html_e( 'Message', 'formconfirmed' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'formconfirmed' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $r['date'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td><?php echo esc_html( $r['form'] ); ?></td>
					<td><?php echo esc_html( Formconfirmed_Held::reason_text( $r['reason'] ) ); ?></td>
					<td>
						<?php foreach ( $r['pairs'] as $pair ) : ?>
							<div><strong><?php echo esc_html( (string) $pair[0] ); ?>:</strong> <?php echo esc_html( wp_trim_words( (string) $pair[1], 40 ) ); ?></div>
						<?php endforeach; ?>
					</td>
					<td class="fc-nowrap">
						<?php if ( $r['released'] ) : ?>
							<span class="fc-badge fc-badge-on"><?php esc_html_e( 'Delivered', 'formconfirmed' ); ?></span>
						<?php else : ?>
							<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formconfirmed_release&id=' . $r['id'] ), 'formconfirmed_release' ) ); ?>"><?php esc_html_e( 'Deliver', 'formconfirmed' ); ?></a>
						<?php endif; ?>
						<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=formconfirmed_delete&id=' . $r['id'] ), 'formconfirmed_delete' ) ); ?>"><?php esc_html_e( 'Delete', 'formconfirmed' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function page_developers() {
		$php = "<?php\n"
			. "// In the form (inside <form>):\n"
			. "echo formconfirmed_widget( 'contact' );   // same as the shortcode [formconfirmed]\n\n"
			. "// In the handler of the form:\n"
			. "\$r = formconfirmed_check( array(\n"
			. "    'purpose' => 'contact',\n"
			. "    'content' => \$message,            // optional, for the content check\n"
			. "    'email'   => \$email,              // optional\n"
			. "    'form'    => 'Callback form',      // optional: keep a copy when not passed ...\n"
			. "    'fields'  => array( array( 'Name', \$name ), array( 'Message', \$message ) ), // ... with these fields\n"
			. ") );\n"
			. "if ( \$r['pass'] ) {\n"
			. "    // send or store the message\n"
			. "} elseif ( 'hold' === \$r['decision'] ) {\n"
			. "    // not sure: keep it for review (a copy is under Held messages when 'form' and 'fields' were given)\n"
			. "} else {\n"
			. "    // refused: show \$r['message'] to the visitor\n"
			. '}';
		?>
		<p class="fc-lead"><?php esc_html_e( 'Protect forms that no supported form plugin renders: theme templates, landing pages, custom plugins.', 'formconfirmed' ); ?></p>
		<div class="fc-card">
			<h2><?php esc_html_e( 'Shortcode', 'formconfirmed' ); ?></h2>
			<p><?php esc_html_e( 'Inside the form, where the badge should appear:', 'formconfirmed' ); ?></p>
			<pre class="fc-code"><code>[formconfirmed]
[formconfirmed purpose="newsletter"]</code></pre>
			<p class="description"><?php esc_html_e( 'purpose binds the token to what the form is for (lower case letters, digits, - and _; default contact). Use the same purpose in formconfirmed_check(). The shortcode prints nothing while the keys are missing.', 'formconfirmed' ); ?></p>
		</div>
		<div class="fc-card">
			<h2><?php esc_html_e( 'PHP', 'formconfirmed' ); ?></h2>
			<pre class="fc-code"><code><?php echo esc_html( $php ); ?></code></pre>
			<p class="description"><?php esc_html_e( 'formconfirmed_check() follows the same rules as the built-in forms: a clear "allow" passes, "block" is refused, "flag" and no clear answer are held - unless the backup check on this site passed (contact and comment forms).', 'formconfirmed' ); ?></p>
		</div>
		<div class="fc-card">
			<h2>Contact Form 7</h2>
			<p><?php esc_html_e( 'The widget is added at the end of every protected form. To place it yourself, put the form tag into the form:', 'formconfirmed' ); ?></p>
			<pre class="fc-code"><code>[formconfirmed]</code></pre>
		</div>
		<div class="fc-card">
			<h2><?php esc_html_e( 'Filters and constants', 'formconfirmed' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><td><code>formconfirmed_client_ip</code></td><td><?php esc_html_e( 'The visitor\'s IP address, for sites behind a proxy or CDN.', 'formconfirmed' ); ?></td></tr>
					<tr><td><code>formconfirmed_held_recipient</code></td><td><?php esc_html_e( 'Where delivered held messages go (default: the site\'s e-mail address).', 'formconfirmed' ); ?></td></tr>
					<tr><td><code>formconfirmed_pow_max</code></td><td><?php esc_html_e( 'Difficulty of the proof of work of the backup check (default 50,000).', 'formconfirmed' ); ?></td></tr>
					<tr><td><code>FORMCONFIRMED_API</code></td><td><?php esc_html_e( 'wp-config.php: another instance of the service, for staging.', 'formconfirmed' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ------------------------------------------------------------------ actions

	public static function action_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_save' );
		$o        = Formconfirmed_Core::opt();
		$site_key = isset( $_POST['site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) : '';
		$secret   = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
		$o['site_key'] = preg_replace( '/[^a-z0-9_]/', '', strtolower( $site_key ) );
		if ( '' !== $secret ) {
			$o['secret'] = $secret;
		}
		if ( ! empty( $_POST['remove_keys'] ) ) {
			$o['site_key'] = '';
			$o['secret']   = '';
		}
		$o['content']   = ! empty( $_POST['content'] );
		$o['backup']    = ! empty( $_POST['backup'] );
		$uncertain      = isset( $_POST['uncertain'] ) ? sanitize_key( wp_unslash( $_POST['uncertain'] ) ) : 'hold';
		$o['uncertain'] = 'reject' === $uncertain ? 'reject' : 'hold';
		Formconfirmed_Core::save_opt( $o );
		$warn = '' !== $o['site_key'] && ! preg_match( '/^[a-z]{2,8}_pub_[a-f0-9]{24}$/', $o['site_key'] );
		set_transient( 'formconfirmed_msg', $warn ? array( 'warning', __( 'Saved. The site key does not look like a key of the service - please check it.', 'formconfirmed' ) ) : array( 'ok', __( 'Saved. If a page cache or CDN serves your pages, clear it so the forms show the change.', 'formconfirmed' ) ), 120 );
		wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	/** Forms tab: form plugins on/off, single forms on/off, new forms. */
	public static function action_save_forms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_save_forms' );
		$o        = Formconfirmed_Core::opt();
		$adapters = isset( $_POST['adapters'] ) && is_array( $_POST['adapters'] ) ? array_map( 'sanitize_key', array_keys( map_deep( wp_unslash( $_POST['adapters'] ), 'sanitize_key' ) ) ) : array();
		$o['adapters']  = array_values( array_intersect( Formconfirmed_Core::ADAPTERS, $adapters ) );
		$o['new_forms'] = ! empty( $_POST['new_forms'] );
		$seen           = isset( $_POST['seen'] ) && is_array( $_POST['seen'] ) ? map_deep( wp_unslash( $_POST['seen'] ), 'absint' ) : array();
		$on             = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? map_deep( wp_unslash( $_POST['forms'] ), 'absint' ) : array();
		foreach ( Formconfirmed_Core::PER_FORM as $a ) {
			if ( empty( $seen[ $a ] ) || ! is_array( $seen[ $a ] ) ) {
				continue;   // not shown (plugin not installed): keep what was saved
			}
			foreach ( $seen[ $a ] as $id ) {
				$id = (string) absint( $id );
				if ( '0' === $id ) {
					continue;
				}
				$o['forms'][ $a ][ $id ] = ! empty( $on[ $a ][ $id ] );
			}
		}
		Formconfirmed_Core::save_opt( $o );
		set_transient( 'formconfirmed_msg', array( 'ok', __( 'Saved. If a page cache or CDN serves your pages, clear it so the forms show the change.', 'formconfirmed' ) ), 120 );
		wp_safe_redirect( self::url( array( 'tab' => 'forms' ) ) );
		exit;
	}

	/** "Test the connection": the health endpoint, the secret key (an empty response is refused, the code tells why), the backup check. */
	public static function action_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_test' );
		$h = wp_remote_get( FORMCONFIRMED_API . '/health', array( 'timeout' => Formconfirmed_Core::TIMEOUT ) );
		if ( is_wp_error( $h ) || 200 !== (int) wp_remote_retrieve_response_code( $h ) ) {
			/* translators: %s: error message or HTTP status */
			$msg = array( 'error', sprintf( __( 'The service did not answer (%s). Until it does, messages are delivered only if the backup check passed; otherwise they are held or refused.', 'formconfirmed' ), is_wp_error( $h ) ? $h->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $h ) ) );
		} else {
			$r     = wp_remote_post( FORMCONFIRMED_API . '/verify', array(
				'timeout' => Formconfirmed_Core::TIMEOUT,
				'body'    => array( 'secret' => Formconfirmed_Core::opt()['secret'], 'response' => '' ),
			) );
			$j     = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
			$codes = is_array( $j ) && isset( $j['error-codes'] ) ? array_filter( (array) $j['error-codes'], 'is_string' ) : array();
			if ( in_array( 'missing-input-response', $codes, true ) ) {
				$o          = Formconfirmed_Core::opt();
				$o['ok_at'] = time();
				Formconfirmed_Core::save_opt( $o );
				$msg = array( 'ok', __( 'Connected: the service answers and accepts the secret key.', 'formconfirmed' ) );
			} elseif ( $codes ) {
				$msg = array( 'error', self::problem_text( implode( ',', $codes ) ) );
			} else {
				$msg = array( 'error', __( 'The service gave an answer this plugin does not understand.', 'formconfirmed' ) );
			}
		}
		if ( Formconfirmed_Backup::own() ) {
			$t = Formconfirmed_Backup::self_test();
			if ( is_wp_error( $t ) ) {
				$msg = array( 'ok' === $msg[0] ? 'warning' : 'error', $msg[1] . ' ' . $t->get_error_message() );
			} else {
				$msg[1] .= ' ' . __( 'Backup check: self-test passed.', 'formconfirmed' );
			}
		}
		set_transient( 'formconfirmed_msg', $msg, 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Keys from the account the formexposed plugin connected this site to (POST /api/plugin/protect). */
	public static function action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( 'formconfirmed_connect' );
		if ( ! self::formexposed_linked() ) {
			wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
			exit;
		}
		$r = wp_remote_post( FORMEXPOSED_API . '/api/plugin/protect', array(
			'timeout' => 15,
			'body'    => array(
				'site'   => Formexposed_Core::host(),
				'key'    => Formexposed_Core::key(),
				'secret' => Formexposed_Core::secret(),
			),
		) );
		$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( is_array( $j ) && 'success' === ( isset( $j['status'] ) ? $j['status'] : '' ) && ! empty( $j['site_key'] ) && ! empty( $j['secret'] ) ) {
			$o             = Formconfirmed_Core::opt();
			$o['site_key'] = preg_replace( '/[^a-z0-9_]/', '', strtolower( sanitize_text_field( (string) $j['site_key'] ) ) );
			$o['secret']   = sanitize_text_field( (string) $j['secret'] );
			Formconfirmed_Core::save_opt( $o );
			set_transient( 'formconfirmed_msg', array( 'ok', __( 'Connected. The forms are protected now.', 'formconfirmed' ) ), 120 );
		} else {
			$m = is_array( $j ) && ! empty( $j['message'] ) ? sanitize_text_field( (string) $j['message'] ) : ( is_wp_error( $r ) ? $r->get_error_message() : __( 'The service did not answer.', 'formconfirmed' ) );
			set_transient( 'formconfirmed_msg', array( 'error', $m ), 120 );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	/** Paragraph for the privacy policy guide (Settings -> Privacy). */
	public static function privacy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = __( 'Our forms are protected by formconfirmed, a service of LW IT Solutions with servers in the EU. On pages with a protected form your browser loads a script of the service and solves a small calculation task; no cookies are set and nothing is stored on your device. When you send the form, this website sends the result, your IP address, the e-mail address entered in the form and - if enabled - the message text to the service, which checks them for spam and returns a decision. The message text is processed briefly on the service servers. AI checks use a temporary internal queue. The IP address is only kept shortened and hashed. If the check cannot be completed, your message is delivered only after an independent check on this website; otherwise it is held for review or refused, not delivered unchecked. Messages that are not passed are kept on this website for 14 days so they can be reviewed.', 'formconfirmed' );
		wp_add_privacy_policy_content( 'formconfirmed', wpautop( esc_html( $text ) ) );
	}
}
