<?php
/**
 * HTML invoice composition and delivery through Data Machine's email ability.
 *
 * @package DataMachineBusiness
 */

// phpcs:disable WordPress.Files.FileName -- PSR-4 autoloading matches the plugin's class layout.

namespace DataMachineBusiness\Abilities\Invoice;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/** Registers and executes invoice creation. */
class CreateInvoiceAbility {

	/**
	 * Per-site sequence option (does not reset at year boundaries).
	 *
	 * @var string
	 */
	public const COUNTER_OPTION = 'datamachine_invoice_counter';

	/**
	 * Seconds after which a numbering lock is considered abandoned.
	 *
	 * @var int
	 */
	public const LOCK_TTL = 300;

	/**
	 * Registration guard.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Register the template and queue ability registration. */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		add_filter( 'datamachine_email_templates', array( InvoiceTemplate::class, 'register' ) );
		if ( function_exists( 'wp_register_ability' ) ) {
			AbilityRegistration::on_abilities_api_init( array( $this, 'register_abilities' ) );
		}
		self::$registered = true;
	}

	/** Register the public ability contract. */
	public function register_abilities(): void {
		$party = array(
			'type'       => 'object',
			'properties' => array_fill_keys( array( 'name', 'attn', 'email', 'address', 'url', 'note' ), array( 'type' => 'string' ) ),
		);
		$props = array_fill_keys( array( 'due', 'payment_instructions', 'notes', 'contact', 'invoice_number', 'to', 'cc' ), array( 'type' => 'string' ) );
		$props = array_merge(
			$props,
			array(
				'bill_to'     => $party,
				'from'        => $party,
				'pay_to'      => $party,
				'currency'    => array(
					'type'    => 'string',
					'default' => 'USD',
					'pattern' => '^[A-Z]{3}$',
				),
				'issued_date' => array(
					'type'   => 'string',
					'format' => 'date',
				),
				'send'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'line_items'  => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'description', 'unit_amount' ),
						'properties' => array(
							'description' => array(
								'type'      => 'string',
								'minLength' => 1,
							),
							'detail'      => array( 'type' => 'string' ),
							'quantity'    => array(
								'type'             => 'number',
								'minimum'          => 0,
								'exclusiveMinimum' => true,
								'default'          => 1,
							),
							'unit_amount' => array(
								'type'    => 'number',
								'minimum' => 0,
							),
						),
					),
				),
			)
		);
		wp_register_ability(
			'datamachine/create-invoice',
			array(
				'label'               => __( 'Create Invoice', 'data-machine-business' ),
				'description'         => __( 'Preview or send an HTML email invoice. Totals are computed from line items.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'bill_to', 'line_items' ),
					'properties'           => $props,
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	/**
	 * Check whether the caller may create and send invoices.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Compose a preview or delegate delivery. Only successful sends consume numbers.
	 *
	 * The option lock serializes automatic sends across processes. A lock older
	 * than LOCK_TTL seconds (a killed mail process) is treated as stale and taken over.
	 *
	 * @param array $input Invoice fields.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		if ( empty( $input['line_items'] ) || ! is_array( $input['line_items'] ) ) {
			return new \WP_Error( 'invoice_line_items_required', 'At least one line item is required.' );
		}
		if ( empty( $input['bill_to'] ) || ! is_array( $input['bill_to'] ) ) {
			return new \WP_Error( 'invoice_bill_to_required', 'Bill to is required.' );
		}
		$context               = $input;
		$context['line_items'] = array();
		$total_cents           = 0;
		foreach ( $input['line_items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['description'] ) || ! isset( $item['unit_amount'] ) ) {
				return new \WP_Error( 'invoice_invalid_item', 'Each line item needs a description and unit_amount.' );
			}
			$quantity = $item['quantity'] ?? 1;
			$amount   = $item['unit_amount'];
			if ( ! is_numeric( $quantity ) || ! is_numeric( $amount ) || ! is_finite( (float) $quantity ) || ! is_finite( (float) $amount ) || $quantity <= 0 || $amount < 0 ) {
				return new \WP_Error( 'invoice_invalid_amount', 'Amounts must be nonnegative and quantities positive finite numbers.' );
			}
			$cents = round( (float) $quantity * (float) $amount * 100 );
			if ( ! is_finite( $cents ) || $cents > PHP_INT_MAX - $total_cents ) {
				return new \WP_Error( 'invoice_amount_overflow', 'Invoice amount is too large.' );
			}
			$total_cents            += (int) $cents;
			$item['quantity']        = $quantity;
			$item['amount']          = number_format( $cents / 100, 2, '.', ',' );
			$item['unit_amount']     = number_format( (float) $amount, 2, '.', ',' );
			$context['line_items'][] = $item;
		}
		$context['total']       = number_format( $total_cents / 100, 2, '.', ',' );
		$context['currency']    = $input['currency'] ?? 'USD';
		$context['issued_date'] = $input['issued_date'] ?? wp_date( 'Y-m-d' );
		$context['from']        = array_merge(
			array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
			$input['from'] ?? array()
		);
		$send                   = ! empty( $input['send'] );
		$automatic              = empty( $input['invoice_number'] );
		$locked                 = false;
		$mailer                 = null;
		if ( $send ) {
			$mailer = wp_get_ability( 'datamachine/send-email' );
			if ( ! $mailer ) {
				return new \WP_Error( 'invoice_mailer_unavailable', 'The send-email ability is unavailable.' );
			}
			if ( $automatic ) {
				$lock_key = self::COUNTER_OPTION . '_lock';
				$locked   = add_option( $lock_key, time(), '', false );
				if ( ! $locked && time() - (int) get_option( $lock_key, 0 ) > self::LOCK_TTL ) {
					delete_option( $lock_key );
					$locked = add_option( $lock_key, time(), '', false );
				}
				if ( ! $locked ) {
					return new \WP_Error( 'invoice_number_busy', 'Another invoice send holds the numbering lock.' );
				}
			}
		}
		try {
			$next                      = (int) get_option( self::COUNTER_OPTION, 0 ) + 1;
			$context['invoice_number'] = $automatic ? sprintf( 'INV-%s-%04d', wp_date( 'Y' ), $next ) : $input['invoice_number'];
			$subject                   = 'Invoice ' . $context['invoice_number'];
			if ( ! $send ) {
				return array(
					'invoice_number' => $context['invoice_number'],
					'total'          => $context['total'],
					'subject'        => $subject,
					'html'           => InvoiceTemplate::render( $context ),
				);
			}
			$result = $mailer->execute(
				array(
					'template'     => 'invoice',
					'context'      => $context,
					'to'           => $input['to'] ?? ( $input['bill_to']['email'] ?? '' ),
					'cc'           => $input['cc'] ?? '',
					'subject'      => $subject,
					'content_type' => 'text/html',
				)
			);
			if ( $automatic && ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
				update_option( self::COUNTER_OPTION, $next, false );
			}
			return $result;
		} finally {
			if ( $locked ) {
				delete_option( self::COUNTER_OPTION . '_lock' );
			}
		}
	}
}
