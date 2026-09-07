<?php

namespace TopJewelleryDiamondBuilder\Nivoda;

if (! defined('ABSPATH')) {
    exit;
}

class NivodaAuth
{
    private const TRANSIENT_KEY = 'tjdb_nivoda_token';
    private const TOKEN_TTL = 5 * HOUR_IN_SECONDS; // real token lives 6h, cache under that

    /**
     * @throws \RuntimeException
     */
    public function get_token(bool $force = false): string
    {
        if (! $force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $token = $this->fetch_token();
        set_transient(self::TRANSIENT_KEY, $token, self::TOKEN_TTL);

        return $token;
    }

    public function invalidate(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @throws \RuntimeException
     */
    private function fetch_token(): string
    {
        $settings = get_option('tjdb_nivoda_settings', []);
        $endpoint = $settings['endpoint'] ?? '';
        $username = $settings['username'] ?? '';
        $password = $settings['password'] ?? '';

        if (! $endpoint || ! $username || ! $password) {
            throw new \RuntimeException('Nivoda credentials are not configured.');
        }

        $query = <<<'GQL'
            query Authenticate($username: String!, $password: String!) {
              authenticate {
                username_and_password(username: $username, password: $password) {
                  token
                }
              }
            }
            GQL;

        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'query' => $query,
                'variables' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Nivoda auth request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || ! is_array($body)) {
            throw new \RuntimeException('Nivoda auth request returned HTTP ' . $code);
        }

        if (! empty($body['errors'])) {
            throw new \RuntimeException('Nivoda auth returned GraphQL errors.');
        }

        $token = $body['data']['authenticate']['username_and_password']['token'] ?? null;

        if (! $token) {
            throw new \RuntimeException('Nivoda auth response did not contain a token.');
        }

        return $token;
    }
}
