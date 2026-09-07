<?php

namespace TopJewelleryDiamondBuilder\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class OrderDisplay
{
    public function init(): void
    {
        add_action('woocommerce_admin_order_item_values', [$this, 'render_item_diamond_panel'], 10, 3);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_order_banner']);
    }

    /**
     * @param \WC_Product|false $product
     * @param \WC_Order_Item $item
     */
    public function render_item_diamond_panel($product, $item, int $item_id): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $diamond_id = $item->get_meta('_tjdb_nivoda_diamond_id');

        if (! $diamond_id) {
            return;
        }

        $cert_number = $item->get_meta('Certificate #');
        $carat = $item->get_meta('Carat');
        $color = $item->get_meta('Color');
        $clarity = $item->get_meta('Clarity');
        $cut = $item->get_meta('Cut');

        $cost = (int) $item->get_meta('_tjdb_cost_price_cents') / 100;
        $margin = (float) $item->get_meta('_tjdb_margin_percent');
        $charged = (int) $item->get_meta('_tjdb_diamond_price_cents') / 100;

?>
        <div class="tjdb-order-item-diamond" style="margin-top:8px; padding-top:8px; border-top:1px dashed #ccc;">
            <strong><?php esc_html_e('Nivoda Diamond ID:', 'topjewellery-diamond-builder'); ?></strong>
            <code onclick="navigator.clipboard.writeText(this.textContent)" title="<?php esc_attr_e('Click to copy', 'topjewellery-diamond-builder'); ?>" style="cursor:pointer;"><?php echo esc_html($diamond_id); ?></code>
            <br>
            <small>
                <?php echo esc_html(sprintf('%sct %s/%s %s cut &middot; Cert #%s', $carat, $color, $clarity, $cut, $cert_number)); ?>
            </small>
            <details style="margin-top:4px;">
                <summary style="cursor:pointer;"><?php esc_html_e('Cost / margin (admin only)', 'topjewellery-diamond-builder'); ?></summary>
                <table style="font-size:12px;">
                    <tr><td><?php esc_html_e('Cost:', 'topjewellery-diamond-builder'); ?></td><td><?php echo wc_price($cost); ?></td></tr>
                    <tr><td><?php esc_html_e('Margin:', 'topjewellery-diamond-builder'); ?></td><td><?php echo esc_html($margin); ?>%</td></tr>
                    <tr><td><?php esc_html_e('Charged:', 'topjewellery-diamond-builder'); ?></td><td><?php echo wc_price($charged); ?></td></tr>
                </table>
            </details>
        </div>
<?php
    }

    public function render_order_banner(\WC_Order $order): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $diamond_ids = [];

        foreach ($order->get_items() as $item) {
            $id = $item->get_meta('_tjdb_nivoda_diamond_id');
            if ($id) {
                $diamond_ids[] = $id;
            }
        }

        if (empty($diamond_ids)) {
            return;
        }

?>
        <div class="tjdb-order-banner notice notice-info inline" style="margin:12px 0; padding:8px 12px;">
            <strong><?php esc_html_e('Diamonds to fulfil via Nivoda:', 'topjewellery-diamond-builder'); ?></strong>
            <ul style="margin:4px 0 0 18px;">
                <?php foreach ($diamond_ids as $id) : ?>
                    <li><code><?php echo esc_html($id); ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
<?php
    }
}
