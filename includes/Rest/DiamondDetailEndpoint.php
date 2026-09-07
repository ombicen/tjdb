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

class DiamondDetailEndpoint
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
        if (! RateLimiter::allow('detail')) {
            return new WP_Error('tjdb_rate_limited', 'Too many requests, please slow down.', ['status' => 429]);
        }

        $params = $request->get_json_params() ?: [];
        $diamond_id = sanitize_text_field($params['diamond_id'] ?? '');

        if (! $diamond_id) {
            return new WP_Error('tjdb_missing_diamond_id', 'diamond_id is required.', ['status' => 400]);
        }

        try {
            $result = $this->fetch($diamond_id);
        } catch (\RuntimeException $e) {
            return new WP_Error('tjdb_nivoda_error', 'Unable to fetch this diamond right now.', ['status' => 502]);
        }

        if ($result === null) {
            return new WP_Error('tjdb_diamond_not_found', 'Diamond not found.', ['status' => 404]);
        }

        return new WP_REST_Response($result);
    }

    /**
     * Fetches a single diamond, from a short-lived server-side cache when
     * available or live from Nivoda otherwise, then caches the result. Used
     * both by the public REST detail endpoint and by CartEndpoint just
     * before add-to-cart — so the cached Nivoda response, not anything the
     * client sends, is always the source of truth for price/availability,
     * and the common "detail was just fetched when the Complete step
     * loaded, then Add to Cart is clicked moments later" path hits this
     * cache instead of a second live call to Nivoda's API.
     *
     * @return array<string, mixed>|null
     * @throws \RuntimeException
     */
    public function fetch(string $diamond_id): ?array
    {
        $cache_key = 'tjdb_diamond_' . md5($diamond_id);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        // get_diamond_by_id takes a bare UUID; diamonds_by_query (and every
        // other surface of this plugin, including what's shown to admins for
        // Nivoda fulfillment) uses the "DIAMOND/<uuid>" form as the canonical id.
        $bare_id = preg_replace('#^DIAMOND/#i', '', $diamond_id);

        $data = $this->client->query($this->query_builder->detail_query(), [
            'diamondId' => $bare_id,
        ]);

        $item = $data['get_diamond_by_id'] ?? null;

        if (! $item) {
            // Not cached — a transient "not found" shouldn't stick around
            // and block a retry once Nivoda's inventory catches up.
            return null;
        }

        $cert = $item['diamond']['certificate'] ?? [];
        $carats = (float) ($cert['carats'] ?? 0);
        $cost_cents = (int) ($item['price'] ?? 0);

        $priced = $this->margin_calculator->apply($cost_cents, $carats);

        // The interactive 360° spin viewer is a distinct URL from the
        // regular `video` field (loupe360's own "type=360" variant, vs
        // "type=api" for the fixed replay) — confirmed via a live query,
        // not assumed. `v360.url` on Diamond is the same S3 resource as
        // `image` for stones observed so far, so the spin control is
        // sourced from certificate.product_videos instead.
        $spin_url = $cert['product_videos'][0]['loupe360_url'] ?? null;
        $main_image = $item['diamond']['image'] ?? null;
        $extra_images = array_values(array_filter(array_map(
            fn (array $media) => $media['url'] ?? null,
            $cert['product_images'] ?? []
        ), fn ($url) => $url && ! self::is_same_image($url, $main_image)));

        $result = [
            // Always the canonical "DIAMOND/<uuid>" form, regardless of
            // whatever format get_diamond_by_id happens to echo back.
            'diamond_id' => $diamond_id,
            'price_cents' => $priced['customer_price_cents'],
            'cost_price_cents' => $priced['cost_price_cents'],
            'margin_percent' => $priced['margin_percent'],
            'availability' => $item['diamond']['availability'] ?? 'UNKNOWN',
            'image' => $item['diamond']['image'] ?? null,
            'video' => $item['diamond']['video'] ?? null,
            'spin_url' => $spin_url,
            'bowtie' => $item['diamond']['bowtie'] ?? null,
            'extra_images' => $extra_images,
            'certificate' => [
                'shape' => $cert['shape'] ?? null,
                'carats' => $carats,
                'color' => $cert['color'] ?? null,
                'clarity' => $cert['clarity'] ?? null,
                'cut' => $cert['cut'] ?? null,
                'lab' => $cert['lab'] ?? null,
                'cert_number' => $cert['certNumber'] ?? null,
                'cert_pdf_url' => $cert['masked_pdf_url'] ?? $cert['pdfUrl'] ?? null,
                'polish' => $cert['polish'] ?? null,
                'symmetry' => $cert['symmetry'] ?? null,
                'length' => $cert['length'] ?? null,
                'width' => $cert['width'] ?? null,
                'depth' => $cert['depth'] ?? null,
                'table' => $cert['table'] ?? null,
                'depth_percentage' => $cert['depthPercentage'] ?? null,
            ],
            'certificate_full' => $cert,
        ];

        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);

        return $result;
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
