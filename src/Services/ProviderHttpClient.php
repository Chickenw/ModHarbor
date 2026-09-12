<?php

namespace GameNest\GameNestModManager\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Fixed upstream API requests. Never propagate response bodies, keys or request exceptions. */
class ProviderHttpClient
{
    public function send(string $method, string $url, array $parameters = [], array $headers = []): array
    {
        try {
            $request = Http::withHeaders($headers + ['Accept' => 'application/json', 'User-Agent' => 'ModHarbor/1.0.0-rc.3'])
                ->timeout(30)->connectTimeout(10)->withOptions(['allow_redirects' => false]);
            $response = $method === 'POST'
                ? $request->post($url, $parameters)
                : $request->get($url, $parameters);
            // Bound metadata parsing. Package bytes use SafeRemoteDownloader separately.
            $body = $response->body();
            if (strlen($body) > 8388608) { throw new RuntimeException('Oversized response.'); }
            return ['status' => $response->status(), 'body' => $body];
        } catch (Throwable) {
            throw new RuntimeException('Provider request failed. Check connectivity and provider configuration.');
        }
    }
}
