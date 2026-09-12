<?php

use GameNest\GameNestModManager\Services\{RustUModLifecycleDriver, RustGitHubLifecycleDriver, RustDirectLifecycleDriver,
    RustUploadLifecycleDriver, SafeRemoteDownloader, UploadPackageStore, SourceContext};
use GameNest\GameNestModManager\Providers\{UModProvider, DirectDownloadProvider, UploadProvider, GitHubProvider};

class RustUModFixture extends UModProvider
{
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $url='https://umod.org/plugins/Fixture.cs?version=1.0'; $checksum=sha1($url);
        return ['id'=>(string)$id,'name'=>'Fixture','latest_file'=>['id'=>'1.0:'.$checksum,'version'=>'1.0',
            'name'=>'Fixture.cs','checksum'=>$checksum,'download_url'=>$url]];
    }
    public function requiredDependencies(string $slug, ?string $version = null, ?SourceContext $source = null): array { return []; }
}
class RustDirectFixture extends DirectDownloadProvider
{
    public string $extension='cs';
    public function validateUrl(string $url): string { return $url; }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        return ['id'=>(string)$id,'name'=>'Fixture','latest_file'=>['id'=>(string)$id,'version'=>'Direct',
            'name'=>'Fixture.'.$this->extension,'download_url'=>'https://fixture/direct']];
    }
}
class RustDownloadFixture extends SafeRemoteDownloader
{
    public function download(string $url, int $maxBytes): string { return $url; }
}
class RustUploadStoreFixture extends UploadPackageStore
{
    public string $extension='cs';
    public function get(string $id): ?array
    {
        return ['id'=>$id,'name'=>'Fixture','filename'=>'Fixture.'.$this->extension,'size'=>20,'version_label'=>'Local'];
    }
    public function contents(string $id): string { return 'private-source'; }
}

foreach (['carbon','oxide'] as $runtime) {
    foreach (['umod:cs','github:cs','github:zip','direct:cs','direct:zip','upload:cs','upload:zip'] as $variant) {
        $tests['Rust '.$runtime.' '.$variant.' retains native install reinstall toggle remove behavior'] = function () use ($runtime,$variant) {
            [$server,$repo,$provider,$manifest,$store,$engine]=fixture(); $server->egg->name='Rust';
            seedFiles($repo,[$runtime.'/config/Fixture.json'=>'{"keep":true}']); $repo->dirs[$runtime.'/plugins']=true;
            [$source,$extension]=explode(':',$variant);
            $id=match($source){'umod'=>'fixture','github'=>'123','direct'=>'fixture-download','upload'=>str_repeat('a',40)};
            if($source==='umod') {
                $GLOBALS['services'][RustUModLifecycleDriver::class]=new RustUModLifecycleDriver(new RustUModFixture);
            } elseif($source==='github') {
                $github=$GLOBALS['services'][GitHubProvider::class];
                $file=['id'=>10,'version'=>'1.0','name'=>'Fixture.'.$extension,'download_url'=>'https://fixture/github/rust'];
                $github->catalog[123]=['id'=>123,'name'=>'Fixture','full_name'=>'fixture/rust','latest_file'=>$file];
                $github->assets[123][10]=$file;
                $repo->archives[$file['download_url']]=['oxide/plugins/Fixture.cs'=>'plugin-source'];
                $GLOBALS['services'][RustGitHubLifecycleDriver::class]=new RustGitHubLifecycleDriver($github);
            } elseif($source==='direct') {
                $direct=new RustDirectFixture; $direct->extension=$extension;
                $GLOBALS['services'][RustDirectLifecycleDriver::class]=new RustDirectLifecycleDriver($direct);
                $GLOBALS['services'][SafeRemoteDownloader::class]=new RustDownloadFixture($direct);
                $repo->archives['https://fixture/direct']=['bundle/Fixture.cs'=>'plugin-source'];
            } else {
                $uploads=new RustUploadStoreFixture; $uploads->extension=$extension;
                $GLOBALS['services'][UploadPackageStore::class]=$uploads;
                $GLOBALS['services'][RustUploadLifecycleDriver::class]=new RustUploadLifecycleDriver(new UploadProvider($uploads),$uploads);
                $repo->archives['private-source']=['bundle/Fixture.cs'=>'plugin-source'];
            }
            $key=$source.':'.$id; $engine->run($server,'install',$key);
            $path=$runtime.'/plugins/Fixture.cs'; check(isset($repo->files[$path]),'Native target missing');
            $installed=$repo->files[$path];
            $engine->run($server,'disable',$key); check(!isset($repo->files[$path]),'Disable failed');
            $engine->run($server,'reinstall',$key); check(!$manifest->mods($server)[$key]['enabled'],'Reinstall changed disabled state');
            $engine->run($server,'enable',$key); check($repo->files[$path]===$installed,'Reinstall changed source');
            $engine->run($server,'remove',$key);
            check(!isset($repo->files[$path]) && $repo->files[$runtime.'/config/Fixture.json']==='{"keep":true}','Removal damaged runtime config');
        };
    }
}
