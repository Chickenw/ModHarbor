<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/** Normalized dependency edges. File IDs remain authoritative immutable release pins. */
class DependencyGraph
{
    public static function normalize(array $specs, string $provider): array
    {
        $result = [];
        foreach ($specs as $spec) {
            if (!is_array($spec)) { $spec = ['id' => (string) $spec]; }
            $type = $spec['type'] ?? (!empty($spec['optional']) ? 'optional' : 'required');
            if (!in_array($type, ['required', 'optional', 'suggested', 'conflict'], true)) {
                throw new RuntimeException('Unknown dependency relationship.');
            }
            $row = ['provider' => $spec['provider'] ?? $provider, 'id' => (string) ($spec['id'] ?? ''),
                'type' => $type, 'constraint' => trim((string) ($spec['constraint'] ?? '')),
                'file_id' => (string) ($spec['file_id'] ?? '')];
            if (!preg_match('/^[a-z0-9_-]+$/D', $row['provider']) || $row['id'] === '' || preg_match('/[\x00-\x1f]/', $row['id'])) {
                throw new RuntimeException('Invalid dependency identity.');
            }
            $row['key'] = $row['provider'] . ':' . $row['id']; $result[] = $row;
        }
        return $result;
    }

    /** Deliberately bounded grammar; unknown constraints fail closed instead of silently matching. */
    public static function satisfies(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') { return true; }
        if (class_exists(\Composer\Semver\Semver::class)) {
            try { return \Composer\Semver\Semver::satisfies($version, $constraint); }
            catch (\Throwable) { throw new RuntimeException('Invalid dependency version constraint.'); }
        }
        foreach (preg_split('/\s*,\s*|\s+(?=[<>=!])/', $constraint) as $part) {
            if (!preg_match('/^(>=|<=|!=|==|=|>|<)?\s*(v?\d+(?:\.\d+){0,3}(?:-[a-zA-Z0-9.-]+)?)$/D', trim($part), $match)) {
                throw new RuntimeException('This dependency constraint requires composer/semver.');
            }
            if (!version_compare(ltrim($version, 'v'), ltrim($match[2], 'v'), ($match[1] ?? '') ?: '=')) { return false; }
        }
        return true;
    }

    public static function assertEntry(array $edge, array $entry): void
    {
        if (($edge['file_id'] !== '' && $edge['file_id'] !== (string) ($entry['file_id'] ?? ''))
            || ($edge['file_id'] === '' && !self::satisfies((string) ($entry['version'] ?? ''), $edge['constraint']))) {
            throw new RuntimeException('Installed dependency does not satisfy required version: ' . $edge['key']);
        }
    }

    public static function validate(array $mods): void
    {
        $visiting = []; $done = [];
        $visit = function ($key) use (&$visit, &$visiting, &$done, $mods) {
            if (isset($visiting[$key])) { throw new RuntimeException('Cyclic required dependencies.'); }
            if (isset($done[$key])) { return; }
            $visiting[$key] = true;
            foreach (($mods[$key]['dependency_specs'] ?? []) as $edge) {
                $target = $mods[$edge['key']] ?? null;
                if ($edge['type'] === 'conflict' && $target && self::satisfies((string) $target['version'], $edge['constraint'])) {
                    throw new RuntimeException('Conflicting installed mod: ' . $edge['key']);
                }
                if ($edge['type'] !== 'required') { continue; }
                if (!$target) { throw new RuntimeException('Missing required dependency: ' . $edge['key']); }
                self::assertEntry($edge, $target);
                if (!empty($mods[$key]['enabled']) && empty($target['enabled'])) { throw new RuntimeException('Required dependency is disabled.'); }
                $visit($edge['key']);
            }
            unset($visiting[$key]); $done[$key] = true;
        };
        foreach (array_keys($mods) as $key) { $visit($key); }
    }
}
