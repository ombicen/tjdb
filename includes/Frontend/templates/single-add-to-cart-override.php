<?php
/**
 * Replaces WooCommerce's single-product add-to-cart form for eligible
 * products, regardless of what actually calls
 * woocommerce_template_single_add_to_cart() (theme, Elementor's Add to Cart
 * widget, etc.) — see Frontend\BuilderButton::filter_locate_template().
 *
 * Self-contained (no $args) since WooCommerce's own call to
 * woocommerce_template_single_add_to_cart() passes none.
 */

if (! defined('ABSPATH')) {
    exit;
}

global $product;

if (! $product instanceof WC_Product) {
    return;
}

echo \TopJewelleryDiamondBuilder\Frontend\BuilderButton::render_product_page_block($product); // phpcs:ignore -- already escaped internally.
