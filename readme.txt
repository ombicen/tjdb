=== Top Jewellery Diamond Builder ===
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.4.2
License: GPLv2 or later

== Description ==

Lets customers build a custom ring by picking a diamond-compatible WooCommerce
"setting" product, then a live loose diamond sourced from the Nivoda
marketplace API, combined into a single priced cart/order line item.

== Setup ==

1. Choose which products are diamond-compatible: WooCommerce → Diamond
   Builder → Eligible Products, pick the categories/tags. Products in them
   get a "Choose This Setting" button in place of Add to Cart, and can
   optionally have per-product carat/shape constraints set on the product
   edit screen's "Diamond Builder" metabox.
2. Configure Nivoda credentials at WooCommerce → Diamond Builder. Staging
   endpoint/credentials are pre-filled on activation.
3. Set margin tiers by carat bracket on the same screen. This is the only
   place margin is configured — it is never shown to the customer.
4. Two pages are auto-created on activation: "Build Your Own"
   ([tjdb_builder], the diamond-picker flow) and "Diamond Ring Settings"
   ([tjdb_settings_archive], a browsable listing of every eligible
   setting). Add the archive page to your menu if you want it discoverable.
5. If single product pages use a custom template (e.g. an Elementor Pro
   Theme Builder single-product template) that doesn't fire the normal
   `woocommerce_single_product_summary` hooks, add a Shortcode widget with
   `[tjdb_build_button]` to that template instead — it renders the same
   stepper preview + button.

== Development ==

No build step. `assets/js/builder.js` (vanilla JS, no framework) and
`assets/css/builder.css` are hand-authored and enqueued directly — edit and
reload. Nivoda diamond search results are cached server-side for 1 hour
(`DiamondSearchEndpoint`); the per-diamond detail lookup used to validate
add-to-cart is never cached, so pricing/availability stay live at checkout.
