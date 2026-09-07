<?php

namespace TopJewelleryDiamondBuilder\Product;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whether a product qualifies for the diamond builder is driven by
 * admin-chosen product categories/tags (tjdb_eligible_categories /
 * tjdb_eligible_tags), not a per-product flag. Categories/tags are how
 * customers browse to the product in the first place (plain WooCommerce
 * archives); this class is the single place that turns that membership
 * into a yes/no.
 */
class Eligibility
{
    public function is_eligible(\WC_Product $product): bool
    {
        $categories = get_option('tjdb_eligible_categories', []);
        $tags = get_option('tjdb_eligible_tags', []);

        if (empty($categories) && empty($tags)) {
            return false;
        }

        if (! empty($categories) && has_term(array_map('intval', $categories), 'product_cat', $product->get_id())) {
            return true;
        }

        if (! empty($tags) && has_term(array_map('intval', $tags), 'product_tag', $product->get_id())) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed> WP_Query tax_query fragment matching eligible products.
     */
    public function get_tax_query(): array
    {
        $categories = get_option('tjdb_eligible_categories', []);
        $tags = get_option('tjdb_eligible_tags', []);

        $clauses = ['relation' => 'OR'];

        if (! empty($categories)) {
            $clauses[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_map('intval', $categories),
            ];
        }

        if (! empty($tags)) {
            $clauses[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => array_map('intval', $tags),
            ];
        }

        return count($clauses) > 1 ? $clauses : [];
    }
}
