<?php

namespace TopJewelleryDiamondBuilder\Nivoda;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps flat REST filter args to a Nivoda DiamondQuery input, and holds
 * the shared GraphQL query/field selections used by search + detail.
 */
class DiamondQueryBuilder
{
    public const ALLOWED_SHAPES = [
        'ROUND', 'PRINCESS', 'CUSHION', 'EMERALD', 'OVAL',
        'PEAR', 'MARQUISE', 'RADIANT', 'ASSCHER', 'HEART',
    ];

    public const ALLOWED_COLORS = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'];
    public const ALLOWED_CLARITIES = ['FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3'];
    public const ALLOWED_CUTS = ['ID', 'EX', 'VG', 'GD', 'FR', 'PR'];
    public const ALLOWED_SORT_TYPES = ['popular', 'price', 'size', 'color', 'clarity', 'cut', 'price_per_carat', 'createdAt'];

    /**
     * @param array<string, mixed> $args Flat REST args: shapes, carat_from, carat_to,
     *                                    color, clarity, cut, labgrown.
     * @return array<string, mixed> DiamondQuery variables.
     */
    public function build_query_variables(array $args): array
    {
        $query = [];

        if (! empty($args['shapes']) && is_array($args['shapes'])) {
            $query['shapes'] = array_values(array_intersect(
                array_map('strtoupper', $args['shapes']),
                self::ALLOWED_SHAPES
            ));
        }

        if (isset($args['carat_from']) || isset($args['carat_to'])) {
            $query['sizes'] = [[
                'from' => isset($args['carat_from']) ? (float) $args['carat_from'] : 0.0,
                'to' => isset($args['carat_to']) ? (float) $args['carat_to'] : 50.0,
            ]];
        }

        foreach (['color', 'clarity', 'cut', 'polish', 'symmetry'] as $enum_field) {
            if (! empty($args[$enum_field]) && is_array($args[$enum_field])) {
                $query[$enum_field] = array_map('strtoupper', $args[$enum_field]);
            }
        }

        $query['labgrown'] = ! empty($args['labgrown']);

        return $query;
    }

    /**
     * @param array<string, mixed> $args Flat REST args: sort_type, sort_direction.
     * @return array<string, string>|null DiamondOrder variables, or null for Nivoda's default order.
     */
    public function build_order_variables(array $args): ?array
    {
        $type = strtolower((string) ($args['sort_type'] ?? ''));

        if (! in_array($type, self::ALLOWED_SORT_TYPES, true)) {
            return null;
        }

        $direction = strtoupper((string) ($args['sort_direction'] ?? 'ASC'));
        $direction = in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC';

        return ['type' => $type, 'direction' => $direction];
    }

    public function item_fields(): string
    {
        // v360/bowtie/product_images/product_videos confirmed to exist via
        // live schema introspection (not guessed) — see DiamondDetailEndpoint
        // for how they're mapped into the safe customer-facing DTO.
        return <<<'GQL'
            id
            price
            diamond_price
            markup_price
            discount
            markup_discount
            diamond {
              id
              image
              video
              bowtie
              v360 { url }
              availability
              supplier { name }
              certificate {
                shape
                carats
                color
                clarity
                cut
                lab
                certNumber
                polish
                symmetry
                length
                width
                depth
                table
                depthPercentage
                floInt
                pdfUrl
                masked_pdf_url
                product_images { url }
                product_videos { url loupe360_url }
              }
            }
            GQL;
    }

    public function search_query(): string
    {
        $fields = $this->item_fields();

        return <<<GQL
            query Search(\$limit: Int, \$offset: Int, \$query: DiamondQuery, \$order: DiamondOrder) {
              diamonds_by_query(limit: \$limit, offset: \$offset, query: \$query, order: \$order) {
                items {
                  {$fields}
                }
                total_count
              }
            }
            GQL;
    }

    public function detail_query(): string
    {
        return <<<'GQL'
            query Detail($diamondId: ID!) {
              get_diamond_by_id(diamond_id: $diamondId) {
                id
                price
                diamond_price
                markup_price
                discount
                markup_discount
                diamond {
                  id
                  image
                  video
                  bowtie
                  v360 { url }
                  availability
                  supplier { name }
                  certificate {
                    shape
                    carats
                    color
                    clarity
                    cut
                    lab
                    certNumber
                    polish
                    symmetry
                    length
                    width
                    depth
                    table
                    depthPercentage
                    floInt
                    pdfUrl
                    masked_pdf_url
                    product_images { url }
                    product_videos { url loupe360_url }
                  }
                }
              }
            }
            GQL;
    }
}
