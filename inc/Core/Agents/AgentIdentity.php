<?php
/**
 * Agent identity value object.
 *
 * @package DataMachine\Core\Agents
 */

namespace DataMachine\Core\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical resolved agent identity.
 */
class AgentIdentity {

	/**
	 * Internal database row ID.
	 *
	 * @var int
	 */
	public int $agent_id;

	/**
	 * Public agent slug.
	 *
	 * @var string
	 */
	public string $agent_slug;

	/**
	 * Owner WordPress user ID.
	 *
	 * @var int
	 */
	public int $owner_id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public string $agent_name;

	/**
	 * Constructor.
	 *
	 * @param int    $agent_id   Internal database row ID.
	 * @param string $agent_slug Public agent slug.
	 * @param int    $owner_id   Owner WordPress user ID.
	 * @param string $agent_name Display name.
	 */
	public function __construct( int $agent_id, string $agent_slug, int $owner_id, string $agent_name ) {
		$this->agent_id   = $agent_id;
		$this->agent_slug = $agent_slug;
		$this->owner_id   = $owner_id;
		$this->agent_name = $agent_name;
	}

	/**
	 * Build an identity from an agents repository row.
	 *
	 * @param array $row Agent repository row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$agent_id   = (int) ( $row['agent_id'] ?? 0 );
		$agent_slug = (string) ( $row['agent_slug'] ?? '' );
		$owner_id   = (int) ( $row['owner_id'] ?? 0 );
		$agent_name = (string) ( $row['agent_name'] ?? '' );

		if ( $agent_id <= 0 || '' === $agent_slug || $owner_id <= 0 ) {
			throw new \InvalidArgumentException( 'Agent row is missing required identity fields.' );
		}

		return new self( $agent_id, $agent_slug, $owner_id, $agent_name );
	}

	/**
	 * Convert to an array for contexts that still pass associative payloads.
	 *
	 * @return array{agent_id:int, agent_slug:string, owner_id:int, agent_name:string}
	 */
	public function to_array(): array {
		return array(
			'agent_id'   => $this->agent_id,
			'agent_slug' => $this->agent_slug,
			'owner_id'   => $this->owner_id,
			'agent_name' => $this->agent_name,
		);
	}
}
