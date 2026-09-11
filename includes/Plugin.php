<?php

namespace TopJewelleryDiamondBuilder;

if (! defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private $plugin_file;

    private $rest_controller;
    private $cart_integration;
    private $margin_calculator;
    private $eligibility;
    private $builder_shortcode;
    private $settings_archive_shortcode;
    private $builder_button;
    private $settings_page;
    private $product_metabox;
    private $order_display;
    private $assets_manager;

    public function __construct($plugin_file)
    {
        $this->plugin_file = $plugin_file;
    }

    public function run()
    {
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        add_action('init', [$this, 'init_plugin']);
    }

    public function init_plugin()
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        $this->load_dependencies();

        $this->margin_calculator = new Pricing\MarginCalculator();
        $this->eligibility = new Product\Eligibility();

        $this->rest_controller = new Rest\RestController($this->margin_calculator, $this->eligibility);
        $this->rest_controller->init();

        $this->cart_integration = new Cart\CartIntegration($this->margin_calculator);
        $this->cart_integration->init();

        $this->assets_manager = new Assets\AssetsManager();
        $this->assets_manager->init();

        $this->builder_shortcode = new Frontend\BuilderShortcode();
        $this->builder_shortcode->init();

        $this->settings_archive_shortcode = new Frontend\SettingsArchiveShortcode($this->eligibility);
        $this->settings_archive_shortcode->init();

        $this->builder_button = new Frontend\BuilderButton($this->eligibility);
        $this->builder_button->init();

        if (is_admin()) {
            $this->settings_page = new Admin\SettingsPage();
            $this->settings_page->init();

            $this->product_metabox = new Admin\ProductMetabox();
            $this->product_metabox->init();

            $this->order_display = new Admin\OrderDisplay();
            $this->order_display->init();
        }
    }

    private function load_dependencies()
    {
        require_once TJDB_PLUGIN_DIR . 'includes/Pricing/MarginCalculator.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Product/Eligibility.php';

        require_once TJDB_PLUGIN_DIR . 'includes/Nivoda/NivodaAuth.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Nivoda/NivodaClient.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Nivoda/DiamondQueryBuilder.php';

        require_once TJDB_PLUGIN_DIR . 'includes/Rest/RateLimiter.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Rest/RestController.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Rest/DiamondSearchEndpoint.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Rest/DiamondDetailEndpoint.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Rest/CartEndpoint.php';

        require_once TJDB_PLUGIN_DIR . 'includes/Cart/CartIntegration.php';

        require_once TJDB_PLUGIN_DIR . 'includes/Assets/AssetsManager.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Frontend/Stepper.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Frontend/BuilderShortcode.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Frontend/SettingsArchiveShortcode.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Frontend/BuilderButton.php';

        require_once TJDB_PLUGIN_DIR . 'includes/Admin/SettingsPage.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Admin/ProductMetabox.php';
        require_once TJDB_PLUGIN_DIR . 'includes/Admin/OrderDisplay.php';
    }

    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $this->plugin_file,
                true
            );
        }
    }

    public function woocommerce_missing_notice()
    {
?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Top Jewellery Diamond Builder requires WooCommerce to be installed and activated.', 'topjewellery-diamond-builder'); ?></p>
        </div>
<?php
    }
}
