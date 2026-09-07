<?php

namespace TopJewelleryDiamondBuilder\Cart;

use TopJewelleryDiamondBuilder\Pricing\MarginCalculator;
use TopJewelleryDiamondBuilder\Rest\DiamondDetailEndpoint;

if (! defined('ABSPATH')) {
    exit;
}

class CartIntegration
{
    private DiamondDetailEndpoint $detail_endpoint;

    public function __construct(MarginCalculator $margin_calculator)
    {
        $this->detail_endpoint = new DiamondDetailEndpoint($margin_calculator);
    }

    public function init(): void
    {
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'enforce_line_price'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_line_item_meta'], 10, 4);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_diamonds_at_checkout'], 10, 2);
    }

    public function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id): array
    {
        // Diamond data is attached directly via WC()->cart->add_to_cart() in
        // Rest\CartEndpoint, so nothing to do here for the normal flow. This
        // hook exists so any future non-REST add-to-cart path stays covered
        // by the pricing enforcement below (it keys off 'tjdb_diamond').
        return $cart_item_data;
    }

    public function enforce_line_price(\WC_Cart $cart): void
    {
        if (is_admin() && ! wp_doing_ajax()) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['tjdb_diamond']['line_total_cents'])) {
                continue;
            }

            $line_total = (float) $cart_item['tjdb_diamond']['line_total_cents'] / 100;
            $cart_item['data']->set_price($line_total);
        }
    }

    public function display_cart_item_data(array $item_data, array $cart_item): array
    {
        if (empty($cart_item['tjdb_diamond'])) {
            return $item_data;
        }

        $cert = $cart_item['tjdb_diamond']['certificate'] ?? [];

        $spec = sprintf(
            '%.2fct %s, %s/%s, %s cut',
            (float) ($cert['carats'] ?? 0),
            ucfirst(strtolower($cert['shape'] ?? '')),
            $cert['color'] ?? '',
            $cert['clarity'] ?? '',
            $cert['cut'] ?? ''
        );

        $item_data[] = [
            'key' => __('Diamond', 'topjewellery-diamond-builder'),
            'value' => esc_html($spec),
        ];

        if (! empty($cert['cert_number'])) {
            $item_data[] = [
                'key' => __('Certificate #', 'topjewellery-diamond-builder'),
                'value' => esc_html($cert['cert_number']),
            ];
        }

        return $item_data;
    }

    /**
     * A diamond can go from AVAILABLE to sold/held between add-to-cart and
     * checkout — the detail cache alone (15 minutes) doesn't cover a
     * customer who adds one item, then keeps shopping or reviewing for
     * longer than that before placing the order. This is the last gate
     * before an order is actually created, so it re-checks every diamond
     * currently in the cart (via the same cache-aware fetch, so it usually
     * doesn't need a fresh Nivoda call either) and blocks checkout — rather
     * than silently accepting an order for a stone that's no longer theirs
     * to sell — if one is no longer available.
     *
     * @param array<string, mixed> $data
     */
    public function validate_diamonds_at_checkout(array $data, \WP_Error $errors): void
    {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $diamond_id = $cart_item['tjdb_diamond']['diamond_id'] ?? null;

            if (! $diamond_id) {
                continue;
            }

            try {
                $diamond = $this->detail_endpoint->fetch($diamond_id);
            } catch (\RuntimeException $e) {
                $errors->add('tjdb_checkout_diamond_unverified', sprintf(
                    /* translators: %s: product name */
                    __('We couldn’t verify the diamond in your "%s" ring right now. Please try again in a moment.', 'topjewellery-diamond-builder'),
                    $cart_item['data']->get_name()
                ));
                continue;
            }

            if (! $diamond || ($diamond['availability'] ?? 'UNKNOWN') !== 'AVAILABLE') {
                $errors->add('tjdb_checkout_diamond_unavailable', sprintf(
                    /* translators: %s: product name */
                    __('The diamond in your "%s" ring is no longer available. Please remove it and choose another.', 'topjewellery-diamond-builder'),
                    $cart_item['data']->get_name()
                ));
            }
        }
    }

    /**
     * @param \WC_Order_Item_Product $item
     */
    public function add_order_line_item_meta($item, string $cart_item_key, array $values, \WC_Order $order): void
    {
        if (empty($values['tjdb_diamond'])) {
            return;
        }

        $diamond = $values['tjdb_diamond'];
        $cert = $diamond['certificate'] ?? [];

        // Customer-visible.
        $item->add_meta_data(__('Diamond', 'topjewellery-diamond-builder'), sprintf(
            '%.2fct %s',
            (float) ($cert['carats'] ?? 0),
            ucfirst(strtolower($cert['shape'] ?? ''))
        ), true);
        $item->add_meta_data(__('Carat', 'topjewellery-diamond-builder'), $cert['carats'] ?? '', true);
        $item->add_meta_data(__('Color', 'topjewellery-diamond-builder'), $cert['color'] ?? '', true);
        $item->add_meta_data(__('Clarity', 'topjewellery-diamond-builder'), $cert['clarity'] ?? '', true);
        $item->add_meta_data(__('Cut', 'topjewellery-diamond-builder'), $cert['cut'] ?? '', true);
        $item->add_meta_data(__('Certificate #', 'topjewellery-diamond-builder'), $cert['cert_number'] ?? '', true);

        // Admin-only (underscore-prefixed, hidden from the customer-facing order view).
        $item->add_meta_data('_tjdb_nivoda_diamond_id', $diamond['diamond_id'] ?? '', true);
        $item->add_meta_data('_tjdb_cost_price_cents', $diamond['cost_price_cents'] ?? 0, true);
        $item->add_meta_data('_tjdb_margin_percent', $diamond['margin_percent'] ?? 0, true);
        $item->add_meta_data('_tjdb_diamond_price_cents', $diamond['diamond_price_cents'] ?? 0, true);
        $item->add_meta_data('_tjdb_setting_price_cents', $diamond['setting_price_cents'] ?? 0, true);
        $item->add_meta_data('_tjdb_certificate_json', wp_json_encode($diamond['certificate_full'] ?? []), true);
    }
}
