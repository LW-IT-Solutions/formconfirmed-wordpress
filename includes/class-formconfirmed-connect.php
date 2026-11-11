<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Server-side verifier and single-use key transfer after the account owner's approval. */
class Formconfirmed_Connect {
	public static function init() {
		add_action( 'admin_post_formconfirmed_begin', array( __CLASS__, 'begin' ) );
		add_action( 'admin_post_formconfirmed_finish', array( __CLASS__, 'finish' ) );
	}

	private static function pending_key() {
		return 'formconfirmed_connect_' . get_current_user_id();
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'formconfirmed' ) );
		}
		check_admin_referer( $action );
	}

	private static function post( $action, $body ) {
		$r = wp_remote_post(
			rtrim( FORMCONFIRMED_API, '/' ) . '/connect/' . $action,
			array( 'timeout' => 10, 'redirection' => 0, 'body' => $body )
		);
		if ( is_wp_error( $r ) || ! in_array( wp_remote_retrieve_response_code( $r ), array( 200, 202 ), true ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		return is_array( $data ) && true === ( isset( $data['ok'] ) ? $data['ok'] : false ) ? $data : null;
	}

	private static function message( $ok, $text ) {
		set_transient( 'formconfirmed_msg', array( $ok ? 'ok' : 'error', $text ), 120 );
		wp_safe_redirect( Formconfirmed_Admin::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	public static function begin() {
		self::guard( 'formconfirmed_begin' );
		$host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$verifier = bin2hex( random_bytes( 32 ) );
		$data     = self::post( 'start', array( 'hostname' => $host, 'challenge' => hash( 'sha256', $verifier ) ) );
		$code     = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
		$url      = rtrim( FORMCONFIRMED_APP, '/' ) . '/wordpress-connect?code=' . $code;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $code ) || ! isset( $data['approve_url'] ) || $data['approve_url'] !== $url ) {
			self::message( false, __( 'The connection could not be started. Your existing keys were kept.', 'formconfirmed' ) );
		}
		set_transient( self::pending_key(), array( 'code' => $code, 'verifier' => $verifier, 'url' => $url, 'host' => $host ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Formconfirmed_Admin::url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	public static function finish() {
		self::guard( 'formconfirmed_finish' );
		$pending = get_transient( self::pending_key() );
		if ( ! is_array( $pending ) || $pending['host'] !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			self::message( false, __( 'The connection expired. Please start again.', 'formconfirmed' ) );
		}
		$data = self::post( 'poll', array( 'code' => $pending['code'], 'verifier' => $pending['verifier'] ) );
		if ( is_array( $data ) && isset( $data['state'] ) && 'pending' === $data['state'] ) {
			self::message( false, __( 'Approve the request in your formconfirmed account first.', 'formconfirmed' ) );
		}
		if ( ! is_array( $data ) || ! isset( $data['state'], $data['site_key'], $data['secret'] ) || 'approved' !== $data['state']
			|| ! is_string( $data['site_key'] ) || ! is_string( $data['secret'] )
			|| ! preg_match( '/^fcp_pub_[a-f0-9]{24}$/D', $data['site_key'] ) || ! preg_match( '/^fcp_sec_[A-Za-z0-9_-]{32}$/D', $data['secret'] ) ) {
			self::message( false, __( 'The connection could not be completed. Your existing keys were kept.', 'formconfirmed' ) );
		}
		$options             = Formconfirmed_Core::opt();
		$options['site_key'] = $data['site_key'];
		$options['secret']   = $data['secret'];
		Formconfirmed_Core::save_opt( $options );
		delete_transient( self::pending_key() );
		self::message( true, __( 'Connected. Test the connection and choose the forms to protect.', 'formconfirmed' ) );
	}

	public static function panel() {
		$pending = get_transient( self::pending_key() );
		?>
		<div class="fc-card fc-card-tight">
			<h2><?php esc_html_e( 'Connect your account', 'formconfirmed' ); ?></h2>
			<?php if ( is_array( $pending ) ) : ?>
				<p><?php esc_html_e( 'Open your account, compare the site and request code, and approve the connection. Then complete it here.', 'formconfirmed' ); ?></p>
				<p><strong><?php echo esc_html( $pending['host'] ); ?></strong> <code><?php echo esc_html( strtoupper( substr( $pending['code'], 0, 8 ) ) ); ?></code></p>
				<p><a class="button" href="<?php echo esc_url( $pending['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Approve in my account', 'formconfirmed' ); ?></a></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'formconfirmed_finish' ); ?>
					<input type="hidden" name="action" value="formconfirmed_finish">
					<button class="button button-primary"><?php esc_html_e( 'Complete connection', 'formconfirmed' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Approve this WordPress site in your formconfirmed account to transfer both keys securely. The request expires after ten minutes.', 'formconfirmed' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fc-block">
				<?php wp_nonce_field( 'formconfirmed_begin' ); ?>
				<input type="hidden" name="action" value="formconfirmed_begin">
				<button class="button"><?php echo esc_html( is_array( $pending ) ? __( 'Start a new request', 'formconfirmed' ) : __( 'Connect with formconfirmed', 'formconfirmed' ) ); ?></button>
			</form>
		</div>
		<?php
	}
}
