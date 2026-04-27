<?php
/**
 * WP-CLI Taxonomy Command
 *
 * Wraps concrete Taxonomy abilities for taxonomy term management.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\DeleteTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\GetTaxonomyTermsAbility;
use DataMachine\Abilities\Taxonomy\ResolveTermAbility;
use DataMachine\Abilities\Taxonomy\UpdateTaxonomyTermAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy term management.
 *
 * @since 0.41.0
 */
class TaxonomyCommand extends BaseCommand {

	/**
	 * List taxonomy terms.
	 *
	 * ## OPTIONS
	 *
	 * --taxonomy=<taxonomy>
	 * : Taxonomy slug (category, post_tag, etc.).
	 *
	 * [--search=<search>]
	 * : Filter terms by name.
	 *
	 * [--parent=<parent>]
	 * : Parent term ID.
	 *
	 * [--number=<number>]
	 * : Maximum number of terms to return.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Number of terms to skip.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--orderby=<orderby>]
	 * : Order by field.
	 * ---
	 * default: name
	 * options:
	 *   - name
	 *   - slug
	 *   - term_id
	 *   - count
	 * ---
	 *
	 * [--order=<order>]
	 * : Sort order.
	 * ---
	 * default: ASC
	 * options:
	 *   - ASC
	 *   - DESC
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine taxonomy list --taxonomy=category
	 *     wp datamachine taxonomy list --taxonomy=post_tag --search=php
	 *     wp datamachine taxonomy list --taxonomy=category --format=json
	 *
	 * @subcommand list
	 */
	public function list_terms( array $args, array $assoc_args ): void {
		$taxonomy = $assoc_args['taxonomy'] ?? '';
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $taxonomy ) ) {
			WP_CLI::error( 'Taxonomy is required (--taxonomy=<slug>).' );
			return;
		}

		$input   = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => (int) ( $assoc_args['number'] ?? 50 ),
			'offset'     => (int) ( $assoc_args['offset'] ?? 0 ),
			'orderby'    => $assoc_args['orderby'] ?? 'name',
			'order'      => $assoc_args['order'] ?? 'ASC',
		);

		if ( ! empty( $assoc_args['search'] ) ) {
			$input['search'] = $assoc_args['search'];
		}
		if ( isset( $assoc_args['parent'] ) ) {
			$input['parent'] = (int) $assoc_args['parent'];
		}

		$result = ( new GetTaxonomyTermsAbility() )->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get taxonomy terms.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$terms = $result['terms'] ?? array();

		if ( empty( $terms ) ) {
			WP_CLI::warning( sprintf( 'No terms found in taxonomy "%s".', $taxonomy ) );
			return;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'term_id' => $term['term_id'] ?? '',
				'name'    => $term['name'] ?? '',
				'slug'    => $term['slug'] ?? '',
				'parent'  => $term['parent'] ?? 0,
				'count'   => $term['count'] ?? 0,
			);
		}

		$fields = array( 'term_id', 'name', 'slug', 'parent', 'count' );
		$this->format_items( $items, $fields, $assoc_args, 'term_id' );

		$total = $result['total'] ?? count( $items );
		$this->output_pagination( $input['offset'], count( $items ), $total, $format, 'terms' );
	}

	/**
	 * Create a taxonomy term.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Term name.
	 *
	 * --taxonomy=<taxonomy>
	 * : Taxonomy slug.
	 *
	 * [--parent=<parent>]
	 * : Parent term ID.
	 *
	 * [--slug=<slug>]
	 * : Term slug.
	 *
	 * [--description=<description>]
	 * : Term description.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine taxonomy create --name="New Category" --taxonomy=category
	 *     wp datamachine taxonomy create --name="Sub Cat" --taxonomy=category --parent=5
	 *
	 * @subcommand create
	 */
	public function create( array $args, array $assoc_args ): void {
		$name     = $assoc_args['name'] ?? '';
		$taxonomy = $assoc_args['taxonomy'] ?? '';
		$format   = $assoc_args['format'] ?? 'table';

		if ( empty( $name ) ) {
			WP_CLI::error( 'Term name is required (--name=<name>).' );
			return;
		}

		if ( empty( $taxonomy ) ) {
			WP_CLI::error( 'Taxonomy is required (--taxonomy=<slug>).' );
			return;
		}

		$input = array(
			'name'     => $name,
			'taxonomy' => $taxonomy,
		);

		if ( isset( $assoc_args['parent'] ) ) {
			$input['parent'] = (int) $assoc_args['parent'];
		}
		if ( isset( $assoc_args['slug'] ) ) {
			$input['slug'] = $assoc_args['slug'];
		}
		if ( isset( $assoc_args['description'] ) ) {
			$input['description'] = $assoc_args['description'];
		}

		$result = ( new CreateTaxonomyTermAbility() )->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to create term.' );
			return;
		}

		WP_CLI::success( sprintf( 'Created term "%s" (ID: %d).', $result['name'] ?? $name, $result['term_id'] ?? 0 ) );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Update a taxonomy term.
	 *
	 * ## OPTIONS
	 *
	 * <term_id>
	 * : Term ID to update.
	 *
	 * [--name=<name>]
	 * : New term name.
	 *
	 * [--slug=<slug>]
	 * : New term slug.
	 *
	 * [--description=<description>]
	 * : New term description.
	 *
	 * [--parent=<parent>]
	 * : New parent term ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine taxonomy update 5 --name="Renamed Category"
	 *     wp datamachine taxonomy update 5 --slug=renamed-cat
	 *
	 * @subcommand update
	 */
	public function update( array $args, array $assoc_args ): void {
		$term_id = (int) ( $args[0] ?? 0 );

		if ( $term_id <= 0 ) {
			WP_CLI::error( 'Valid term ID is required.' );
			return;
		}

		$input = array( 'term_id' => $term_id );

		if ( isset( $assoc_args['name'] ) ) {
			$input['name'] = $assoc_args['name'];
		}
		if ( isset( $assoc_args['slug'] ) ) {
			$input['slug'] = $assoc_args['slug'];
		}
		if ( isset( $assoc_args['description'] ) ) {
			$input['description'] = $assoc_args['description'];
		}
		if ( isset( $assoc_args['parent'] ) ) {
			$input['parent'] = (int) $assoc_args['parent'];
		}

		$result = ( new UpdateTaxonomyTermAbility() )->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to update term.' );
			return;
		}

		WP_CLI::success( sprintf( 'Updated term %d.', $term_id ) );
	}

	/**
	 * Delete a taxonomy term.
	 *
	 * ## OPTIONS
	 *
	 * <term_id>
	 * : Term ID to delete.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine taxonomy delete 5
	 *     wp datamachine taxonomy delete 5 --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$term_id      = (int) ( $args[0] ?? 0 );
		$skip_confirm = isset( $assoc_args['yes'] );

		if ( $term_id <= 0 ) {
			WP_CLI::error( 'Valid term ID is required.' );
			return;
		}

		if ( ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( 'Delete term %d?', $term_id ) );
		}

		$result = ( new DeleteTaxonomyTermAbility() )->execute( array( 'term_id' => $term_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete term.' );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted term %d.', $term_id ) );
	}

	/**
	 * Resolve a term by name, slug, or ID.
	 *
	 * ## OPTIONS
	 *
	 * <identifier>
	 * : Term name, slug, or numeric ID.
	 *
	 * --taxonomy=<taxonomy>
	 * : Taxonomy slug.
	 *
	 * [--create]
	 * : Create the term if not found.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine taxonomy resolve "Uncategorized" --taxonomy=category
	 *     wp datamachine taxonomy resolve "New Tag" --taxonomy=post_tag --create
	 *     wp datamachine taxonomy resolve 42 --taxonomy=category
	 *
	 * @subcommand resolve
	 */
	public function resolve( array $args, array $assoc_args ): void {
		$identifier = $args[0] ?? '';
		$taxonomy   = $assoc_args['taxonomy'] ?? '';
		$create     = isset( $assoc_args['create'] );
		$format     = $assoc_args['format'] ?? 'table';

		if ( empty( $identifier ) ) {
			WP_CLI::error( 'Term identifier is required.' );
			return;
		}

		if ( empty( $taxonomy ) ) {
			WP_CLI::error( 'Taxonomy is required (--taxonomy=<slug>).' );
			return;
		}

		$result = ResolveTermAbility::resolve( $identifier, $taxonomy, $create );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Term not found.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$action = ! empty( $result['created'] ) ? 'Created' : 'Resolved';
		WP_CLI::success( sprintf( '%s term "%s".', $action, $result['name'] ) );
		WP_CLI::log( sprintf( 'Term ID:  %d', $result['term_id'] ) );
		WP_CLI::log( sprintf( 'Name:     %s', $result['name'] ) );
		WP_CLI::log( sprintf( 'Slug:     %s', $result['slug'] ) );
		WP_CLI::log( sprintf( 'Taxonomy: %s', $result['taxonomy'] ) );
	}
}
