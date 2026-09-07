<?php

namespace TopJewelleryDiamondBuilder\Pricing;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for turning a Nivoda cost price into what the
 * customer is charged. Called from the search endpoint (display), the
 * detail endpoint (re-validation display), and the cart-add endpoint
 * (actual charge) so displayed and charged prices can never drift.
 */
class MarginCalculator
{
    /**
     * @param int   $cost_price_cents Raw Nivoda cost, in cents. Never sent to the browser.
     * @param float $carats           Diamond carat weight, used to pick the tier.
     * @return array{customer_price_cents:int,margin_percent:float,cost_price_cents:int}
     */
    public function apply(int $cost_price_cents, float $carats): array
    {
        $margin_percent = $this->get_margin_percent_for_carats($carats);

        $customer_price_cents = (int) round($cost_price_cents * (1 + ($margin_percent / 100)));

        return [
            'customer_price_cents' => $customer_price_cents,
            'margin_percent' => $margin_percent,
            'cost_price_cents' => $cost_price_cents,
        ];
    }

    public function get_margin_percent_for_carats(float $carats): float
    {
        $tiers = $this->get_tiers();

        foreach ($tiers as $tier) {
            $from = (float) $tier['carat_from'];
            $to = $tier['carat_to'];

            if ($carats < $from) {
                continue;
            }

            if ($to === null || $to === '' || $carats < (float) $to) {
                return (float) $tier['margin_percent'];
            }
        }

        return (float) get_option('tjdb_margin_default_percent', 25.0);
    }

    /**
     * @return array<int, array{carat_from:float,carat_to:?float,margin_percent:float}>
     */
    public function get_tiers(): array
    {
        $tiers = get_option('tjdb_margin_tiers', []);

        if (! is_array($tiers)) {
            return [];
        }

        usort($tiers, function ($a, $b) {
            return $a['carat_from'] <=> $b['carat_from'];
        });

        return $tiers;
    }
}
