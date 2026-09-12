<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\SourceContext;
use RuntimeException;

class DirectDownloadProvider implements ModProvider, ConfiguredDownloadProvider
{
    public function key(): string
    {
        return 'direct';
    }

    public function name(): string
    {
        return 'Direct Download';
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array {
        return [];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array {
        $url = $this->decodeId((string) $id);

        $filename = $this->filename($url);

        return [
            'id' => (string) $id,
            'name' => $filename,
            'summary' => 'Directly managed download',
            'provider' => 'direct',
            'profile_url' => $url,

            'latest_file' => [
                /*
                 * Direct URLs have no trustworthy remote release/version
                 * metadata. The URL identity remains stable for reinstall.
                 */
                'id' => (string) $id,
                'name' => $filename,
                'filename' => $filename,
                'version' => 'Direct',
                'download_url' => $url,
            ],
        ];
    }

    public function makeId(string $url): string
    {
        $url = $this->validateUrl($url);

        return rtrim(
            strtr(
                base64_encode($url),
                '+/',
                '-_'
            ),
            '='
        );
    }

    public function decodeId(string $id): string
    {
        $id = trim($id);

        if (
            $id === ''
            || !preg_match('/^[A-Za-z0-9_-]+$/', $id)
        ) {
            throw new RuntimeException(
                'Invalid direct-download identifier.'
            );
        }

        $padding =
            (4 - strlen($id) % 4) % 4;

        $decoded = base64_decode(
            strtr(
                $id . str_repeat('=', $padding),
                '-_',
                '+/'
            ),
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Invalid direct-download identifier.'
            );
        }

        return $this->validateUrl($decoded);
    }

    public function validateUrl(string $url): string
    {
        $url = trim($url);

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new RuntimeException(
                'Enter a valid HTTPS download URL.'
            );
        }

        $parts = parse_url($url);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
        ) {
            throw new RuntimeException(
                'Direct downloads must use HTTPS.'
            );
        }

        $host = strtolower(
            trim((string) $parts['host'])
        );

        /*
         * Reject obvious localhost/private-address targets.
         * The daemon must never become an SSRF path into local services.
         */
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
        ) {
            throw new RuntimeException(
                'Localhost direct-download URLs are not allowed.'
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (
                filter_var(
                    $host,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE
                    | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                throw new RuntimeException(
                    'Private or reserved direct-download addresses are not allowed.'
                );
            }
        }

        /*
         * Hostnames are resolved here as well as immediately before
         * retrieval. Every returned address must be publicly routable.
         */
        $this->resolvePublicAddresses($url);

        $this->filename($url);

        return $url;
    }

    public function resolvePublicAddresses(
        string $url
    ): array {
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || trim((string) ($parts['host'] ?? '')) === ''
        ) {
            throw new RuntimeException(
                'Direct Download host could not be resolved safely.'
            );
        }

        $host = strtolower(
            trim((string) $parts['host'])
        );

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
        ) {
            throw new RuntimeException(
                'Localhost direct-download URLs are not allowed.'
            );
        }

        $addresses = [];

        if (
            filter_var(
                $host,
                FILTER_VALIDATE_IP
            )
        ) {
            $addresses[] = $host;
        } else {
            $records = @dns_get_record(
                $host,
                DNS_A | DNS_AAAA
            );

            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = trim(
                        (string) (
                            $record['ip']
                            ?? $record['ipv6']
                            ?? ''
                        )
                    );

                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
            }

            if ($addresses === []) {
                $fallback = @gethostbynamel($host);

                if (is_array($fallback)) {
                    foreach ($fallback as $address) {
                        if (trim($address) !== '') {
                            $addresses[] =
                                trim($address);
                        }
                    }
                }
            }
        }

        $addresses = array_values(
            array_unique($addresses)
        );

        if ($addresses === []) {
            throw new RuntimeException(
                'Direct Download host did not resolve to a usable address.'
            );
        }

        foreach ($addresses as $address) {
            if (
                filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE
                    | FILTER_FLAG_NO_RES_RANGE
                ) === false
            ) {
                throw new RuntimeException(
                    'Direct Download host resolves to a private or reserved address.'
                );
            }
        }

        return $addresses;
    }

    public function filename(string $url): string
    {
        $path =
            (string) (
                parse_url($url, PHP_URL_PATH)
                ?? ''
            );

        $filename =
            rawurldecode(
                basename($path)
            );

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            throw new RuntimeException(
                'The direct-download URL must contain a valid filename.'
            );
        }

        return $filename;
    }
}
