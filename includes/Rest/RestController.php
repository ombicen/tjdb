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

class RestController
{
    private const NAMESPACE = 'tjdb/v1';

    private MarginCalculator $margin_calculator;
    private Eligibility $eligibility;
    private DiamondSearchEndpoint $search_endpoint;
    private DiamondDetailEndpoint $detail_endpoint;
    private CartEndpoint $cart_endpoint;

    public function __construct(MarginCalculator $margin_calculator, Eligibility $eligibility)
    {
        $this->margin_calculator = $margin_calculator;
        $this->eligibility = $eligibility;
        $this->search_endpoint = new DiamondSearchEndpoint($margin_calculator);
        $this->detail_endpoint = new DiamondDetailEndpoint($margin_calculator);
        $this->cart_endpoint = new CartEndpoint($margin_calculator, $eligibility);
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings_list'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/settings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_setting'],
            'permission_callback' => '__return_true',
            'args' => ['id' => ['required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/diamonds/search', [
            'methods' => 'POST',
            'callback' => [$this->search_endpoint, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/diamonds/detail', [
            'methods' => 'POST',
            'callback' => [$this->detail_endpoint, 'handle'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/cart/add', [
            'methods' => 'POST',
            'callback' => [$this->cart_endpoint, 'handle'],
            'permission_callback' => [$this, 'check_nonce'],
        ]);
    }

    /**
     * WP-CLI/curl testing note: the nonce check applies only to /cart/add,
     * which mutates the WC session cart. Read-only endpoints stay public
     * so the React app can load without a logged-in user.
     */
    public function check_nonce(WP_REST_Request $request)
    {
        $nonce = $request->get_header('X-WP-Nonce');

        if (! $nonce || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('tjdb_invalid_nonce', 'Invalid or missing nonce.', ['status' => 403]);
        }

        return true;
    }

    public function get_settings_list(WP_REST_Request $request)
    {
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 20));

        $tax_query = $this->eligibility->get_tax_query();

        if (empty($tax_query)) {
            return new WP_REST_Response(['items' => [], 'total_count' => 0]);
        }

        // Diamond-first flow: once a diamond is chosen, only show settings
        // that can actually accept it (shape allowed, carat within range).
        // The compatibility check needs product meta PHP-side, so every
        // eligible product is fetched and filtered *before* paginating —
        // paginating first would silently drop compatible settings that
        // just happened to sort past the page cutoff.
        $shape = strtoupper((string) $request->get_param('shape'));
        $carat = $request->get_param('carat') !== null ? (float) $request->get_param('carat') : null;

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'tax_query' => $tax_query,
        ]);

        $products = $this->filter_products_for_diamond($products, $shape, $carat);
        $total = count($products);
        $products = array_slice($products, ($page - 1) * $per_page, $per_page);

        $items = array_map([$this, 'product_to_setting_dto'], $products);

        return new WP_REST_Response(['items' => $items, 'total_count' => $total]);
    }

    /**
     * @param \WC_Product[] $products
     * @return \WC_Product[]
     */
    private function filter_products_for_diamond(array $products, string $shape, ?float $carat): array
    {
        if (! $shape && $carat === null) {
            return $products;
        }

        return array_values(array_filter($products, function (\WC_Product $product) use ($shape, $carat) {
            if ($shape) {
                $allowed_shapes = $product->get_meta('_tjdb_allowed_shapes');
                $allowed_shapes = is_array($allowed_shapes) ? $allowed_shapes : [];
                if (! empty($allowed_shapes) && ! in_array($shape, $allowed_shapes, true)) {
                    return false;
                }
            }

            if ($carat !== null) {
                $min_carat = (float) $product->get_meta('_tjdb_min_carat');
                $max_carat = (float) $product->get_meta('_tjdb_max_carat');
                if ($min_carat > 0 && $carat < $min_carat) {
                    return false;
                }
                if ($max_carat > 0 && $carat > $max_carat) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function get_setting(WP_REST_Request $request)
    {
        $product_id = absint($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (! $product || $product->get_status() !== 'publish' || ! $this->eligibility->is_eligible($product)) {
            return new WP_Error('tjdb_setting_not_found', 'Setting not found.', ['status' => 404]);
        }

        return new WP_REST_Response($this->product_to_setting_dto($product));
    }

    private function product_to_setting_dto(\WC_Product $product): array
    {
        $allowed_shapes = $product->get_meta('_tjdb_allowed_shapes');
        $is_variable = $product->is_type('variable');

        $dto = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price_cents' => (int) round((float) ($is_variable ? $product->get_variation_price('min') : $product->get_price()) * 100),
            'image' => wp_get_attachment_image_url($product->get_image_id(), 'medium') ?: null,
            'permalink' => get_permalink($product->get_id()),
            'min_carat' => (float) $product->get_meta('_tjdb_min_carat'),
            'max_carat' => (float) $product->get_meta('_tjdb_max_carat'),
            'allowed_shapes' => is_array($allowed_shapes) ? array_values($allowed_shapes) : [],
            'has_variations' => $is_variable,
            'variation_attributes' => [],
            'variations' => [],
        ];

        if (! $is_variable || ! $product instanceof \WC_Product_Variable) {
            return $dto;
        }

        foreach ($product->get_attributes() as $attribute) {
            if (! $attribute->get_variation()) {
                continue;
            }

            $taxonomy = $attribute->get_name();
            $options = [];

            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_terms() ?: [] as $term) {
                    $options[] = ['value' => $term->slug, 'label' => $term->name];
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    $options[] = ['value' => sanitize_title($option), 'label' => $option];
                }
            }

            $dto['variation_attributes'][] = [
                'attribute_key' => 'attribute_' . sanitize_title($taxonomy),
                'label' => wc_attribute_label($taxonomy),
                'options' => $options,
            ];
        }

        foreach ($product->get_available_variations() as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);

            if (! $variation) {
                continue;
            }

            $dto['variations'][] = [
                'variation_id' => $variation->get_id(),
                'attributes' => $variation_data['attributes'],
                'price_cents' => (int) round((float) $variation->get_price() * 100),
                'in_stock' => $variation->is_in_stock(),
                'purchasable' => $variation->is_purchasable(),
            ];
        }

        return $dto;
    }
}
