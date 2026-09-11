<?php

namespace TopJewelleryDiamondBuilder\Admin;

use TopJewelleryDiamondBuilder\Nivoda\NivodaAuth;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_tjdb_save_settings', [$this, 'handle_save']);
        add_action('wp_ajax_tjdb_test_nivoda_connection', [$this, 'ajax_test_connection']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Diamond Builder', 'topjewellery-diamond-builder'),
            __('Diamond Builder', 'topjewellery-diamond-builder'),
            'manage_woocommerce',
            'tjdb-settings',
            [$this, 'render']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'tjdb-settings') === false) {
            return;
        }

        wp_enqueue_script(
            'tjdb-margin-table',
            TJDB_PLUGIN_URL . 'assets/admin/margin-table.js',
            [],
            TJDB_VERSION,
            true
        );

        wp_enqueue_script(
            'tjdb-settings-page',
            TJDB_PLUGIN_URL . 'assets/admin/settings-page.js',
            [],
            TJDB_VERSION,
            true
        );

        wp_register_style('tjdb-admin-icons', false, [], TJDB_VERSION);
        wp_enqueue_style('tjdb-admin-icons');
        wp_add_inline_style('tjdb-admin-icons', '.tjdb-admin-icon{width:16px;height:16px;vertical-align:-3px;stroke-width:2}.tjdb-remove-tier{display:inline-flex!important;align-items:center;justify-content:center;min-width:30px}');

        wp_localize_script('tjdb-settings-page', 'tjdbSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tjdb_test_connection'),
        ]);
    }

    public function handle_save(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'topjewellery-diamond-builder'));
        }

        check_admin_referer('tjdb_save_settings');

        $nivoda_settings = [
            'endpoint' => esc_url_raw(wp_unslash($_POST['tjdb_endpoint'] ?? '')),
            'username' => sanitize_text_field(wp_unslash($_POST['tjdb_username'] ?? '')),
            'password' => sanitize_text_field(wp_unslash($_POST['tjdb_password'] ?? '')),
        ];
        update_option('tjdb_nivoda_settings', $nivoda_settings);

        $tiers = [];
        $carat_from = wp_unslash($_POST['tier_carat_from'] ?? []);
        $carat_to = wp_unslash($_POST['tier_carat_to'] ?? []);
        $margin_percent = wp_unslash($_POST['tier_margin_percent'] ?? []);

        if (is_array($carat_from)) {
            foreach ($carat_from as $i => $from) {
                $from = (float) $from;
                $to_raw = $carat_to[$i] ?? '';
                $to = $to_raw === '' ? null : (float) $to_raw;
                $percent = (float) ($margin_percent[$i] ?? 0);

                if ($percent < 0) {
                    continue;
                }
                if ($to !== null && $to <= $from) {
                    continue;
                }

                $tiers[] = [
                    'carat_from' => $from,
                    'carat_to' => $to,
                    'margin_percent' => $percent,
                ];
            }
        }

        usort($tiers, fn($a, $b) => $a['carat_from'] <=> $b['carat_from']);
        update_option('tjdb_margin_tiers', $tiers);

        $default_percent = (float) ($_POST['tjdb_default_margin_percent'] ?? 25);
        update_option('tjdb_margin_default_percent', max(0, $default_percent));

        $eligible_categories = isset($_POST['tjdb_eligible_categories']) && is_array($_POST['tjdb_eligible_categories'])
            ? array_map('absint', wp_unslash($_POST['tjdb_eligible_categories']))
            : [];
        update_option('tjdb_eligible_categories', $eligible_categories);

        $eligible_tags = isset($_POST['tjdb_eligible_tags']) && is_array($_POST['tjdb_eligible_tags'])
            ? array_map('absint', wp_unslash($_POST['tjdb_eligible_tags']))
            : [];
        update_option('tjdb_eligible_tags', $eligible_tags);

        update_option('tjdb_builder_page_id', absint($_POST['tjdb_builder_page_id'] ?? 0));

        (new NivodaAuth())->invalidate();

        wp_safe_redirect(add_query_arg(['page' => 'tjdb-settings', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function ajax_test_connection(): void
    {
        check_ajax_referer('tjdb_test_connection', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'topjewellery-diamond-builder')], 403);
        }

        try {
            $token = (new NivodaAuth())->get_token(true);
            wp_send_json_success(['message' => __('Connected successfully.', 'topjewellery-diamond-builder'), 'token_prefix' => substr($token, 0, 12) . '...']);
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $nivoda_settings = get_option('tjdb_nivoda_settings', []);
        $tiers = get_option('tjdb_margin_tiers', []);
        $default_percent = get_option('tjdb_margin_default_percent', 25.0);
        $eligible_categories = get_option('tjdb_eligible_categories', []);
        $eligible_tags = get_option('tjdb_eligible_tags', []);
        $builder_page_id = get_option('tjdb_builder_page_id', 0);

        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);

?>
        <div class="wrap tjdb-settings-page">
            <h1><?php esc_html_e('Diamond Builder Settings', 'topjewellery-diamond-builder'); ?></h1>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'topjewellery-diamond-builder'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tjdb_save_settings">
                <?php wp_nonce_field('tjdb_save_settings'); ?>

                <h2><?php esc_html_e('Nivoda API Credentials', 'topjewellery-diamond-builder'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tjdb_endpoint"><?php esc_html_e('Endpoint', 'topjewellery-diamond-builder'); ?></label></th>
                        <td><input type="url" id="tjdb_endpoint" name="tjdb_endpoint" class="regular-text" value="<?php echo esc_attr($nivoda_settings['endpoint'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tjdb_username"><?php esc_html_e('Username', 'topjewellery-diamond-builder'); ?></label></th>
                        <td><input type="text" id="tjdb_username" name="tjdb_username" class="regular-text" value="<?php echo esc_attr($nivoda_settings['username'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tjdb_password"><?php esc_html_e('Password', 'topjewellery-diamond-builder'); ?></label></th>
                        <td><input type="password" id="tjdb_password" name="tjdb_password" class="regular-text" value="<?php echo esc_attr($nivoda_settings['password'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button type="button" class="button" id="tjdb-test-connection"><?php esc_html_e('Test Connection', 'topjewellery-diamond-builder'); ?></button>
                            <span id="tjdb-test-connection-result"></span>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Eligible Products', 'topjewellery-diamond-builder'); ?></h2>
                <p class="description"><?php esc_html_e('Products in any of these categories or tags get a "Build with a Diamond" button and can be used as a setting. Customers still browse them via normal WooCommerce archives.', 'topjewellery-diamond-builder'); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Categories', 'topjewellery-diamond-builder'); ?></th>
                        <td>
                            <select name="tjdb_eligible_categories[]" multiple style="width:100%; height:140px;">
                                <?php foreach ($categories as $term) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array($term->term_id, $eligible_categories, true)); ?>>
                                        <?php echo esc_html($term->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Tags', 'topjewellery-diamond-builder'); ?></th>
                        <td>
                            <select name="tjdb_eligible_tags[]" multiple style="width:100%; height:140px;">
                                <?php foreach ($tags as $term) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array($term->term_id, $eligible_tags, true)); ?>>
                                        <?php echo esc_html($term->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tjdb_builder_page_id"><?php esc_html_e('Builder page', 'topjewellery-diamond-builder'); ?></label></th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'name' => 'tjdb_builder_page_id',
                                'id' => 'tjdb_builder_page_id',
                                'selected' => $builder_page_id,
                                'show_option_none' => __('— Select the page with [tjdb_builder] —', 'topjewellery-diamond-builder'),
                            ]);
                            ?>
                            <p class="description">
                                <?php esc_html_e('The page containing the [tjdb_builder] shortcode. "Choose This Setting" buttons link here. Auto-created on activation.', 'topjewellery-diamond-builder'); ?>
                                <?php if ($builder_page_id && get_permalink($builder_page_id)) : ?>
                                    — <a href="<?php echo esc_url(get_permalink($builder_page_id)); ?>" target="_blank"><?php esc_html_e('View', 'topjewellery-diamond-builder'); ?></a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Settings archive page', 'topjewellery-diamond-builder'); ?></th>
                        <td>
                            <?php $archive_page_id = get_option('tjdb_archive_page_id', 0); ?>
                            <p class="description">
                                <?php esc_html_e('Auto-generated page listing every eligible setting, via [tjdb_settings_archive]. Add it to your menu.', 'topjewellery-diamond-builder'); ?>
                                <?php if ($archive_page_id && get_permalink($archive_page_id)) : ?>
                                    — <a href="<?php echo esc_url(get_permalink($archive_page_id)); ?>" target="_blank"><?php esc_html_e('View', 'topjewellery-diamond-builder'); ?></a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Margin Tiers', 'topjewellery-diamond-builder'); ?></h2>
                <p class="description"><?php esc_html_e('Margin applied on top of Nivoda\'s raw cost, by carat bracket. Leave "to" empty on the last row for "and above". Never shown to customers.', 'topjewellery-diamond-builder'); ?></p>

                <table class="widefat" id="tjdb-margin-tiers-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Carat from', 'topjewellery-diamond-builder'); ?></th>
                            <th><?php esc_html_e('Carat to', 'topjewellery-diamond-builder'); ?></th>
                            <th><?php esc_html_e('Margin %', 'topjewellery-diamond-builder'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tiers as $tier) : ?>
                            <tr class="tjdb-tier-row">
                                <td><input type="number" step="0.01" min="0" name="tier_carat_from[]" value="<?php echo esc_attr($tier['carat_from']); ?>"></td>
                                <td><input type="number" step="0.01" min="0" name="tier_carat_to[]" value="<?php echo esc_attr($tier['carat_to'] ?? ''); ?>"></td>
                                <td><input type="number" step="0.01" min="0" name="tier_margin_percent[]" value="<?php echo esc_attr($tier['margin_percent']); ?>"></td>
                                <td><button type="button" class="button tjdb-remove-tier" aria-label="<?php esc_attr_e('Remove tier', 'topjewellery-diamond-builder'); ?>"><?php echo \TopJewelleryDiamondBuilder\Frontend\Stepper::lucide_icon('x', 'tjdb-admin-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="tjdb-add-tier"><?php esc_html_e('Add Row', 'topjewellery-diamond-builder'); ?></button></p>

                <template id="tjdb-tier-row-template">
                    <tr class="tjdb-tier-row">
                        <td><input type="number" step="0.01" min="0" name="tier_carat_from[]" value=""></td>
                        <td><input type="number" step="0.01" min="0" name="tier_carat_to[]" value=""></td>
                        <td><input type="number" step="0.01" min="0" name="tier_margin_percent[]" value=""></td>
                        <td><button type="button" class="button tjdb-remove-tier" aria-label="<?php esc_attr_e('Remove tier', 'topjewellery-diamond-builder'); ?>"><?php echo \TopJewelleryDiamondBuilder\Frontend\Stepper::lucide_icon('x', 'tjdb-admin-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></td>
                    </tr>
                </template>

                <table class="form-table">
                    <tr>
                        <th><label for="tjdb_default_margin_percent"><?php esc_html_e('Default margin % (fallback)', 'topjewellery-diamond-builder'); ?></label></th>
                        <td><input type="number" step="0.01" min="0" id="tjdb_default_margin_percent" name="tjdb_default_margin_percent" value="<?php echo esc_attr($default_percent); ?>"></td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'topjewellery-diamond-builder')); ?>
            </form>
        </div>
<?php
    }
}
