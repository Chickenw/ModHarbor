<?php

namespace GameNest\GameNestModManager\Services;

use App\Repositories\Daemon\DaemonFileRepository;
use RuntimeException;

/** File moves are journaled before sending them to Wings, including ambiguous failures. */
class FileTransaction
{
    public array $moves = [];

    public function __construct(
        public DaemonFileRepository $repo,
        public string $root,
        protected \Closure $persist,
    ) {
    }

    public static function path(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f:]/', $path)
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new RuntimeException('Unsafe managed file path.');
        }
        return $path;
    }

    public function listing(string $path): array
    {
        $entries = $this->repo->getDirectory('/' . trim($path, '/'));
        if (!is_array($entries)) {
            throw new RuntimeException('Unable to read the server directory.');
        }
        return $entries;
    }

    /**
     * Resolve multiple paths against one short-lived directory snapshot.
     *
     * The listing cache exists only for this method call. It is never retained
     * across a Wings mutation, so post-move and rollback verification always
     * starts from fresh remote state.
     *
     * @param array<int, string> $paths
     * @return array<string, array|null>
     */
    public function statMany(array $paths): array
    {
        $listings = [];
        $results = [];

        foreach ($paths as $path) {
            $path = self::path((string) $path);

            if (array_key_exists($path, $results)) {
                continue;
            }

            $parts = explode('/', $path);
            $parent = '';
            $found = null;

            foreach ($parts as $index => $part) {
                if (!array_key_exists($parent, $listings)) {
                    $listings[$parent] = $this->listing($parent);
                }

                $found = null;

                foreach ($listings[$parent] as $entry) {
                    if (($entry['name'] ?? '') === $part) {
                        $found = $entry;
                        break;
                    }
                }

                if ($found === null) {
                    break;
                }

                if (!empty($found['symlink'])) {
                    throw new RuntimeException(
                        'Symbolic links are not supported in managed paths: ' . $path
                    );
                }

                if (
                    $index < count($parts) - 1
                    && !empty($found['file'])
                ) {
                    throw new RuntimeException(
                        'A parent path is a file: ' . $path
                    );
                }

                $parent = ltrim(
                    $parent . '/' . $part,
                    '/'
                );
            }

            $results[$path] = $found;
        }

        return $results;
    }

    /** Walk from root: never mistake permission/network errors for missing files. */
    public function stat(string $path): ?array
    {
        $path = self::path($path);

        return $this->statMany([$path])[$path];
    }

    public function mkdir(string $path): void
    {
        $path = self::path($path);
        $parent = '';
        $prefixes = [];

        foreach (explode('/', $path) as $part) {
            $parent = ltrim(
                $parent . '/' . $part,
                '/'
            );

            $prefixes[] = $parent;
        }

        $states = $this->statMany($prefixes);
        $parent = '';

        foreach (explode('/', $path) as $part) {
            $next = ltrim(
                $parent . '/' . $part,
                '/'
            );

            $entry = $states[$next] ?? null;

            if ($entry === null) {
                $this->repo->createDirectory(
                    $part,
                    '/' . $parent
                );
            } elseif (!empty($entry['file'])) {
                throw new RuntimeException(
                    'A directory is occupied by a file: ' . $next
                );
            }

            $parent = $next;
        }
    }

    public function move(string $from, string $to): void
    {
        self::path($from);
        self::path($to);
        $states = $this->statMany([
            $from,
            $to,
        ]);

        $source = $states[$from];

        if (
            !$source
            || empty($source['file'])
            || $states[$to] !== null
        ) {
            throw new RuntimeException(
                'File move conflicts with the current server files: ' . $from
            );
        }
        $parent = dirname($to);
        if ($parent !== '.') {
            $this->mkdir($parent);
        }
        $identity = ['size' => (int) ($source['size'] ?? 0)];
        if ($identity['size'] <= 32 * 1024 * 1024) {
            $identity['sha256'] = hash('sha256', $this->repo->getContent($from, max(1, $identity['size'] + 1)));
        }
        $this->moves[] = ['from' => $from, 'to' => $to] + $identity;
        ($this->persist)($this->moves);
        $this->repo->renameFiles('/', [['from' => $from, 'to' => $to]]);
        $this->verifyMove($from, $to, $identity);
    }

    /**
     * Move independent files as one verified Wings rename operation.
     *
     * All source/destination and destination-parent checks are resolved from
     * one short-lived pre-mutation snapshot. Every intended move is journaled
     * before the remote rename request. Verification always reads fresh remote
     * state after the request, so silent, partial, or corrupt moves remain
     * recoverable through the existing rollback journal.
     *
     * This method intentionally rejects overlapping move graphs. Chained moves
     * such as A -> B followed by B -> C must continue to use move() so their
     * ordering remains explicit.
     *
     * @param array<int, array{from:string,to:string}> $moves
     */
    public function moveMany(array $moves): void
    {
        if ($moves === []) {
            return;
        }

        $normalized = [];
        $occupied = [];
        $statPaths = [];
        $parents = [];

        foreach ($moves as $move) {
            if (
                !is_array($move)
                || !isset($move['from'], $move['to'])
                || !is_string($move['from'])
                || !is_string($move['to'])
            ) {
                throw new RuntimeException(
                    'Invalid file move request.'
                );
            }

            $from = self::path($move['from']);
            $to = self::path($move['to']);

            if ($from === $to) {
                throw new RuntimeException(
                    'Invalid self-referencing file move.'
                );
            }

            /*
             * Batch moves must be independent. This prevents ambiguous Wings
             * ordering where one move consumes another move's source or target.
             */
            if (
                isset($occupied[$from])
                || isset($occupied[$to])
            ) {
                throw new RuntimeException(
                    'Overlapping file moves cannot be batched.'
                );
            }

            $occupied[$from] = true;
            $occupied[$to] = true;

            $normalized[] = [
                'from' => $from,
                'to' => $to,
            ];

            $statPaths[$from] = true;
            $statPaths[$to] = true;

            $parent = dirname($to);

            if ($parent !== '.') {
                $prefix = '';

                foreach (explode('/', $parent) as $part) {
                    $prefix = ltrim(
                        $prefix . '/' . $part,
                        '/'
                    );

                    $parents[$prefix] = true;
                    $statPaths[$prefix] = true;
                }
            }
        }

        $states = $this->statMany(
            array_keys($statPaths)
        );

        /*
         * Validate the complete batch before performing any remote mutation.
         */
        foreach ($normalized as $move) {
            $source = $states[$move['from']] ?? null;
            $destination = $states[$move['to']] ?? null;

            if (
                !$source
                || empty($source['file'])
                || $destination !== null
            ) {
                throw new RuntimeException(
                    'File move conflicts with the current server files: '
                    . $move['from']
                );
            }
        }

        /*
         * Validate destination parents from the same snapshot, then create
         * only the missing prefixes in shallow-to-deep order.
         */
        $parentPaths = array_keys($parents);

        usort(
            $parentPaths,
            static function (string $a, string $b): int {
                $depth =
                    substr_count($a, '/')
                    <=> substr_count($b, '/');

                return $depth !== 0
                    ? $depth
                    : strlen($a) <=> strlen($b);
            }
        );

        foreach ($parentPaths as $parent) {
            $entry = $states[$parent] ?? null;

            if ($entry !== null) {
                if (!empty($entry['file'])) {
                    throw new RuntimeException(
                        'A directory is occupied by a file: '
                        . $parent
                    );
                }

                continue;
            }

            $root = dirname($parent);

            if ($root === '.') {
                $root = '';
            }

            $this->repo->createDirectory(
                basename($parent),
                '/' . $root
            );
        }

        /*
         * Capture identities before the batch is journaled and moved.
         */
        $journalMoves = [];
        $wireMoves = [];

        foreach ($normalized as $move) {
            $source = $states[$move['from']];

            $identity = [
                'size' =>
                    (int) ($source['size'] ?? 0),
            ];

            if (
                $identity['size']
                <= 32 * 1024 * 1024
            ) {
                $identity['sha256'] =
                    hash(
                        'sha256',
                        $this->repo->getContent(
                            $move['from'],
                            max(
                                1,
                                $identity['size'] + 1
                            )
                        )
                    );
            }

            $journalMoves[] =
                $move + $identity;

            $wireMoves[] = $move;
        }

        $startIndex = count($this->moves);

        foreach ($journalMoves as $move) {
            $this->moves[] = $move;
        }

        /*
         * Persist every intended move before Wings receives the batch. If the
         * request partially succeeds or its response is lost, recovery has the
         * complete set of possible remote mutations.
         */
        ($this->persist)($this->moves);

        $this->repo->renameFiles(
            '/',
            $wireMoves
        );

        /*
         * Verification deliberately starts with a fresh remote snapshot.
         */
        $verifyPaths = [];

        foreach ($journalMoves as $move) {
            $verifyPaths[$move['from']] = true;
            $verifyPaths[$move['to']] = true;
        }

        $verified =
            $this->statMany(
                array_keys($verifyPaths)
            );

        foreach ($journalMoves as $offset => $move) {
            $source =
                $verified[$move['from']]
                ?? null;

            $destination =
                $verified[$move['to']]
                ?? null;

            if (
                $source !== null
                || empty($destination['file'])
                || (
                    isset($move['size'])
                    && (int) (
                        $destination['size']
                        ?? -1
                    ) !== $move['size']
                )
            ) {
                throw new RuntimeException(
                    'File move could not be verified; recovery files have been retained.'
                );
            }

            if (
                isset($move['sha256'])
                && !hash_equals(
                    $move['sha256'],
                    hash(
                        'sha256',
                        $this->repo->getContent(
                            $move['to'],
                            max(
                                1,
                                $move['size'] + 1
                            )
                        )
                    )
                )
            ) {
                throw new RuntimeException(
                    'File content changed during a move; recovery files have been retained.'
                );
            }
        }
    }

    private function verifyMove(string $absent, string $present, array $identity): void
    {
        $states = $this->statMany([
            $present,
            $absent,
        ]);

        $stat = $states[$present];

        if ($states[$absent] !== null || empty($stat['file'])
            || (isset($identity['size']) && (int) ($stat['size'] ?? -1) !== $identity['size'])) {
            throw new RuntimeException('File move could not be verified; recovery files have been retained.');
        }
        if (isset($identity['sha256']) && !hash_equals($identity['sha256'],
            hash('sha256', $this->repo->getContent($present, max(1, $identity['size'] + 1))))) {
            throw new RuntimeException('File content changed during a move; recovery files have been retained.');
        }
    }

    public function rollback(): void
    {
        foreach (array_reverse(array_keys($this->moves)) as $index) {
            $move = $this->moves[$index];
            if (!empty($move['restored'])) { continue; }
            $states = $this->statMany([
                $move['from'],
                $move['to'],
            ]);

            $from = $states[$move['from']];
            $to = $states[$move['to']];

            if ($from === null && $to !== null && !empty($to['file'])) {
                $this->repo->renameFiles('/', [['from' => $move['to'], 'to' => $move['from']]]);
            } elseif ($from === null || $to !== null) {
                throw new RuntimeException('Cannot safely restore a file; recovery journal retained.');
            }
            $this->verifyMove($move['to'], $move['from'], $move);
            $this->moves[$index]['restored'] = true;
            ($this->persist)($this->moves);
        }
    }

    /**
     * Retry an interrupted transaction using only its recorded move journal.
     *
     * A move is considered safely restored only when the original source
     * exists as a regular file and the transaction destination is absent.
     * Ambiguous states remain locked for manual administrator review.
     */
    public function recover(array $moves): void
    {
        $validated = [];

        foreach ($moves as $move) {
            if (
                !is_array($move)
                || !isset($move['from'], $move['to'])
                || !is_string($move['from'])
                || !is_string($move['to'])
            ) {
                throw new RuntimeException(
                    'The recovery journal contains an invalid file move.'
                );
            }

            $from = self::path($move['from']);
            $to = self::path($move['to']);

            if ($from === $to) {
                throw new RuntimeException(
                    'The recovery journal contains an invalid self-referencing move.'
                );
            }

            $identity = [];
            if (isset($move['size'])) {
                if (!is_int($move['size']) || $move['size'] < 0) { throw new RuntimeException('Invalid recovery file size.'); }
                $identity['size'] = $move['size'];
            }
            if (isset($move['sha256'])) {
                if (!isset($identity['size']) || $identity['size'] > 32 * 1024 * 1024
                    || !is_string($move['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $move['sha256'])) {
                    throw new RuntimeException('Invalid recovery checksum.');
                }
                $identity['sha256'] = $move['sha256'];
            }
            $validated[] = [
                'from' => $from,
                'to' => $to,
                'restored' => !empty($move['restored']),
            ] + $identity;
        }

        $this->moves = $validated;

        $this->rollback();

        // Check net original occupancy: transaction chains can reuse earlier paths.
        $expected = [];
        foreach ($this->moves as $move) {
            $expected[$move['from']] ??= true;
            $expected[$move['to']] ??= false;
        }
        $states = $this->statMany(
            array_keys($expected)
        );

        foreach ($expected as $path => $present) {
            $stat = $states[$path];

            if ($present ? ($stat === null || empty($stat['file'])) : $stat !== null) {
                throw new RuntimeException('Recovery could not verify the original file state.');
            }
        }
    }


    /**
     * Remove genuinely empty parent directories left behind after managed
     * files have been removed.
     *
     * This is intentionally best-effort and must only be called after the
     * main file transaction and manifest commit have succeeded.
     */
    public function cleanupEmptyParents(array $filePaths): void
    {
        $protected = array_fill_keys([
            'Mods',
            'UserCode',
            'Configs',
            'Configs/Mods',
            '.gamenest',
            '.gamenest/mod-manager',
            '.gamenest/mod-manager/disabled',
            '.gamenest/mod-manager/operations',
        ], true);

        $candidates = [];

        foreach ($filePaths as $filePath) {
            $filePath = self::path((string) $filePath);
            $directory = dirname($filePath);

            while (
                $directory !== '.'
                && $directory !== ''
            ) {
                if (isset($protected[$directory])) {
                    break;
                }

                $candidates[$directory] = true;

                $parent = dirname($directory);

                if (
                    $parent === $directory
                    || $parent === '.'
                    || $parent === ''
                ) {
                    break;
                }

                $directory = $parent;
            }
        }

        $candidates = array_keys($candidates);

        usort(
            $candidates,
            static function (string $a, string $b): int {
                $depth = substr_count($b, '/') <=> substr_count($a, '/');

                return $depth !== 0
                    ? $depth
                    : strlen($b) <=> strlen($a);
            }
        );

        foreach ($candidates as $directory) {
            if (isset($protected[$directory])) {
                continue;
            }

            $entry = $this->stat($directory);

            if ($entry === null) {
                continue;
            }

            if (
                !empty($entry['file'])
                || !empty($entry['symlink'])
            ) {
                continue;
            }

            /*
             * Never infer emptiness from stat information. Ask Wings for the
             * directory contents immediately before deleting it.
             */
            if ($this->listing($directory) !== []) {
                continue;
            }

            $parent = dirname($directory);

            if ($parent === '.') {
                $parent = '';
            }

            $this->repo->deleteFiles(
                '/' . trim($parent, '/'),
                [basename($directory)]
            );
        }
    }

    public function cleanup(): void
    {
        if ($this->stat($this->root) !== null) {
            $this->repo->deleteFiles('/' . dirname($this->root), [basename($this->root)]);
        }
    }
}
