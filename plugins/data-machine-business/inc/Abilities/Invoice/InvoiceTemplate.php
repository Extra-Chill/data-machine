<?php
/**
 * Email-safe invoice template. Context is normalized by CreateInvoiceAbility.
 *
 * @package DataMachineBusiness
 */

// phpcs:disable WordPress.Files.FileName -- PSR-4 autoloading matches the plugin's class layout.

namespace DataMachineBusiness\Abilities\Invoice;

use DataMachine\Abilities\Media\BrandTokens;

defined( 'ABSPATH' ) || exit;

/** Renders the invoice email without external CSS or assets beyond the logo. */
class InvoiceTemplate {

	/**
	 * Register the template without disturbing other consumers.
	 *
	 * @param array $templates Existing email templates.
	 * @return array
	 */
	public static function register( array $templates ): array {
		$templates['invoice'] = array( self::class, 'render' );
		return $templates;
	}

	/**
	 * Resolve a local brand asset against uploads, content or the WordPress root.
	 * Unmappable private files are never exposed in the email.
	 *
	 * @param array  $tokens Brand tokens.
	 * @param string $key    Token key (logo_path or logo_path_inverse).
	 * @return string Public logo URL, or empty for the site-name fallback.
	 */
	private static function logo_url( array $tokens, string $key ): string {
		$path = $tokens[ $key ] ?? '';
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		$uploads = wp_get_upload_dir();
		$roots   = array(
			array( $uploads['basedir'], $uploads['baseurl'] ),
			array( WP_CONTENT_DIR, content_url() ),
			array( ABSPATH, site_url() ),
		);
		$path    = wp_normalize_path( $path );
		foreach ( $roots as $root ) {
			$prefix = trailingslashit( wp_normalize_path( $root[0] ) );
			if ( str_starts_with( $path, $prefix ) ) {
				$relative = substr( $path, strlen( $prefix ) );
				if ( in_array( '..', explode( '/', $relative ), true ) ) {
					return '';
				}
				return trailingslashit( $root[1] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
			}
		}
		return '';
	}

	/**
	 * Brand colors with neutral fallbacks when Data Machine tokens are unavailable.
	 *
	 * @param array $tokens Brand tokens.
	 * @return array<string,string>
	 */
	private static function colors( array $tokens ): array {
		$defaults = array(
			'header_bg'    => '#000000',
			'text_inverse' => '#ffffff',
			'text_primary' => '#111111',
			'text_muted'   => '#6b7280',
			'surface'      => '#f1f5f9',
			'border'       => '#dddddd',
			'accent'       => '#111111',
		);
		$colors   = array();
		foreach ( $defaults as $key => $fallback ) {
			$value          = $tokens['colors'][ $key ] ?? '';
			$colors[ $key ] = is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', $value ) ? $value : $fallback;
		}
		return $colors;
	}

	/**
	 * Render a party as escaped plain text, preserving address newlines.
	 *
	 * @param array $party Party fields.
	 * @return string Escaped HTML.
	 */
	private static function party( array $party ): string {
		$lines = array();
		foreach ( array( 'name', 'attn', 'email', 'address', 'url', 'note' ) as $field ) {
			if ( ! empty( $party[ $field ] ) ) {
				$lines[] = nl2br( esc_html( $party[ $field ] ) );
			}
		}
		return implode( '<br>', $lines );
	}

	/**
	 * Render the normalized invoice context.
	 *
	 * @param array $context Computed invoice values, never raw totals from callers.
	 * @return string HTML email body.
	 */
	public static function render( array $context ): string {
		$tokens = class_exists( BrandTokens::class ) ? BrandTokens::get() : array();
		$c      = self::colors( $tokens );
		$logo   = self::logo_url( $tokens, 'logo_path_inverse' );
		if ( '' === $logo ) {
			$logo = self::logo_url( $tokens, 'logo_path' );
		}
		$label = 'font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:' . $c['text_muted'] . ';';
		$sym   = array(
			'USD' => '$',
			'CAD' => '$',
			'AUD' => '$',
			'EUR' => '€',
			'GBP' => '£',
		)[ $context['currency'] ?? '' ] ?? '';
		$cell  = 'padding:12px 0;border-bottom:1px solid ' . $c['border'] . ';';
		ob_start();
		?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( 'Invoice ' . $context['invoice_number'] ); ?></title></head>
<body style="margin:0;padding:24px 12px;background:<?php echo esc_attr( $c['surface'] ); ?>;color:<?php echo esc_attr( $c['text_primary'] ); ?>;font-family:Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;border-radius:10px;overflow:hidden;">
<tr><td style="background:<?php echo esc_attr( $c['header_bg'] ); ?>;padding:14px 28px;border-bottom:4px solid <?php echo esc_attr( $c['accent'] ); ?>;">
		<?php if ( $logo ) : ?>
<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" width="64" style="display:block;width:64px;max-width:64px;height:auto;border:0;">
		<?php else : ?>
<strong style="font-size:24px;color:<?php echo esc_attr( $c['text_inverse'] ); ?>;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
		<?php endif; ?>
</td></tr>
<tr><td style="padding:28px 28px 8px;">
<div style="<?php echo esc_attr( $label ); ?>">Invoice</div>
<div style="font-size:26px;font-weight:bold;margin:4px 0 6px;"><?php echo esc_html( $context['invoice_number'] ); ?></div>
<div style="font-size:14px;color:<?php echo esc_attr( $c['text_muted'] ); ?>;">Issued <?php echo esc_html( $context['issued_date'] ); ?><?php echo ! empty( $context['due'] ) ? ' &middot; ' . esc_html( $context['due'] ) : ''; ?></div>
</td></tr>
<tr><td style="padding:16px 28px 8px;">
		<?php
		foreach ( array(
			'bill_to' => 'Bill to',
			'pay_to'  => 'Pay to',
			'from'    => 'From',
		) as $key => $heading ) :
			if ( empty( $context[ $key ] ) ) {
				continue;
			}
			?>
<div style="display:inline-block;vertical-align:top;width:170px;margin:0 8px 16px 0;font-size:14px;line-height:1.5;"><div style="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $heading ); ?></div>
			<?php echo self::party( $context[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in party(). ?>
</div>
		<?php endforeach; ?>
</td></tr>
<tr><td style="padding:0 28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
<tr><td style="<?php echo esc_attr( $label . 'padding-bottom:8px;border-bottom:2px solid ' . $c['text_primary'] . ';' ); ?>">Description</td><td align="right" style="<?php echo esc_attr( $label . 'padding-bottom:8px;border-bottom:2px solid ' . $c['text_primary'] . ';' ); ?>">Amount</td></tr>
		<?php foreach ( $context['line_items'] as $item ) : ?>
<tr><td style="<?php echo esc_attr( $cell ); ?>line-height:1.45;"><strong><?php echo esc_html( $item['description'] ); ?></strong>
			<?php if ( ! empty( $item['detail'] ) ) : ?>
<br><span style="color:<?php echo esc_attr( $c['text_muted'] ); ?>;"><?php echo nl2br( esc_html( $item['detail'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( 1.0 !== (float) $item['quantity'] ) : ?>
<br><span style="color:<?php echo esc_attr( $c['text_muted'] ); ?>;"><?php echo esc_html( $item['quantity'] . ' × ' . $sym . $item['unit_amount'] ); ?></span>
			<?php endif; ?>
</td><td align="right" valign="top" style="<?php echo esc_attr( $cell ); ?>white-space:nowrap;"><?php echo esc_html( $sym . $item['amount'] ); ?></td></tr>
		<?php endforeach; ?>
<tr><td align="right" style="padding:18px 16px 0 0;font-size:14px;">Total due (<?php echo esc_html( $context['currency'] ); ?>)</td><td align="right" style="padding-top:18px;font-size:22px;font-weight:bold;color:<?php echo esc_attr( $c['accent'] ); ?>;white-space:nowrap;"><?php echo esc_html( $sym . $context['total'] ); ?></td></tr>
</table>
</td></tr>
		<?php if ( ! empty( $context['payment_instructions'] ) ) : ?>
<tr><td style="padding:28px 28px 0;">
<div style="background:<?php echo esc_attr( $c['surface'] ); ?>;border-left:4px solid <?php echo esc_attr( $c['accent'] ); ?>;border-radius:6px;padding:14px 16px;font-size:14px;line-height:1.55;"><div style="<?php echo esc_attr( $label ); ?>margin-bottom:4px;">Payment</div><?php echo nl2br( esc_html( $context['payment_instructions'] ) ); ?></div>
</td></tr>
		<?php endif; ?>
		<?php if ( ! empty( $context['notes'] ) ) : ?>
<tr><td style="padding:18px 28px 0;font-size:14px;line-height:1.55;"><?php echo nl2br( esc_html( $context['notes'] ) ); ?></td></tr>
		<?php endif; ?>
<tr><td style="padding:28px;font-size:12px;line-height:1.5;color:<?php echo esc_attr( $c['text_muted'] ); ?>;"><?php echo nl2br( esc_html( $context['contact'] ?? get_option( 'admin_email' ) ) ); ?></td></tr>
</table>
</td></tr></table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
