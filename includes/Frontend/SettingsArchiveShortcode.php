<?php

namespace TopJewelleryDiamondBuilder\Frontend;

use TopJewelleryDiamondBuilder\Product\Eligibility;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * [tjdb_settings_archive] — a regular WooCommerce product archive (same
 * theme card template as any shop/category page) restricted to products
 * in the admin-chosen eligible categories/tags, so the store gets a single
 * browsable page across every eligible category/tag without depending on
 * WordPress' native per-term archive URLs.
 */
class SettingsArchiveShortcode
{
    private const PER_PAGE = 24;

    private Eligibility $eligibility;

    public function __construct(Eligibility $eligibility)
    {
        $this->eligibility = $eligibility;
    }

    public function init(): void
    {
        add_shortcode('tjdb_settings_archive', [$this, 'render']);
    }

    public function render(): string
    {
        $tax_query = $this->eligibility->get_tax_query();

        if (empty($tax_query)) {
            return $this->wrap('<p>' . esc_html__('No settings are available right now.', 'topjewellery-diamond-builder') . '</p>');
        }

        // Diamond-first flow: the builder SPA loads this same page (a real
        // page request, not REST — see fetchCompatibleSettingsHtml() in
        // builder.js) with ?shape=&carat= so the theme's own CSS/JS handles
        // everything naturally, instead of faking the archive via a REST
        // endpoint (which Woodmart deliberately skips per-card styling for —
        // see AssetsManager's earlier now-removed priming workaround).
        $shape = isset($_GET['shape']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['shape']))) : '';
        $carat = isset($_GET['carat']) && $_GET['carat'] !== '' ? (float) $_GET['carat'] : null;

        if ($shape || $carat !== null) {
            return $this->wrap($this->render_filtered($tax_query, $shape, $carat));
        }

        $paged = max(1, (int) (get_query_var('paged') ?: (isset($_GET['paged']) ? absint($_GET['paged']) : 1)));

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $paged,
            'tax_query' => $tax_query,
            'ignore_sticky_posts' => true,
        ]);

        if (! $query->have_posts()) {
            return $this->wrap('<p>' . esc_html__('No settings are available right now.', 'topjewellery-diamond-builder') . '</p>');
        }

        ob_start();

        wc_setup_loop([
            'columns' => wc_get_default_products_per_row(),
            'name' => 'tjdb_settings_archive',
            'is_shortcode' => true,
            'total' => (int) $query->found_posts,
            'per_page' => self::PER_PAGE,
            'current_page' => $paged,
        ]);

        woocommerce_product_loop_start();

        while ($query->have_posts()) {
            $query->the_post();

            global $product;
            $product = wc_get_product(get_the_ID());

            wc_get_template_part('content', 'product');
        }

        woocommerce_product_loop_end();

        wc_reset_loop();
        wp_reset_postdata();

        $output = ob_get_clean();

        return $this->wrap($output . $this->render_pagination($paged, (int) $query->max_num_pages));
    }

    /**
     * Once a diamond is chosen, only show settings that can actually accept
     * it (shape allowed, carat in range). The compatibility check has to run
     * in PHP against product meta (WP_Query can't filter on it directly), so
     * every eligible product's meta is needed up front — not just the first
     * page's worth — before filtering and paginating; fetching only a
     * limited batch first would silently drop compatible settings that just
     * happened to sort past that cutoff. See get_eligible_products_with_meta()
     * for how that's kept cheap despite needing the whole catalogue.
     */
    private function render_filtered(array $tax_query, string $shape, ?float $carat): string
    {
        $eligible = $this->get_eligible_products_with_meta($tax_query);

        $ids = [];
        foreach ($eligible as $id => $meta) {
            if ($shape && ! empty($meta['allowed_shapes']) && ! in_array($shape, $meta['allowed_shapes'], true)) {
                continue;
            }

            if ($carat !== null) {
                if ($meta['min_carat'] > 0 && $carat < $meta['min_carat']) {
                    continue;
                }
                if ($meta['max_carat'] > 0 && $carat > $meta['max_carat']) {
                    continue;
                }
            }

            $ids[] = $id;
        }

        $paged = max(1, (int) (get_query_var('paged') ?: (isset($_GET['paged']) ? absint($_GET['paged']) : 1)));
        $page_ids = array_slice($ids, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        $output = self::render_products_grid(
            $page_ids,
            __('No settings currently support this diamond. Try a different diamond.', 'topjewellery-diamond-builder')
        );

        return $output . $this->render_pagination($paged, (int) ceil(count($ids) / self::PER_PAGE));
    }

    /**
     * Cached (1 hour, matching this plugin's other Nivoda/search caches) map
     * of eligible product id => its shape/carat meta. The diamond-first
     * "choose your setting" step re-runs render_filtered() on essentially
     * every diamond pick and every "Change", so without this it was
     * instantiating a full WC_Product (with all its own data-store queries)
     * for every eligible product, on every one of those requests, just to
     * read three meta values off each. This fetches bare IDs, primes
     * WordPress' own meta cache with a single extra query, then reads the
     * three meta fields straight from that cache — no WC_Product involved
     * until render_products_grid() builds the final ≤24-item page.
     *
     * @return array<int, array{allowed_shapes: string[], min_carat: float, max_carat: float}>
     */
    private function get_eligible_products_with_meta(array $tax_query): array
    {
        $cache_key = 'tjdb_eligible_meta_' . md5(wp_json_encode($tax_query));
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $ids = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'tax_query' => $tax_query,
            'return' => 'ids',
        ]);

        $map = [];

        if ($ids) {
            update_meta_cache('post', $ids);

            foreach ($ids as $id) {
                $allowed_shapes = get_post_meta($id, '_tjdb_allowed_shapes', true);
                $map[$id] = [
                    'allowed_shapes' => is_array($allowed_shapes) ? $allowed_shapes : [],
                    'min_carat' => (float) get_post_meta($id, '_tjdb_min_carat', true),
                    'max_carat' => (float) get_post_meta($id, '_tjdb_max_carat', true),
                ];
            }
        }

        set_transient($cache_key, $map, HOUR_IN_SECONDS);

        return $map;
    }

    /**
     * Same markup Woodmart's own WooCommerce archive templates use
     * (`.wd-loop-footer.products-footer > nav.woocommerce-pagination.wd-pagination > ul.page-numbers`)
     * so this page's pagination picks up the theme's own styling instead of
     * looking like unstyled plain links.
     */
    private function render_pagination(int $paged, int $total_pages): string
    {
        $links = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'type' => 'list',
            'prev_text' => '←',
            'next_text' => '→',
        ]);

        if (! $links) {
            return '';
        }

        // paginate_links() with type=list already wraps in <ul class="page-numbers">;
        // it just needs the theme's own nav/section wrapper around it.
        return '<div class="wd-loop-footer products-footer"><nav class="woocommerce-pagination wd-pagination" aria-label="' . esc_attr__('Product Pagination', 'topjewellery-diamond-builder') . '">' . $links . '</nav></div>';
    }

    private function wrap(string $grid_html): string
    {
        // No product is ever "current" on this page — it renders without a
        // price/Change link (see BuilderButton::render_stepper_html()).
        return BuilderButton::render_stepper_html('tjdb-stepper-top') . $grid_html;
    }

    /**
     * Renders the theme's product-card loop for an arbitrary fixed set of
     * product IDs (order preserved) rather than a paginated tax_query.
     *
     * @param int[] $product_ids
     */
    public static function render_products_grid(array $product_ids, string $empty_message = ''): string
    {
        $empty_message = $empty_message ?: __('No settings are available right now.', 'topjewellery-diamond-builder');

        if (empty($product_ids)) {
            return '<p>' . esc_html($empty_message) . '</p>';
        }

        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => count($product_ids),
            'post__in' => $product_ids,
            'orderby' => 'post__in',
            'ignore_sticky_posts' => true,
        ]);

        if (! $query->have_posts()) {
            return '<p>' . esc_html($empty_message) . '</p>';
        }

        ob_start();

        wc_setup_loop([
            'columns' => wc_get_default_products_per_row(),
            'name' => 'tjdb_settings_archive',
            'is_shortcode' => true,
            'total' => (int) $query->found_posts,
            'per_page' => count($product_ids),
            'current_page' => 1,
        ]);

        woocommerce_product_loop_start();

        while ($query->have_posts()) {
            $query->the_post();

            global $product;
            $product = wc_get_product(get_the_ID());

            wc_get_template_part('content', 'product');
        }

        woocommerce_product_loop_end();

        wc_reset_loop();
        wp_reset_postdata();

        return (string) ob_get_clean();
    }
}
