<?php
/**
 * Taxonomy Abilities
 *
 * Facade that loads and registers all modular Taxonomy ability classes.
 * Maintains backward compatibility by delegating to individual ability instances.
 *
 * @package DataMachine\Abilities
 * @since 0.13.7
 */

namespace DataMachine\Abilities;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Abilities\Taxonomy\GetTaxonomyTermsAbility;
use DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\UpdateTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\DeleteTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\ResolveTermAbility;

defined( 'ABSPATH' ) || exit;

class TaxonomyAbilities {

	private static bool $registered = false;

	private GetTaxonomyTermsAbility $get_taxonomy_terms;
	private CreateTaxonomyTermAbility $create_taxonomy_term;
	private UpdateTaxonomyTermAbility $update_taxonomy_term;
	private DeleteTaxonomyTermAbility $delete_taxonomy_term;
	private ResolveTermAbility $resolve_term;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->resolve_term          = new ResolveTermAbility();
		$this->get_taxonomy_terms    = new GetTaxonomyTermsAbility();
		$this->create_taxonomy_term  = new CreateTaxonomyTermAbility();
		$this->update_taxonomy_term  = new UpdateTaxonomyTermAbility();
		$this->delete_taxonomy_term  = new DeleteTaxonomyTermAbility();

		self::$registered = true;
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute get taxonomy terms ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with taxonomy terms data.
	 */
	public function executeGetTaxonomyTerms( array $input ): array {
		if ( ! isset( $this->get_taxonomy_terms ) ) {
			$this->get_taxonomy_terms = new GetTaxonomyTermsAbility();
		}
		return $this->get_taxonomy_terms->execute( $input );
	}

	/**
	 * Execute create taxonomy term ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created taxonomy term data.
	 */
	public function executeCreateTaxonomyTerm( array $input ): array {
		if ( ! isset( $this->create_taxonomy_term ) ) {
			$this->create_taxonomy_term = new CreateTaxonomyTermAbility();
		}
		return $this->create_taxonomy_term->execute( $input );
	}

	/**
	 * Execute update taxonomy term ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update status.
	 */
	public function executeUpdateTaxonomyTerm( array $input ): array {
		if ( ! isset( $this->update_taxonomy_term ) ) {
			$this->update_taxonomy_term = new UpdateTaxonomyTermAbility();
		}
		return $this->update_taxonomy_term->execute( $input );
	}

	/**
	 * Execute delete taxonomy term ability (backward compatibility).
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function executeDeleteTaxonomyTerm( array $input ): array {
		if ( ! isset( $this->delete_taxonomy_term ) ) {
			$this->delete_taxonomy_term = new DeleteTaxonomyTermAbility();
		}
		return $this->delete_taxonomy_term->execute( $input );
	}
}
