<?php
/**
 * Runtime completion decision value.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable completion decision returned by runtime completion policies.
 */
class WP_Agent_Conversation_Completion_Decision {

	/** @var bool Whether the current conversation should stop. */
	private bool $complete;

	/** @var string Optional diagnostic message. */
	private string $message;

	/** @var array<string, mixed> Optional diagnostic context. */
	private array $context;

	/**
	 * @param bool   $complete Whether the current conversation should stop.
	 * @param string $message  Optional diagnostic message.
	 * @param array<string, mixed> $context  Optional diagnostic context.
	 */
	private function __construct( bool $complete, string $message = '', array $context = array() ) {
		$this->complete = $complete;
		$this->message  = $message;
		$this->context  = $context;
	}

	/**
	 * Build a non-completing decision.
	 *
	 * @param string $message Optional diagnostic message.
	 * @param array<string, mixed> $context Optional diagnostic context.
	 * @return self
	 */
	public static function incomplete( string $message = '', array $context = array() ): self {
		return new self( false, $message, $context );
	}

	/**
	 * Build a completing decision.
	 *
	 * @param string $message Optional diagnostic message.
	 * @param array<string, mixed> $context Optional diagnostic context.
	 * @return self
	 */
	public static function complete( string $message = '', array $context = array() ): self {
		return new self( true, $message, $context );
	}

	/** @return bool Whether the current conversation should stop. */
	public function isComplete(): bool {
		return $this->complete;
	}

	/** @return string Optional diagnostic message. */
	public function message(): string {
		return $this->message;
	}

	/** @return array<string, mixed> Optional diagnostic context. */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Return a normalized array representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'complete' => $this->complete,
			'message'  => $this->message,
			'context'  => $this->context,
		);
	}
}
