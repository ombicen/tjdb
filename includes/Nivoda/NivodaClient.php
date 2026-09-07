<?php

namespace TopJewelleryDiamondBuilder\Nivoda;

if (! defined('ABSPATH')) {
    exit;
}

class NivodaClient
{
    private NivodaAuth $auth;

    public function __construct(?NivodaAuth $auth = null)
    {
        $this->auth = $auth ?? new NivodaAuth();
    }

    /**
     * Runs a GraphQL query against Nivoda, retrying once on auth expiry.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed> The `data` payload.
     * @throws \RuntimeException
     */
    public function query(string $query, array $variables = [], bool $is_retry = false): array
    {
        $settings = get_option('tjdb_nivoda_settings', []);
        $endpoint = $settings['endpoint'] ?? '';

        if (! $endpoint) {
            throw new \RuntimeException('Nivoda endpoint is not configured.');
        }

        $token = $this->auth->get_token();

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode([
                'query' => $query,
                'variables' => $variables,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Nivoda request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401 && ! $is_retry) {
            $this->auth->invalidate();
            return $this->query($query, $variables, true);
        }

        if ($code !== 200 || ! is_array($body)) {
            throw new \RuntimeException('Nivoda request returned HTTP ' . $code);
        }

        if (! empty($body['errors'])) {
            $message = is_array($body['errors'][0] ?? null) && isset($body['errors'][0]['message'])
                ? $body['errors'][0]['message']
                : 'Unknown Nivoda GraphQL error.';
            throw new \RuntimeException('Nivoda GraphQL error: ' . $message);
        }

        return $body['data'] ?? [];
    }
}
