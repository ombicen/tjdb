<?php

namespace TopJewelleryDiamondBuilder\Frontend;

use TopJewelleryDiamondBuilder\Product\Eligibility;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders "Choose this setting" wherever an eligible product's normal
 * purchase actions would otherwise appear (shop-loop cards, single product
 * page), and suppresses those normal actions so it's the only option —
 * these settings are only sold as part of a diamond bundle.
 */
class BuilderButton
{
    private Eligibility $eligibility;

    public function __construct(Eligibility $eligibility)
    {
        $this->eligibility = $eligibility;
    }

    public function init(): void
    {
        // Archive / shop-loop cards (native WooCommerce archives and our
        // own settings archive both use the standard loop hooks).
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'maybe_suppress_loop_add_to_cart'], 10, 2);
        add_action('woocommerce_after_shop_loop_item', [$this, 'render_archive_button'], 15);

        // Full-width horizontal stepper above the whole product block.
        // woocommerce_before_single_product lands inside this theme's
        // reserved "WooCommerce Hook" Elementor widget slot, which is
        // collapsed to 0x0 by the theme's own CSS whenever it's empty
        // (verified live — WooCommerce's own notices wrapper collapses the
        // same way there). woocommerce_before_main_content instead fires
        // outside that slot, at the theme's actual content-wrapper level,
        // and renders full-width and visible (also verified live).
        add_action('woocommerce_before_main_content', [$this, 'render_top_stepper']);

        // Single product page: WooCommerce's own single-product add-to-cart
        // form is always rendered via wc_get_template('single-product/add-to-cart/{type}.php'),
        // whether that's called by the theme's standard hook or (as on this
        // site) directly by Elementor Pro's Add to Cart widget
        // (Product_Add_To_Cart::render() calls woocommerce_template_single_add_to_cart()
        // itself, bypassing woocommerce_single_product_summary entirely).
        // Filtering the template lookup catches both cases uniformly.
        add_filter('woocommerce_locate_template', [$this, 'filter_locate_template'], 10, 3);

        add_shortcode('tjdb_build_button', [$this, 'render_shortcode']);
    }

    public function maybe_suppress_loop_add_to_cart(string $html, \WC_Product $product): string
    {
        return $this->eligibility->is_eligible($product) ? '' : $html;
    }

    public function render_archive_button(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->eligibility->is_eligible($product)) {
            return;
        }

        $this->render_link($product, 'tjdb-archive-build-button');
    }

    /**
     * @param string $template      Located template path (possibly empty).
     * @param string $template_name Requested template name, e.g. 'single-product/add-to-cart/simple.php'.
     * @param array  $template_path
     */
    public function filter_locate_template(string $template, string $template_name, $template_path): string
    {
        if (strpos($template_name, 'single-product/add-to-cart/') !== 0) {
            return $template;
        }

        global $product;

        if (! $product instanceof \WC_Product || ! $this->eligibility->is_eligible($product)) {
            return $template;
        }

        return TJDB_PLUGIN_DIR . 'includes/Frontend/templates/single-add-to-cart-override.php';
    }

    private function render_link(\WC_Product $product, string $css_class): void
    {
        $url = self::get_builder_url($product->get_id());

        if (! $url) {
            return;
        }

        printf(
            '<a href="%s" class="button %s">%s</a>',
            esc_url($url),
            esc_attr($css_class),
            esc_html__('Choose This Setting', 'topjewellery-diamond-builder')
        );
    }

    public static function get_builder_url(int $product_id): ?string
    {
        $page_id = (int) get_option('tjdb_builder_page_id', 0);

        if (! $page_id) {
            return null;
        }

        $base = get_permalink($page_id);

        if (! $base) {
            return null;
        }

        $args = ['tjdb_setting_id' => $product_id];

        // Diamond-first flow: the settings step forwards the currently
        // selected diamond onto outgoing product-page links (see
        // renderSettingStep() in builder.js) so that landing back here via
        // "Choose This Setting" restores it instead of losing the diamond
        // just because the customer wanted to look at a product page first.
        if (isset($_GET['tjdb_diamond_id']) && is_string($_GET['tjdb_diamond_id'])) {
            $args['tjdb_diamond_id'] = sanitize_text_field(wp_unslash($_GET['tjdb_diamond_id']));
        }

        return add_query_arg($args, $base);
    }

    /**
     * Full-width horizontal stepper above the whole product block — see
     * woocommerce_before_main_content hook in init(). Only "Setting" is
     * ever "current" here since nothing else is chosen yet.
     */
    public function render_top_stepper(): void
    {
        if (! is_product()) {
            return;
        }

        global $product;

        if (! $product instanceof \WC_Product) {
            $product = wc_get_product(get_queried_object_id());
        }

        if (! $product instanceof \WC_Product || ! $this->eligibility->is_eligible($product)) {
            return;
        }

        echo self::render_stepper_html('tjdb-stepper-top'); // phpcs:ignore -- already escaped internally.

        // There's no WordPress hook available between the breadcrumb widget
        // and the product summary in this theme's Elementor single-product
        // template — both are rendered together as one Elementor blob via
        // the_content (verified empirically: a woocommerce_before_single_product_summary
        // mu-plugin marker never appeared in the rendered page). JS reposition
        // is the only reliable option here.
?>
        <script>
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                var stepper = document.querySelector('.tjdb-stepper-top');
                var breadcrumb = document.querySelector('.woocommerce-breadcrumb, .wd-el-breadcrumbs');
                if (stepper && breadcrumb && breadcrumb.parentNode) {
                    breadcrumb.parentNode.insertBefore(stepper, breadcrumb.nextSibling);
                }
            });
        })();
        </script>
<?php
    }

    public static function get_archive_url(): ?string
    {
        $page_id = (int) get_option('tjdb_archive_page_id', 0);

        if (! $page_id) {
            return null;
        }

        return get_permalink($page_id) ?: null;
    }

    /**
     * The builder page with no setting/diamond pre-selected — i.e. the
     * diamond-first entry point.
     */
    public static function get_builder_base_url(): ?string
    {
        $page_id = (int) get_option('tjdb_builder_page_id', 0);

        if (! $page_id) {
            return null;
        }

        return get_permalink($page_id) ?: null;
    }

    /**
     * The same stepper component used everywhere: here (a static, pre-JS
     * render on product pages and the settings archive), and inside the
     * builder SPA itself (assets/js/builder.js renderStepper()) — same
     * markup, same classes, same 3 steps. Nothing is ever "selected" here
     * (that only happens once you're actually in the SPA and pick
     * something), so Setting and Diamond are just plain entry-point links
     * and Complete is inert, matching the SPA's own unselected state.
     */
    public static function render_stepper_html(string $extra_class = 'tjdb-stepper-preview'): string
    {
        // Always 3 steps — ring size (when the setting has variations) is
        // chosen inline on the final "Complete" step, not a step of its own.
        $steps = [
            ['key' => 'setting', 'label' => __('Setting', 'topjewellery-diamond-builder'), 'current' => true, 'href' => self::get_archive_url()],
            ['key' => 'diamond', 'label' => __('Diamond', 'topjewellery-diamond-builder'), 'current' => false, 'href' => self::get_builder_base_url()],
            ['key' => 'complete', 'label' => __('Review Your Ring', 'topjewellery-diamond-builder'), 'current' => false, 'href' => null],
        ];

        ob_start();
?>
        <ol class="tjdb-stepper <?php echo esc_attr($extra_class); ?>">
            <?php foreach ($steps as $index => $step) : ?>
                <?php $tag = $step['href'] ? 'a' : 'span'; ?>
                <li class="tjdb-stepper-item<?php echo $step['current'] ? ' is-current' : ''; ?><?php echo $step['href'] ? ' is-clickable' : ''; ?>">
                    <<?php echo $tag; ?> class="tjdb-stepper-button"<?php echo $step['href'] ? ' href="' . esc_url($step['href']) . '"' : ''; ?>>
                        <span class="tjdb-stepper-circle"><?php echo esc_html($index + 1); ?></span>
                        <span class="tjdb-stepper-text">
                            <span class="tjdb-stepper-label"><?php echo esc_html($step['label']); ?></span>
                        </span>
                        <?php echo self::stepper_icon_svg(); // phpcs:ignore -- static markup. ?>
                    </<?php echo $tag; ?>>
                </li>
            <?php endforeach; ?>
        </ol>
<?php
        return (string) ob_get_clean();
    }

    private static function stepper_icon_svg(): string
    {
        return '<svg class="tjdb-stepper-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M4 9L8 3h8l4 6-10 12L4 9z"/><path d="M4 9h16M8 3l1.5 6L12 21l2.5-12L16 3"/></svg>';
    }

    /**
     * "Choose This Setting" button only — the full-width stepper renders
     * once already, at the top of the page via render_top_stepper().
     */
    public static function render_product_page_block(\WC_Product $product): string
    {
        $url = self::get_builder_url($product->get_id());

        if (! $url) {
            return '';
        }

        return sprintf(
            '<a href="%s" class="button tjdb-single-build-button">%s</a>',
            esc_url($url),
            esc_html__('Choose This Setting', 'topjewellery-diamond-builder')
        );
    }

    /**
     * Fallback for themes/page builders (e.g. an Elementor Pro single-product
     * template) that don't fire woocommerce_single_product_summary in the
     * usual place — drop [tjdb_build_button] directly into that template
     * (an Elementor "Shortcode" widget), replacing its Add to Cart widget.
     */
    public function render_shortcode(): string
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            $product = wc_get_product(get_the_ID());
        }

        if (! $product instanceof \WC_Product || ! $this->eligibility->is_eligible($product)) {
            return '';
        }

        return self::render_product_page_block($product);
    }
}
