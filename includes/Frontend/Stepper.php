<?php

namespace TopJewelleryDiamondBuilder\Frontend;

if (! defined('ABSPATH')) {
    exit;
}

class Stepper
{
    /**
     * Lucide Icons, ISC license: https://lucide.dev/license
     */
    public static function lucide_icon(string $name, string $class = 'tjdb-icon'): string
    {
        $paths = [
            'gem' => '<path d="M10.5 3 8 9l4 13 4-13-2.5-6" /><path d="M17 3a2 2 0 0 1 1.6.8l3 4a2 2 0 0 1 .013 2.382l-7.99 10.986a2 2 0 0 1-3.247 0l-7.99-10.986A2 2 0 0 1 2.4 7.8l2.998-3.997A2 2 0 0 1 7 3z" /><path d="M2 9h20" />',
            'check' => '<path d="M20 6 9 17l-5-5" />',
            'x' => '<path d="M18 6 6 18" /><path d="m6 6 12 12" />',
        ];

        $path = $paths[$name] ?? $paths['gem'];

        return '<svg class="' . esc_attr($class) . ' lucide lucide-' . esc_attr($name) . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    /**
     * @param array<int, array{
     *     key: string,
     *     label: string,
     *     current?: bool,
     *     completed?: bool,
     *     href?: ?string,
     *     detail?: ?string,
     *     image?: ?string,
     *     action_label?: ?string
     * }> $steps
     */
    public static function render(array $steps, string $extra_class = 'tjdb-stepper-preview'): string
    {
        ob_start();
?>
        <nav class="tjdb-stepper <?php echo esc_attr($extra_class); ?>" aria-label="<?php esc_attr_e('Design Your Ring', 'topjewellery-diamond-builder'); ?>">
            <span class="tjdb-stepper-segment tjdb-stepper-intro">
                <span class="tjdb-stepper-segment-content">Design Your Ring</span>
            </span>
            <?php foreach ($steps as $index => $step) : ?>
                <?php
                $href = $step['href'] ?? null;
                $tag = $href ? 'a' : 'span';
                $classes = 'tjdb-stepper-segment';
                $classes .= ! empty($step['current']) ? ' is-current' : '';
                $classes .= ! empty($step['completed']) ? ' is-completed' : '';
                $classes .= $href ? ' is-clickable' : '';
                ?>
                <<?php echo $tag; ?> class="<?php echo esc_attr($classes); ?>" data-step-key="<?php echo esc_attr($step['key']); ?>"<?php echo $href ? ' href="' . esc_url($href) . '"' : ''; ?>>
                        <span class="tjdb-stepper-segment-content">
                            <span class="tjdb-stepper-circle">
                                <?php echo ! empty($step['completed']) && empty($step['current']) ? self::lucide_icon('check', 'tjdb-stepper-check') : esc_html((string) ($index + 1)); ?>
                            </span>
                            <span class="tjdb-stepper-text">
                                <span class="tjdb-stepper-kicker"><?php echo esc_html((string) ($index + 1)); ?></span>
                                <span class="tjdb-stepper-label"><?php echo esc_html($step['label']); ?></span>
                                <?php if (! empty($step['detail'])) : ?>
                                    <span class="tjdb-stepper-detail"><?php echo esc_html($step['detail']); ?></span>
                                <?php endif; ?>
                                <?php if (! empty($step['action_label'])) : ?>
                                    <span class="tjdb-stepper-change"><?php echo esc_html($step['action_label']); ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if (! empty($step['image'])) : ?>
                                <img class="tjdb-stepper-thumb" src="<?php echo esc_url($step['image']); ?>" alt="">
                            <?php else : ?>
                                <?php echo self::lucide_icon('gem', 'tjdb-stepper-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                        </span>
                </<?php echo $tag; ?>>
            <?php endforeach; ?>
        </nav>
<?php
        return (string) ob_get_clean();
    }
}
