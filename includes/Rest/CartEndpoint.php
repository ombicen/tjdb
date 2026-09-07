<?php

namespace TopJewelleryDiamondBuilder\Rest;

use TopJewelleryDiamondBuilder\Pricing\MarginCalculator;
use TopJewelleryDiamondBuilder\Product\Eligibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

class CartEndpoint
{
    private DiamondDetailEndpoint $detail_endpoint;
    private Eligibility $eligibility;

    public function __construct(MarginCalculator $margin_calculator, Eligibility $eligibility)
    {
        $this->detail_endpoint = new DiamondDetailEndpoint($margin_calculator);
        $this->eligibility = $eligibility;
    }

    public function handle(WP_REST_Request $request)
    {
        // WooCommerce only bootstraps WC()->cart/session on normal frontend
        // page loads; a REST API request needs it forced explicitly.
        if (is_null(WC()->cart)) {
            wc_load_cart();
        }

        $params = $request->get_json_params() ?: [];

        $product_id = absint($params['product_id'] ?? 0);
        $diamond_id = sanitize_text_field($params['diamond_id'] ?? '');
        $quantity = max(1, absint($params['quantity'] ?? 1));
        $variation_id = absint($params['variation_id'] ?? 0);

        if (! $product_id || ! $diamond_id) {
            return new WP_Error('tjdb_invalid_request', 'product_id and diamond_id are required.', ['status' => 400]);
        }

        $product = wc_get_product($product_id);

        if (! $product || $product->get_status() !== 'publish') {
            return new WP_Error('tjdb_invalid_product', 'This setting is not available.', ['status' => 404]);
        }

        if (! $this->eligibility->is_eligible($product)) {
            return new WP_Error('tjdb_not_diamond_compatible', 'This product does not support diamond building.', ['status' => 400]);
        }

        $variation_attributes = [];
        $setting_price_source = $product;

        if ($product->is_type('variable')) {
            if (! $variation_id) {
                return new WP_Error('tjdb_variation_required', 'Please choose the available options for this setting.', ['status' => 400]);
            }

            $variation = wc_get_product($variation_id);

            if (! $variation || $variation->get_parent_id() !== $product_id || ! $variation->exists()) {
                return new WP_Error('tjdb_invalid_variation', 'The selected options are not available for this setting.', ['status' => 400]);
            }

            if (! $variation->is_purchasable() || ! $variation->is_in_stock()) {
                return new WP_Error('tjdb_variation_unavailable', 'The selected options are no longer available.', ['status' => 409]);
            }

            $variation_attributes = $variation->get_variation_attributes();
            $setting_price_source = $variation;
        }

        // Price/availability/certificate always come from our own server-side
        // record of Nivoda's response (DiamondDetailEndpoint::fetch(), which
        // caches for 15 minutes) — never from the client. In the common case
        // this was already fetched and cached seconds earlier when the
        // "Complete your ring" step loaded, so this hits the cache rather
        // than making a second live call to Nivoda's (flaky, staging) API;
        // it only actually hits Nivoda again once that cache has expired.
        try {
            $diamond = $this->detail_endpoint->fetch($diamond_id);
        } catch (\RuntimeException $e) {
            return new WP_Error('tjdb_nivoda_error', 'Unable to verify this diamond right now.', ['status' => 502]);
        }

        if (! $diamond) {
            return new WP_Error('tjdb_diamond_not_found', 'This diamond is no longer available.', ['status' => 404]);
        }

        if (($diamond['availability'] ?? 'UNKNOWN') !== 'AVAILABLE') {
            return new WP_Error('tjdb_diamond_unavailable', 'This diamond is no longer available.', ['status' => 409]);
        }

        // Every diamond is a single, individually identified stone — unlike
        // an ordinary product, "two of the same diamond" isn't a valid
        // order, it's the same physical stone counted twice.
        foreach (WC()->cart->get_cart() as $existing_item) {
            if (($existing_item['tjdb_diamond']['diamond_id'] ?? null) === $diamond['diamond_id']) {
                return new WP_Error('tjdb_duplicate_diamond', 'This diamond is already in your cart.', ['status' => 409]);
            }
        }

        $carats = (float) ($diamond['certificate']['carats'] ?? 0);
        $shape = $diamond['certificate']['shape'] ?? null;

        $min_carat = (float) $product->get_meta('_tjdb_min_carat');
        $max_carat = (float) $product->get_meta('_tjdb_max_carat');
        $allowed_shapes = $product->get_meta('_tjdb_allowed_shapes');
        $allowed_shapes = is_array($allowed_shapes) ? $allowed_shapes : [];

        if ($min_carat > 0 && $carats < $min_carat) {
            return new WP_Error('tjdb_carat_out_of_range', 'This diamond is below the minimum carat for this setting.', ['status' => 400]);
        }

        if ($max_carat > 0 && $carats > $max_carat) {
            return new WP_Error('tjdb_carat_out_of_range', 'This diamond exceeds the maximum carat for this setting.', ['status' => 400]);
        }

        if (! empty($allowed_shapes) && $shape && ! in_array($shape, $allowed_shapes, true)) {
            return new WP_Error('tjdb_shape_not_allowed', 'This diamond shape is not available for this setting.', ['status' => 400]);
        }

        $setting_price_cents = (int) round((float) $setting_price_source->get_price() * 100);
        $diamond_price_cents = (int) $diamond['price_cents'];
        $line_total_cents = $setting_price_cents + $diamond_price_cents;

        // The customer confirmed a specific total on the Complete step. If
        // the server-side (cached-or-live) price has since moved — the
        // detail cache expired and Nivoda's price changed, or the setting's
        // own price changed — silently charging the new total would mean
        // charging more (or less) than what was shown. Reject instead so
        // the customer sees and explicitly accepts the new total.
        if (isset($params['expected_total_cents']) && (int) $params['expected_total_cents'] !== $line_total_cents) {
            return new WP_Error('tjdb_price_changed', 'The price has changed since you reviewed it. Please check the updated total before adding to cart.', [
                'status' => 409,
                'line_total_cents' => $line_total_cents,
                'setting_price_cents' => $setting_price_cents,
                'diamond_price_cents' => $diamond_price_cents,
            ]);
        }

        $cart_item_data = [
            'tjdb_diamond' => [
                'diamond_id' => $diamond['diamond_id'],
                'certificate' => $diamond['certificate'],
                'certificate_full' => $diamond['certificate_full'],
                'cost_price_cents' => $diamond['cost_price_cents'],
                'margin_percent' => $diamond['margin_percent'],
                'diamond_price_cents' => $diamond_price_cents,
                'setting_price_cents' => $setting_price_cents,
                'line_total_cents' => $line_total_cents,
                'unique_key' => wp_generate_uuid4(),
            ],
        ];

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data);

        if (! $cart_item_key) {
            return new WP_Error('tjdb_add_to_cart_failed', 'Could not add this bundle to the cart.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'cart_item_key' => $cart_item_key,
            'line_total_cents' => $line_total_cents,
            'cart_url' => wc_get_cart_url(),
        ]);
    }
}
