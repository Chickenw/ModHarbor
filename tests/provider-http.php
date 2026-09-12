<?php

namespace Illuminate\Support\Facades {
    class Http {
        public static array $options = [];
        public static array $headers = [];
        public static string $mode = 'ok';
        public static function withHeaders($headers) { self::$headers = $headers; return new self; }
        public function timeout($seconds) { self::$options['timeout'] = $seconds; return $this; }
        public function connectTimeout($seconds) { self::$options['connect'] = $seconds; return $this; }
        public function withOptions($options) { self::$options += $options; return $this; }
        public function get($url, $parameters) {
            if (self::$mode === 'failure') { throw new \RuntimeException('PRIVATE key in URL ' . $url); }
            return $this;
        }
        public function post($url, $parameters) { return $this->get($url, $parameters); }
        public function body() { return self::$mode === 'oversized' ? str_repeat('x', 8388609) : '{"ok":true}'; }
        public function status() { return 200; }
    }
}
namespace {
    require __DIR__ . '/../src/Services/ProviderHttpClient.php';
    $client = new \GameNest\GameNestModManager\Services\ProviderHttpClient;
    $client->send('GET', 'https://provider.invalid/?key=PRIVATE');
    $options = \Illuminate\Support\Facades\Http::$options;
    if (($options['allow_redirects'] ?? true) !== false || $options['timeout'] !== 30 || $options['connect'] !== 10) { throw new RuntimeException('Unsafe HTTP options'); }
    foreach (['failure', 'oversized'] as $mode) {
        \Illuminate\Support\Facades\Http::$mode = $mode;
        try { $client->send('GET', 'https://provider.invalid/?key=PRIVATE'); throw new LogicException('Missing rejection'); }
        catch (RuntimeException $e) {
            if (str_contains((string) $e, 'PRIVATE') || $e->getPrevious() !== null) { throw new LogicException('Request details leaked'); }
        }
    }
    echo "Provider HTTP timeout, redirect, response-size and secret-safety checks passed.\n";
}
