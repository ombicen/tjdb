<?php

namespace TopJewelleryDiamondBuilder\Admin;

use TopJewelleryDiamondBuilder\Nivoda\DiamondQueryBuilder;
use TopJewelleryDiamondBuilder\Product\Eligibility;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Whether the diamond builder applies to a product is decided by category/tag
 * membership (see Product\Eligibility + the Eligible Categories/Tags setting).
 * This metabox only configures the per-product carat/shape constraints used
 * once a product already qualifies.
 */
class ProductMetabox
{
    private Eligibility $eligibility;

    public function __construct(?Eligibility $eligibility = null)
    {
        $this->eligibility = $eligibility ?? new Eligibility();
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post_product', [$this, 'save']);
    }

    public function add_metabox(): void
    {
        add_meta_box(
            'tjdb_diamond_compatible',
            __('Diamond Builder', 'topjewellery-diamond-builder'),
            [$this, 'render'],
            'product',
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('tjdb_save_product_metabox', 'tjdb_product_metabox_nonce');

        $min_carat = get_post_meta($post->ID, '_tjdb_min_carat', true);
        $max_carat = get_post_meta($post->ID, '_tjdb_max_carat', true);
        $allowed_shapes = get_post_meta($post->ID, '_tjdb_allowed_shapes', true);
        $allowed_shapes = is_array($allowed_shapes) ? $allowed_shapes : [];

        $product = wc_get_product($post->ID);
        $eligible = $product && $this->eligibility->is_eligible($product);

?>
        <style>.tjdb-admin-icon{width:16px;height:16px;vertical-align:-3px;stroke-width:2}</style>
        <p>
            <?php if ($eligible) : ?>
                <span style="color:#2a8;"><?php echo \TopJewelleryDiamondBuilder\Frontend\Stepper::lucide_icon('check', 'tjdb-admin-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e('Eligible for the diamond builder (via category/tag).', 'topjewellery-diamond-builder'); ?></span>
            <?php else : ?>
                <span style="color:#888;"><?php esc_html_e('Not eligible — assign a category/tag configured under WooCommerce → Diamond Builder.', 'topjewellery-diamond-builder'); ?></span>
            <?php endif; ?>
        </p>
        <p>
            <label for="tjdb_min_carat"><?php esc_html_e('Min carat', 'topjewellery-diamond-builder'); ?></label><br>
            <input type="number" step="0.01" min="0" id="tjdb_min_carat" name="tjdb_min_carat" value="<?php echo esc_attr($min_carat); ?>" style="width:100%;">
        </p>
        <p>
            <label for="tjdb_max_carat"><?php esc_html_e('Max carat', 'topjewellery-diamond-builder'); ?></label><br>
            <input type="number" step="0.01" min="0" id="tjdb_max_carat" name="tjdb_max_carat" value="<?php echo esc_attr($max_carat); ?>" style="width:100%;">
        </p>
        <p>
            <label for="tjdb_allowed_shapes"><?php esc_html_e('Allowed shapes', 'topjewellery-diamond-builder'); ?></label><br>
            <select id="tjdb_allowed_shapes" name="tjdb_allowed_shapes[]" multiple style="width:100%; height:140px;">
                <?php foreach (DiamondQueryBuilder::ALLOWED_SHAPES as $shape) : ?>
                    <option value="<?php echo esc_attr($shape); ?>" <?php selected(in_array($shape, $allowed_shapes, true)); ?>>
                        <?php echo esc_html(ucfirst(strtolower($shape))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description"><?php esc_html_e('Leave empty to allow all shapes.', 'topjewellery-diamond-builder'); ?></span>
        </p>
<?php
    }

    public function save(int $post_id): void
    {
        if (! isset($_POST['tjdb_product_metabox_nonce']) || ! wp_verify_nonce($_POST['tjdb_product_metabox_nonce'], 'tjdb_save_product_metabox')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('edit_product', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_tjdb_min_carat', (float) ($_POST['tjdb_min_carat'] ?? 0));
        update_post_meta($post_id, '_tjdb_max_carat', (float) ($_POST['tjdb_max_carat'] ?? 0));

        $shapes = isset($_POST['tjdb_allowed_shapes']) && is_array($_POST['tjdb_allowed_shapes'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['tjdb_allowed_shapes']))
            : [];
        update_post_meta($post_id, '_tjdb_allowed_shapes', array_values(array_intersect($shapes, DiamondQueryBuilder::ALLOWED_SHAPES)));
    }
}
