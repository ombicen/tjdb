<?php

namespace TopJewelleryDiamondBuilder\Rest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Exposes WooCommerce products flagged as "diamond compatible" — the
 * settings the customer can pick in step 1 of the builder.
 */
class SettingsEndpoint
{
    public function list_settings(\WP_REST_Request $request)
    {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(48, max(1, (int) $request->get_param('per_page')));

        $products = wc_get_products([
            'status'     => 'publish',
            'limit'      => $per_page,
            'page'       => $page,
            'meta_key'   => '_tjdb_diamond_compatible',
            'meta_value' => 'yes',
        ]);

        $items = array_map([$this, 'format_product'], $products);

        return new \WP_REST_Response([
            'items' => $items,
            'page'  => $page,
        ], 200);
    }

    public function get_setting(\WP_REST_Request $request)
    {
        $id      = (int) $request->get_param('id');
        $product = wc_get_product($id);

        if (! $product || $product->get_status() !== 'publish' || get_post_meta($id, '_tjdb_diamond_compatible', true) !== 'yes') {
            return new \WP_Error('tjdb_not_found', __('Setting not found.', 'topjewellery-diamond-builder'), ['status' => 404]);
        }

        return new \WP_REST_Response($this->format_product($product), 200);
    }

    private function format_product($product)
    {
        $id = $product->get_id();
        $image_id = $product->get_image_id();

        $allowed_shapes = get_post_meta($id, '_tjdb_allowed_shapes', true);
        if (! is_array($allowed_shapes)) {
            $allowed_shapes = [];
        }

        return [
            'product_id'     => $id,
            'name'           => $product->get_name(),
            'price'          => $product->get_price(),
            'currency'       => get_woocommerce_currency(),
            'image'          => $image_id ? wp_get_attachment_image_url($image_id, 'medium') : null,
            'min_carat'      => (float) get_post_meta($id, '_tjdb_min_carat', true),
            'max_carat'      => (float) get_post_meta($id, '_tjdb_max_carat', true),
            'allowed_shapes' => $allowed_shapes,
        ];
    }
}
