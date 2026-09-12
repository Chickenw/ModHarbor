<?php

namespace GameNest\GameNestModManager\Services;

class AuditTrail
{
    public static function transitions(array $before, array $after): array
    {
        $rows = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = $before[$key] ?? null; $new = $after[$key] ?? null;
            if ($old === $new) { continue; }
            $fields = array_flip(['provider', 'provider_id', 'file_id', 'version', 'enabled', 'source_type']);
            $rows[] = ['key' => $key, 'name' => $new['name'] ?? $old['name'] ?? $key,
                'before' => $old ? array_intersect_key($old, $fields) : null,
                'after' => $new ? array_intersect_key($new, $fields) : null,
                'files' => array_values(array_unique(array_merge($old['paths'] ?? [], $new['paths'] ?? [])))];
        }
        return self::redact($rows);
    }

    public static function display(array $entry): array
    {
        $row = array_intersect_key($entry, array_flip(['id', 'action', 'name', 'status', 'started_at',
            'finished_at', 'actor', 'message', 'changes', 'verification', 'transitions', 'affected_files',
            'phase', 'phases', 'duration_ms', 'restart_required', 'error_type', 'recovered_at', 'recovered_by', 'recovery_of']));
        // Legacy journals get a useful file list without exposing manifests, config bodies or URLs.
        $row['affected_files'] ??= array_values(array_unique(array_merge(...array_map(
            fn ($move) => array_values(array_filter([$move['from'] ?? '', $move['to'] ?? ''],
                fn ($path) => $path !== '' && !str_starts_with($path, '.gamenest/'))), $entry['moves'] ?? []))));
        if (isset($row['duration_ms'])) {
            $row['duration_ms'] = round(max(0, (float) $row['duration_ms']), 2);
        }

        if (isset($row['phases']) && is_array($row['phases'])) {
            $row['phases'] = array_values(array_map(
                static function ($phase): array {
                    if (!is_array($phase)) {
                        return [
                            'name' => 'Unknown',
                            'duration_ms' => null,
                        ];
                    }

                    return [
                        'name' => (string) ($phase['name'] ?? 'Unknown'),
                        'duration_ms' => isset($phase['duration_ms'])
                            ? round(max(0, (float) $phase['duration_ms']), 2)
                            : null,
                    ];
                },
                $row['phases']
            ));
        }

        $row['rollback'] = match ($entry['status'] ?? '') {
            'failed', 'recovered' => 'Rolled back', 'recovery_required' => 'Incomplete',
            'running' => 'Pending verification', default => 'Not required',
        };
        $row['move_count'] = count($entry['moves'] ?? []);
        $row['journal_moves'] = array_map(fn ($move) => [
            'from' => (string) ($move['from'] ?? ''), 'to' => (string) ($move['to'] ?? ''),
            'restored' => !empty($move['restored']),
        ], $entry['moves'] ?? []);
        return self::redact($row);
    }

    /** Provider identities may encode private URLs; display stable hashes instead of recoverable URLs. */
    private static function redact(mixed $value): mixed
    {
        if (is_array($value)) { return array_map([self::class, 'redact'], $value); }
        if (!is_string($value)) { return $value; }
        if (preg_match('~https?://~i', $value)) { return 'URL reference sha256:' . hash('sha256', $value); }
        return preg_replace_callback('/[A-Za-z0-9_-]{12,}/', static function ($match) {
            $token = $match[0];
            $decoded = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4), true);
            return $decoded !== false && preg_match('~^https?://~i', $decoded)
                ? 'sha256:' . hash('sha256', $token) : $token;
        }, $value);
    }
}
