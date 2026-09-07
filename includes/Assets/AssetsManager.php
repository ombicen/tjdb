<?php

namespace TopJewelleryDiamondBuilder\Assets;

if (! defined('ABSPATH')) {
    exit;
}

class AssetsManager
{
    public function init(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_builder_script']);
    }

    /**
     * The stepper/card styles are shared between the interactive builder
     * page and the static stepper preview on eligible product pages
     * (Frontend\BuilderButton), so loaded unconditionally on the frontend —
     * it's a few KB, cheaper than working out every page it might render on
     * before wp_head has already printed.
     */
    public function enqueue_styles(): void
    {
        $css_path = TJDB_PLUGIN_DIR . 'assets/css/builder.css';

        if (! file_exists($css_path)) {
            return;
        }

        wp_enqueue_style(
            'tjdb-builder-styles',
            TJDB_PLUGIN_URL . 'assets/css/builder.css',
            [],
            (string) filemtime($css_path)
        );
    }

    /**
     * Only enqueues on pages containing the shortcode, to avoid loading the
     * builder script site-wide.
     */
    public function maybe_enqueue_builder_script(): void
    {
        if (! is_singular() || ! has_shortcode(get_post()->post_content ?? '', 'tjdb_builder')) {
            return;
        }

        $js_path = TJDB_PLUGIN_DIR . 'assets/js/builder.js';

        if (! file_exists($js_path)) {
            return;
        }

        wp_enqueue_script(
            'tjdb-builder-app',
            TJDB_PLUGIN_URL . 'assets/js/builder.js',
            [],
            (string) filemtime($js_path),
            true
        );

        $archive_page_id = (int) get_option('tjdb_archive_page_id', 0);

        wp_localize_script('tjdb-builder-app', 'tjdbBuilderData', [
            'restUrl' => esc_url_raw(rest_url('tjdb/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'cartUrl' => wc_get_cart_url(),
            'archiveUrl' => $archive_page_id ? get_permalink($archive_page_id) : null,
            // The store's own currency settings — prices were hardcoded to
            // "£" before, which would have shown the wrong symbol (and the
            // wrong decimal formatting) on any store not configured for GBP.
            'currencySymbol' => get_woocommerce_currency_symbol(),
            'currencyDecimals' => wc_get_price_decimals(),
        ]);
    }
}
