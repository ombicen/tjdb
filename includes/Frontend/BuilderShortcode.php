<?php

namespace TopJewelleryDiamondBuilder\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

class BuilderShortcode
{
    public function init(): void
    {
        add_shortcode('tjdb_builder', [$this, 'render']);
    }

    public function render(): string
    {
        if (! file_exists(TJDB_PLUGIN_DIR . 'assets/js/builder.js')) {
            if (current_user_can('manage_woocommerce')) {
                return '<p>' . esc_html__('Diamond Builder: assets/js/builder.js is missing.', 'topjewellery-diamond-builder') . '</p>';
            }
            return '';
        }

        return '<div id="tjdb-builder-root"></div>';
    }
}
