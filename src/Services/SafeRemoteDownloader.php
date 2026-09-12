<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Providers\DirectDownloadProvider;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SafeRemoteDownloader
{
    protected const MAX_REDIRECTS = 4;

    public function __construct(
        protected DirectDownloadProvider $direct
    ) {}

    public function download(
        string $url,
        int $maxBytes
    ): string {
        if ($maxBytes < 1) {
            throw new RuntimeException(
                'Invalid remote download size limit.'
            );
        }

        $current = $this->direct->validateUrl($url);

        for ($redirects = 0; ; $redirects++) {
            if ($redirects > self::MAX_REDIRECTS) {
                throw new RuntimeException(
                    'Direct Download exceeded the allowed redirect limit.'
                );
            }

            /*
             * Validate DNS on every hop and pin cURL to one of the
             * addresses we actually validated. This prevents an allowed
             * hostname from being used as a redirect/DNS-rebinding path
             * into localhost or a private network.
             */
            $addresses =
                $this->direct->resolvePublicAddresses(
                    $current
                );

            $parts = parse_url($current);

            if (!is_array($parts)) {
                throw new RuntimeException(
                    'Direct Download URL could not be parsed safely.'
                );
            }

            $host = strtolower(
                trim((string) ($parts['host'] ?? ''))
            );

            $port = (int) ($parts['port'] ?? 443);

            $address =
                $this->preferredAddress($addresses);

            $curlAddress =
                str_contains($address, ':')
                    ? '[' . $address . ']'
                    : $address;

            $options = [
                'allow_redirects' => false,
                'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                    if ($total > $maxBytes || $downloaded > $maxBytes) { throw new RuntimeException('Download exceeds the package size limit.'); }
                },
            ];

            if (defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [
                    CURLOPT_RESOLVE => [
                        $host
                        . ':'
                        . $port
                        . ':'
                        . $curlAddress,
                    ],
                ];
            }

            try {
                $response = Http::timeout(60)
                    ->connectTimeout(10)
                    ->withOptions($options)
                    ->withHeaders([
                        'User-Agent' =>
                            'ModHarbor/1.0.0-rc.3 Direct Download',
                        'Accept' =>
                            'application/octet-stream,*/*',
                    ])
                    ->get($current);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Direct Download could not retrieve the remote file.'
                );
            }

            if (
                in_array(
                    $response->status(),
                    [301, 302, 303, 307, 308],
                    true
                )
            ) {
                $location = trim(
                    (string) $response->header('Location')
                );

                if ($location === '') {
                    throw new RuntimeException(
                        'Direct Download returned an invalid redirect.'
                    );
                }

                try {
                    $next = (string) UriResolver::resolve(
                        new Uri($current),
                        new Uri($location)
                    );
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        'Direct Download returned an invalid redirect.'
                    );
                }

                $current =
                    $this->direct->validateUrl($next);

                continue;
            }

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Direct Download returned HTTP '
                    . $response->status()
                    . '.'
                );
            }

            $contentLength = trim(
                (string) $response->header(
                    'Content-Length'
                )
            );

            if (
                $contentLength !== ''
                && ctype_digit($contentLength)
                && (int) $contentLength > $maxBytes
            ) {
                throw new RuntimeException(
                    'Direct Download exceeds the allowed size limit of '
                    . $this->megabytes($maxBytes)
                    . ' MB.'
                );
            }

            $content = $response->body();

            if ($content === '') {
                throw new RuntimeException(
                    'Direct Download returned an empty file.'
                );
            }

            if (strlen($content) > $maxBytes) {
                throw new RuntimeException(
                    'Direct Download exceeds the allowed size limit of '
                    . $this->megabytes($maxBytes)
                    . ' MB.'
                );
            }

            return $content;
        }
    }

    protected function preferredAddress(
        array $addresses
    ): string {
        foreach ($addresses as $address) {
            if (
                filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                )
            ) {
                return $address;
            }
        }

        $address = (string) ($addresses[0] ?? '');

        if ($address === '') {
            throw new RuntimeException(
                'Direct Download host did not resolve safely.'
            );
        }

        return $address;
    }

    protected function megabytes(int $bytes): int
    {
        return (int) ceil(
            $bytes / 1024 / 1024
        );
    }
}
