<?php
/**
 * Shared utility functions for the Mighty Backup plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the current user belongs to an allowed email domain.
 *
 * Used by settings, dev mode, and devcontainer classes to restrict
 * plugin access to authorized team members.
 *
 * @return bool
 */
function mighty_backup_is_authorized_user(): bool {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	$allowed_domains = apply_filters( 'mighty_backup_admin_domains', [ 'builtmighty.com' ] );
	$email           = strtolower( $user->user_email );

	foreach ( $allowed_domains as $domain ) {
		if ( str_ends_with( $email, '@' . strtolower( $domain ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Render a small "?" help icon next to a label that reveals a tooltip on click.
 *
 * Outputs a `<button class="mb-help-icon" data-mb-help="...">` — admin.js
 * handles the popover positioning, click-outside dismissal, and Escape support.
 *
 * @param string $help_text Plain text to render inside the tooltip. Anything HTML
 *                         will be escaped — keep it short and prose-like.
 */
function mighty_backup_help_icon( string $help_text ): void {
	if ( $help_text === '' ) {
		return;
	}
	printf(
		'<button type="button" class="mb-help-icon" aria-label="%s" data-mb-help="%s">?</button>',
		esc_attr__( 'Help', 'mighty-backup' ),
		esc_attr( $help_text )
	);
}
