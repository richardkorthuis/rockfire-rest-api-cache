<?php
/**
 * Resolves the ancestors of terms.
 *
 * @since 1.0.0
 *
 * @package Rockfire_Rest_Api_Cache
 */

namespace Rockfire\Rest_Api_Cache\Invalidation;

defined( 'ABSPATH' ) || exit;

/**
 * Ancestors of hierarchical terms, used because a changed child changes the cached output of its parents (counts, children).
 *
 * @since 1.0.0
 */
final class Term_Ancestry {

	/**
	 * The ancestors of terms, without the terms themselves.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @return int[] De-duplicated ancestor IDs; empty for a flat taxonomy or empty input.
	 *
	 * @phpstan-return list<int>
	 */
	public function ancestors( array $term_ids, string $taxonomy ): array {
		if ( array() === $term_ids || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return array();
		}

		$inputs = array_flip( array_map( 'intval', $term_ids ) );
		$found  = array();
		foreach ( array_keys( $inputs ) as $term_id ) {
			foreach ( get_ancestors( $term_id, $taxonomy, 'taxonomy' ) as $ancestor ) {
				$ancestor = (int) $ancestor;
				if ( ! isset( $inputs[ $ancestor ] ) ) {
					$found[ $ancestor ] = true;
				}
			}
		}
		return array_keys( $found );
	}
}
