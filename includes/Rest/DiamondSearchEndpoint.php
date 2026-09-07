<?php

namespace TopJewelleryDiamondBuilder\Rest;

use TopJewelleryDiamondBuilder\Nivoda\NivodaClient;
use TopJewelleryDiamondBuilder\Nivoda\DiamondQueryBuilder;
use TopJewelleryDiamondBuilder\Pricing\MarginCalculator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

class DiamondSearchEndpoint
{
    private NivodaClient $client;
    private DiamondQueryBuilder $query_builder;
    private MarginCalculator $margin_calculator;

    public function __construct(MarginCalculator $margin_calculator)
    {
        $this->client = new NivodaClient();
        $this->query_builder = new DiamondQueryBuilder();
        $this->margin_calculator = $margin_calculator;
    }

    public function handle(WP_REST_Request $request)
    {
        if (! RateLimiter::allow('search')) {
            return new WP_Error('tjdb_rate_limited', 'Too many requests, please slow down.', ['status' => 429]);
        }

        $params = $request->get_json_params() ?: [];
        $limit = min(50, max(1, (int) ($params['limit'] ?? 20)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $variables = [
            'limit' => $limit,
            'offset' => $offset,
            'query' => $this->query_builder->build_query_variables($params),
        ];

        // Sending an explicit `order: null` (rather than omitting the
        // variable) crashes Nivoda's resolver server-side, so only add it
        // when there's an actual sort to apply.
        $order = $this->query_builder->build_order_variables($params);
        if ($order !== null) {
            $variables['order'] = $order;
        }

        // Cache the raw (pre-margin) Nivoda result for an hour — this is a
        // browsing/listing endpoint, not the add-to-cart price authority
        // (that's DiamondDetailEndpoint::fetch(), which stays live/uncached).
        // Margin is applied fresh below on every request, cached or not, so
        // a margin tier change takes effect immediately without waiting for
        // this cache to expire.
        $cache_key = 'tjdb_search_' . md5(wp_json_encode($variables));
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            $items = $cached['items'];
            $total = $cached['total'];
        } else {
            try {
                $data = $this->client->query($this->query_builder->search_query(), $variables);
            } catch (\RuntimeException $e) {
                return new WP_Error('tjdb_nivoda_error', 'Unable to search diamonds right now.', ['status' => 502]);
            }

            // NivodaClient only throws on a transport/GraphQL-error failure;
            // a 200 response missing the expected shape (e.g. a truncated or
            // otherwise malformed payload) wouldn't trigger that, but caching
            // it would hide real results behind a bogus "0 diamonds" for an
            // hour. Only a genuine, well-formed response gets cached.
            if (! isset($data['diamonds_by_query']) || ! is_array($data['diamonds_by_query'])) {
                return new WP_Error('tjdb_nivoda_error', 'Unable to search diamonds right now.', ['status' => 502]);
            }

            $items = $data['diamonds_by_query']['items'] ?? [];
            $total = $data['diamonds_by_query']['total_count'] ?? 0;

            set_transient($cache_key, ['items' => $items, 'total' => $total], HOUR_IN_SECONDS);
        }

        $results = array_map([$this, 'to_safe_dto'], $items);

        return new WP_REST_Response([
            'items' => $results,
            'total_count' => $total,
        ]);
    }

    /**
     * Strips every raw-cost field and returns only the customer-safe view.
     * @param array<string, mixed> $item
     */
    private function to_safe_dto(array $item): array
    {
        $cert = $item['diamond']['certificate'] ?? [];
        $carats = (float) ($cert['carats'] ?? 0);
        $cost_cents = (int) ($item['price'] ?? 0);

        $priced = $this->margin_calculator->apply($cost_cents, $carats);

        // The interactive 360° spin viewer is a distinct URL from the
        // regular `video` field (loupe360's own "type=360" variant, vs
        // "type=api" for the fixed replay) — confirmed via a live query,
        // not assumed. The modal opens straight off this search result
        // (no separate detail fetch), so these need to be here too, not
        // just in DiamondDetailEndpoint.
        $spin_url = $cert['product_videos'][0]['loupe360_url'] ?? null;
        $main_image = $item['diamond']['image'] ?? null;
        $extra_images = array_values(array_filter(array_map(
            fn (array $media) => $media['url'] ?? null,
            $cert['product_images'] ?? []
        ), fn ($url) => $url && ! self::is_same_image($url, $main_image)));

        return [
            'diamond_id' => $item['id'] ?? '',
            'price_cents' => $priced['customer_price_cents'],
            'availability' => $item['diamond']['availability'] ?? 'UNKNOWN',
            'image' => $item['diamond']['image'] ?? null,
            'video' => $item['diamond']['video'] ?? null,
            'spin_url' => $spin_url,
            'bowtie' => $item['diamond']['bowtie'] ?? null,
            'extra_images' => $extra_images,
            'supplier' => $item['diamond']['supplier']['name'] ?? null,
            'certificate' => [
                'shape' => $cert['shape'] ?? null,
                'carats' => $carats,
                'color' => $cert['color'] ?? null,
                'clarity' => $cert['clarity'] ?? null,
                'cut' => $cert['cut'] ?? null,
                'lab' => $cert['lab'] ?? null,
                'cert_number' => $cert['certNumber'] ?? null,
                // Prefer the masked (watermarked) PDF — the raw one can
                // reveal supplier/stock details that let a customer shop
                // the same stone directly with the supplier, bypassing us.
                'cert_pdf_url' => $cert['masked_pdf_url'] ?? $cert['pdfUrl'] ?? null,
                'polish' => $cert['polish'] ?? null,
                'symmetry' => $cert['symmetry'] ?? null,
                'length' => $cert['length'] ?? null,
                'width' => $cert['width'] ?? null,
                'depth' => $cert['depth'] ?? null,
                'table' => $cert['table'] ?? null,
                'depth_percentage' => $cert['depthPercentage'] ?? null,
            ],
        ];
    }

    /**
     * Nivoda's `diamond.image` and `certificate.product_images[]` both
     * point at the same S3 object for the primary photo — `image` just has
     * tracking query params (`?d_id=&c_id=&f_id=&type=api`) tacked on that
     * `product_images[].url` doesn't, so a straight string compare misses
     * the duplicate. Comparing host+path (ignoring the query string) is
     * what actually distinguishes "same photo" from "a genuinely different
     * angle" here.
     */
    private static function is_same_image(string $a, ?string $b): bool
    {
        if (! $b) {
            return false;
        }

        return wp_parse_url($a, PHP_URL_HOST) . wp_parse_url($a, PHP_URL_PATH)
            === wp_parse_url($b, PHP_URL_HOST) . wp_parse_url($b, PHP_URL_PATH);
    }
}
