<?php

use GameNest\GameNestModManager\Services\{SupportBundle, PortableDefinitionGuard, ProviderSettingsStore, GameDefinition};

test070('canonical example agrees with shipped identity', function () {
    $example = json_decode(file_get_contents(__DIR__ . '/../examples/7-days-to-die.modharbor.json'), true);
    $seeds = config('gamenest-mod-manager.game_definition_seeds');
    $seed = array_values(array_filter($seeds, fn ($d) => $d['key'] === $example['key']));
    check(count($seed) === 1 && $seed[0]['steam_app_id'] === $example['steam_app_id'], 'Example identity does not match seed');
});

test070('portable data rejects nested credentials and private URL wrappers', function () {
    $bad = [
        ['nested' => ['apiKey' => 'canary-secret']],
        ['nested' => ['authorization' => 'canary-secret']],
        ['nested' => ['client_secret' => 'canary-secret']],
        ['nested' => ['signature' => 'canary-secret']],
        ['note' => 'Bearer canary-secret'],
        ['note' => 'password=canary-secret'],
        ['url' => 'https://user:canary-secret@example.test/file'],
        ['url' => 'https://example.test/file?X-Amz-Signature=canary-secret'],
        ['url' => 'https://example.test/file#canary-secret'],
        ['url' => rawurlencode('https://example.test/file?key=canary-secret')],
        ['url' => base64_encode('https://example.test/file?key=canary-secret')],
    ];
    foreach ($bad as $metadata) {
        $d = game070(); $d['sources']['github']['metadata'] = $metadata;
        try { definition070($d)->json(); throw new LogicException('Sensitive metadata accepted'); }
        catch (RuntimeException $e) { check(!str_contains($e->getMessage(), 'canary-secret'), 'Validation error leaked secret'); }
    }
    $d = game070(); $d['artwork_url'] = 'https://example.test/image?key=canary-secret';
    try { definition070($d)->json(); throw new LogicException('Private artwork URL exported'); }
    catch (RuntimeException $e) { check(!str_contains($e->getMessage(), 'canary-secret'), 'Artwork error leaked secret'); }
    PortableDefinitionGuard::check(['repository' => 'author/public-project', 'url' => 'https://example.test/public.jpg']);
});

test070('exports reject configured secrets disguised in ordinary fields', function () {
    $GLOBALS['configOverrides']['gamenest-mod-manager.providers'] = ['github' => ['credential_fields' => ['token' => ['type' => 'secret']]]];
    $settings = new class extends ProviderSettingsStore {
        public function get(string $provider, string $name, mixed $fallback = null): mixed { return 'unique-secret-canary'; }
    };
    foreach (['unique-secret-canary', base64_encode('unique-secret-canary'), rawurlencode('unique-secret-canary')] as $secret) {
        try { $settings->assertPortable(['name' => 'Example ' . $secret]);
            throw new LogicException('Configured secret exported');
        } catch (RuntimeException $e) { check(!str_contains($e->getMessage(), 'unique-secret-canary'), 'Secret in exception'); }
    }
    try { $settings->assertPortable(['name' => base64_encode('unique-secret-canary')]); throw new LogicException('Encoded secret exported'); }
    catch (RuntimeException) {}
    try { PortableDefinitionGuard::check(['opaqueKeyCanary' => 'value'], ['opaqueKeyCanary']); throw new LogicException('Secret metadata key exported'); }
    catch (RuntimeException) {}
    try { PortableDefinitionGuard::check(['value' => 812345], ['812345']); throw new LogicException('Numeric credential exported'); }
    catch (RuntimeException) {}
});

test070('support projection omits all arbitrary strings and nested secrets', function () {
    $secret = 'support-canary-token';
    $d = game070();
    foreach (['key', 'name', 'artwork_url'] as $key) { $d[$key] = $secret; }
    $d['sources']['github']['metadata'] = ['anything' => $secret];
    $d['mod_directories'] = [$secret]; $d['deployment']['target'] = $secret;
    $d['detection']['egg_names'] = [$secret];
    $row = ['id' => $secret, 'name' => $secret, 'actor' => $secret, 'message' => $secret,
        'action' => $secret, 'status' => 'failed', 'error_type' => $secret, 'phase' => $secret,
        'phases' => [['name' => $secret, 'duration_ms' => 12.5, 'started_us' => $secret]],
        'duration_ms' => 25.5, 'journal_moves' => [['from' => $secret]],
        'transitions' => [['before' => ['url' => 'https://private.test/' . $secret]]]];
    $projected = ['definition' => SupportBundle::definition($d), 'history' => SupportBundle::history([$row]),
        'manifest' => SupportBundle::manifest(['server_uuid' => $secret, 'mods' => [$secret => ['name' => $secret, 'paths' => [$secret]]]])];
    $json = json_encode($projected);
    check(!str_contains($json, $secret) && !str_contains($json, 'private.test'), 'Public projection contains private text');
    check($projected['history'][0]['duration_ms'] === 25.5 && $projected['history'][0]['phases'][0]['duration_ms'] === 12.5, 'Timings lost');
    check($projected['manifest']['managed_count'] === 1 && $projected['manifest']['file_count'] === 1, 'Manifest summary incorrect');
});

test070('support history bounds and malformed timing compatibility', function () {
    $rows = SupportBundle::history(array_fill(0, 120, ['status' => 'completed', 'duration_ms' => INF,
        'phases' => [['name' => 'Verify', 'duration_ms' => -1], ['name' => 'Stage', 'duration_ms' => '123'], 'legacy']]));
    check(count($rows) === 100 && $rows[0]['duration_ms'] === null, 'History bound or nonfinite timing failure');
    check($rows[0]['phases'][0]['duration_ms'] === null && $rows[0]['phases'][1]['duration_ms'] === null, 'Invalid duration accepted');
    json_encode($rows, JSON_THROW_ON_ERROR);
    check(SupportBundle::history([['status' => 'completed']])[0]['duration_ms'] === null, 'Legacy timing fabricated');
});

test070('support collection uses safe history and retains recovery beyond recent history', function () {
    [$server, $repo] = fixture();
    $store = new \GameNest\GameNestModManager\Services\OperationStore;
    for ($i = 0; $i < 102; $i++) {
        $store->save($server, ['id' => sprintf('%024x', $i), 'status' => $i === 0 ? 'recovery_required' : 'completed',
            'started_at' => gmdate('c', $i), 'action' => 'install', 'message' => 'Bearer private-canary',
            'manifest_before' => ['private' => 'private-canary'], 'duration_ms' => 20.0,
            'phases' => [['name' => 'Verify', 'duration_ms' => 10.0, 'started_us' => 123]]]);
    }
    $filesBefore = $repo->files;
    $bundle = (new SupportBundle)->collect($server);
    check(count($bundle['history']) === 100 && $bundle['recovery']['count'] === 1, 'Older recovery lost');
    check($bundle['recovery']['operations'][0]['status'] === 'recovery_required', 'Recovery status missing');
    check(!str_contains(json_encode($bundle), 'private-canary'), 'Journal secret leaked');
    check($repo->files === $filesBefore && $repo->moves === 0, 'Download modified server');
});

test070('support fails safely on unavailable manifest and corrupt journals', function () {
    [$server, $repo] = fixture(); $repo->unavailable = true;
    $GLOBALS['services'][ProviderSettingsStore::class] = new class extends ProviderSettingsStore {
        public function configurationStatus(string $provider, array $fields, array $requireAny = []): bool { throw new RuntimeException('credential-error-canary'); }
    };
    $store = new \GameNest\GameNestModManager\Services\OperationStore;
    file_put_contents($store->directory($server) . '/' . str_repeat('a', 24) . '.json', '{corrupt-secret');
    $bundle = (new SupportBundle)->collect($server);
    check($bundle['manifest']['status'] === 'unavailable' && $bundle['recovery']['status'] === 'unavailable', 'Unavailable state reported as clear');
    check($bundle['history'] === null && !str_contains(json_encode($bundle), 'corrupt-secret'), 'Corrupt journal escaped');
    check($bundle['providers'][0]['configured'] === null && !str_contains(json_encode($bundle), 'credential-error-canary'), 'Credential failure leaked or claimed configured');
});

test070('support authorization checked before collection', function () {
    [$server] = fixture(); \Illuminate\Support\Facades\Gate::$allow = false;
    try { (new SupportBundle)->collect($server); throw new LogicException('Unauthorized support download'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'Denied', 'Read permission not checked'); }
    finally { \Illuminate\Support\Facades\Gate::$allow = true; }
});

test070('provider configured status never returns values and validates required fields', function () {
    $store = new class extends ProviderSettingsStore {
        public function get(string $provider, string $name, mixed $fallback = null): mixed { return $name === 'token' ? 'canary' : ''; }
    };
    check($store->configurationStatus('github', ['token' => ['type' => 'secret']]) === true, 'Configured secret missing');
    check($store->configurationStatus('github', ['missing' => ['required' => true], 'token' => []]) === false, 'Missing required field accepted');
    check($store->configurationStatus('direct', []) === true, 'No-settings provider unavailable');
    check($store->configurationStatus('github', ['token' => []], ['missing']) === false, 'Alternative credential requirement ignored');
    check($store->configurationStatus('github', ['missing' => []]) === true, 'Optional credentials incorrectly required');
});

test070('invalid package types and archive prefixes fail before activation', function () {
    foreach ([[['nested']], ['zip', 'exe'], [false]] as $types) {
        $d = game070(); $d['package_types'] = $types;
        try { definition070($d); throw new LogicException('Invalid package type accepted'); } catch (RuntimeException) {}
    }
    foreach (['../outside', '/absolute', 'Mods/CON', 'Mods/trailing.', 'Mods\\other'] as $path) {
        $d = game070(); $d['deployment']['archive_prefix'] = $path;
        try { definition070($d); throw new LogicException('Unsafe prefix accepted'); } catch (RuntimeException) {}
    }
    $GLOBALS['configOverrides']['gamenest-mod-manager.providers'] = ['fixture' => ['metadata_fields' => ['prefix' => ['type' => 'text', 'format' => 'relative-path']]]];
    $validator = new \GameNest\GameNestModManager\Services\SourceMetadataValidator;
    try { $validator->validate(['sources' => ['fixture' => ['metadata' => ['prefix' => '../outside']]]]); throw new LogicException('Unsafe provider prefix accepted'); }
    catch (RuntimeException) {}
    $validator->validate(['sources' => ['fixture' => ['metadata' => ['prefix' => 'Mods/Example']]]]);
});
