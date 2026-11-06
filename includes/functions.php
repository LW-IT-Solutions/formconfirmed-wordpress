<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * formconfirmed - functions for forms of your own (theme templates, custom plugins).
 *
 *   In the form:     echo formconfirmed_widget( 'contact' );   // or the shortcode [formconfirmed]
 *   In its handler:  $r = formconfirmed_check( array( 'content' => $message, 'email' => $email ) );
 *                    if ( $r['pass'] ) { ...send... } elseif ( 'hold' === $r['decision'] ) { ...keep for review... } else { ...show $r['message']... }
 *
 * Both are safe to call while the keys are missing: the widget prints nothing and the check returns
 * "hold" (never a pass).
 */

/**
 * The widget element for a form of your own. Put it inside the <form>.
 *
 * @param string $purpose What the form is for (contact, comment, register, newsletter ...). The token is bound to it.
 * @return string HTML (already escaped).
 */
function formconfirmed_widget( $purpose = 'contact' ) {
	return Formconfirmed_Core::shortcode( array( 'purpose' => $purpose ) );
}

/**
 * Checks the current submission of a form of your own.
 *
 * @param array $args purpose, content, email, data (default $_POST), form + fields (keep a copy when not passed).
 * @return array pass (bool), decision (pass|hold|reject), action, reasons, message (for the visitor).
 */
function formconfirmed_check( $args = array() ) {
	return Formconfirmed_Core::check( $args );
}
