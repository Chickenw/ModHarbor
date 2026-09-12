<?php

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use GameNest\GameNestModManager\Services\{AdapterRegistry, AuditTrail, ConfigManagementService, ConfigRevisionStore,
    DependencyGraph, ExistingModService, FileTransaction, GameDefinitionStore, ManagedFileScanner, ManagedMutation, ManifestService, ModLifecycleService, OperationStore};

function seedFiles(DaemonFileRepository $repo, array $contents): void
{
    foreach ($contents as $path => $content) {
        $parent = dirname($path);
        while ($parent !== '.') { $repo->dirs[$parent] = true; $parent = dirname($parent); }
        $repo->files[$path] = $content;
    }
}

function adoptedFixture(): array
{
    $f = fixture();
    seedFiles($f[1], ['Mods/Manual/main.dll' => 'retained binary', 'Configs/Manual.json' => '{"value":1}']);
    (new ExistingModService)->adopt($f[0], ['Mods/Manual/main.dll']);
    return $f;
}

$tests['scanner discovers adapter files and excludes core backups and symlinks'] = function () {
    [$s,$r] = fixture();
    seedFiles($r, ['Mods/Manual/main.dll'=>'x','Mods/__core__/main.dll'=>'core','Mods/Manual/main.dll.bak'=>'backup',
        'Mods/Manual/link.dll'=>'link','Mods/Manual/code.cs'=>'code','Mods/Manual/settings.json'=>'{}']);
    $r->symlinks['Mods/Manual/link.dll'] = true;
    $paths = array_column((new ManagedFileScanner)->scan($s), 'path');
    check($paths === ['Mods/Manual/code.cs','Mods/Manual/main.dll'], 'Unexpected discovery paths');
};
$tests['scanner ownership index preserves case multiple owners and unmanaged files at scale'] = function () {
    [$s,$r] = fixture();

    seedFiles($r, [
        'Mods/Shared.dll' => 'shared',
        'Mods/Unmanaged.dll' => 'unmanaged',
    ]);

    $mods = [];

    for ($i = 0; $i < 500; $i++) {
        $mods['provider:'.$i] = [
            'paths' => ['Mods/Package'.$i.'.dll'],
        ];
    }

    $mods['provider:alpha'] = [
        'paths' => ['mods/shared.DLL'],
    ];

    $mods['provider:beta'] = [
        'paths' => ['MODS/SHARED.dll'],
    ];

    $rows = (new ManagedFileScanner)->scan($s, false, $mods);
    $byPath = [];

    foreach ($rows as $row) {
        $byPath[$row['path']] = $row;
    }

    check(
        ($byPath['Mods/Shared.dll']['status'] ?? null) === 'managed',
        'Case-insensitive ownership was not preserved'
    );

    $owners = $byPath['Mods/Shared.dll']['owners'] ?? [];
    sort($owners);

    check(
        $owners === ['provider:alpha', 'provider:beta'],
        'Multiple owners were not preserved'
    );

    check(
        ($byPath['Mods/Unmanaged.dll']['status'] ?? null) === 'unmanaged'
        && ($byPath['Mods/Unmanaged.dll']['owners'] ?? null) === [],
        'Unmanaged ownership changed'
    );
};

$tests['scanner rejects symlink parents and propagates connection failures'] = function () {
    [$s,$r] = fixture(); $r->symlinks['Mods'] = true;
    fails(fn () => (new ManagedFileScanner)->scan($s));
    $r->symlinks = []; $r->unavailable = true;
    fails(fn () => (new ManagedFileScanner)->scan($s));
};
$tests['scanner bounds directory entry count'] = function () {
    [$s,$r] = fixture(); for ($i=0;$i<5001;$i++) { $r->files['Mods/'.$i.'.dll'] = 'x'; }
    fails(fn () => (new ManagedFileScanner)->scan($s), '5,000');
};
$tests['scanner uses active Rust runtime and ignores stale runtime files'] = function () {
    [$s,$r] = fixture(); $s->egg->name = 'Rust';
    seedFiles($r,['carbon/plugins/Active.cs'=>'a','oxide/plugins/Stale.cs'=>'b']);
    check(array_column((new ManagedFileScanner)->scan($s),'path') === ['carbon/plugins/Active.cs'], 'Wrong runtime');
};
$tests['scanner supports XML-only configured game mods'] = function () {
    [$s,$r] = fixture();
    $s->egg->name = '7 Days to Die';

    $store = app(GameDefinitionStore::class);
    $snapshot = $store->snapshot();

    if (
        isset($snapshot['definitions']['7-days-to-die'])
        && !$snapshot['definitions']['7-days-to-die']['enabled']
    ) {
        $store->toggle(
            '7-days-to-die',
            $snapshot['revision']
        );
    }

    seedFiles(
        $r,
        [
            'Mods/Example/ModInfo.xml' => '<xml/>',
            'Mods/Example/Config/blocks.xml' => '<xml/>',
        ]
    );

    check(
        count((new ManagedFileScanner)->scan($s)) === 2,
        'XML mod files not found'
    );
};
$tests['adoption commits manifest and private source without changing live bytes'] = function () {
    [$s,$r,$p,$m,$store] = adoptedFixture();
    $mods = $m->mods($s); $entry = reset($mods);
    check(count($mods)===1 && $entry['provider']==='existing' && $entry['source_type']==='adopted','Missing adopted entry');
    check($r->files['Mods/Manual/main.dll']==='retained binary','Adoption changed live file');
    check($store->history($s)[0]['action']==='adopt','Missing adoption audit');
    check((new ManagedFileScanner)->scan($s)[0]['status']==='managed','Ownership not reported');
};
$tests['managed mutation records safe operation duration'] = function () {
    [$s,$r,$p,$m,$store] = adoptedFixture();

    $row = $store->history($s)[0] ?? [];

    check(
        ($row['action'] ?? null) === 'adopt',
        'Managed mutation history missing'
    );

    check(
        isset($row['duration_ms'])
        && is_numeric($row['duration_ms'])
        && $row['duration_ms'] >= 0,
        'Managed mutation duration missing'
    );

    check(
        !array_key_exists('started_us', $row),
        'Managed mutation internal timer leaked into history'
    );
};

$tests['bulk adoption is atomic when a selected file is missing'] = function () {
    [$s,$r,$p,$m] = fixture(); seedFiles($r,['Mods/A.dll'=>'a']); $before=$r->files;
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll','Mods/missing.dll']));
    check($r->files===$before && $m->mods($s)===[],'Partial adoption survived');
};
$tests['adoption rejects traversal unsupported paths and already managed files'] = function () {
    [$s,$r] = adoptedFixture();
    foreach (['../escape.dll','Configs/Manual.json','Mods/Manual/main.dll'] as $path) {
        fails(fn () => (new ExistingModService)->adopt($s,[$path]));
    }
};
$tests['adoption enforces size count and case collision limits'] = function () {
    [$s,$r] = fixture(); seedFiles($r,['Mods/Large.dll'=>str_repeat('x',8*1024*1024+1)]);
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/Large.dll']));
    fails(fn () => (new ExistingModService)->adopt($s,[]));
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll','Mods/a.dll']));
    fails(fn () => (new ExistingModService)->adopt($s,array_map(fn($i)=>'Mods/'.$i.'.dll',range(1,51))));
};
$tests['adoption blocks running servers denied permissions and unresolved journals'] = function () {
    [$s,$r,$p,$m,$store] = fixture(); seedFiles($r,['Mods/A.dll'=>'a']);
    $GLOBALS['services'][DaemonServerRepository::class]->state='running';
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll']));
    $GLOBALS['services'][DaemonServerRepository::class]->state='offline';
    \Illuminate\Support\Facades\Gate::$allow=false;
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll']));
    \Illuminate\Support\Facades\Gate::$allow=true;
    $store->save($s,['id'=>str_repeat('a',24),'status'=>'recovery_required','started_at'=>'now']);
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll']));
    check($m->mods($s)===[],'Blocked adoption changed manifest');
};
$tests['adoption rolls back ambiguous post-move failure'] = function () {
    [$s,$r,$p,$m] = fixture(); seedFiles($r,['Mods/A.dll'=>'a']); $before=$r->files;
    $r->failAt=2; $r->failAfterMove=true;
    fails(fn () => (new ExistingModService)->adopt($s,['Mods/A.dll']));
    check($r->files===$before && $m->mods($s)===[],'Adoption rollback failed');
};
$tests['adopted source supports disable enable repair and remove'] = function () {
    [$s,$r,$p,$m,$store,$engine] = adoptedFixture(); $key=array_key_first($m->mods($s));
    $engine->run($s,'disable',$key); check(!isset($r->files['Mods/Manual/main.dll']),'Disable did not move file');
    $engine->run($s,'enable',$key); unset($r->files['Mods/Manual/main.dll']);
    $engine->repair($s,$key); check($r->files['Mods/Manual/main.dll']==='retained binary','Repair lost source');
    $engine->run($s,'remove',$key); check($m->mods($s)===[] && isset($r->files['Configs/Manual.json']),'Remove damaged config');
};
$tests['adopted reinstall refuses corrupted retained source'] = function () {
    [$s,$r,$p,$m,$store,$engine] = adoptedFixture(); $key=array_key_first($m->mods($s));
    foreach ($r->files as $path=>&$body) { if(str_starts_with($path,'.gamenest/mod-manager/adopted/'))$body='corrupt'; } unset($body);
    fails(fn () => $engine->run($s,'reinstall',$key),'checksum');
    check($r->files['Mods/Manual/main.dll']==='retained binary','Corrupt source touched live file');
};
$tests['configuration discovery supports all declared formats and excludes templates'] = function () {
    [$s,$r] = fixture(); foreach(['json','yaml','yml','toml','ini','cfg','txt','eco'] as $ext)$r->files['Configs/a.'.$ext]='{}';
    $r->files['Configs/a.eco.template']='{}';
    check(count((new ConfigManagementService)->listing($s))===8,'Missing format or included template');
};
$tests['config save retains exact JSON bytes and previous revision'] = function () {
    [$s,$r] = fixture(); $r->files['Configs/a.json']='{"n":1}'; $svc=new ConfigManagementService;
    $svc->save($s,'Configs/a.json','{ "n": 2 }',hash('sha256','{"n":1}'));
    check($r->files['Configs/a.json']==='{ "n": 2 }','Save reformatted content');
    $store=new ConfigRevisionStore; $rev=$store->listing($s,'Configs/a.json')[0];
    check($store->contents($s,'Configs/a.json',$rev['id'])==='{"n":1}','Wrong backup');
};
$tests['config restore validates and backs up current content'] = function () {
    [$s,$r] = fixture(); $r->files['Configs/a.json']='{"n":1}'; $svc=new ConfigManagementService; $store=new ConfigRevisionStore;
    $svc->save($s,'Configs/a.json','{"n":2}',hash('sha256','{"n":1}'));
    $id=$store->listing($s,'Configs/a.json')[0]['id'];
    $svc->save($s,'Configs/a.json','ignored',hash('sha256','{"n":2}'),$id);
    check($r->files['Configs/a.json']==='{"n":1}' && count($store->listing($s,'Configs/a.json'))===2,'Restore failed');
    check((new OperationStore)->history($s)[0]['action']==='config-restore','Restore audit missing');
};
$tests['config stale edits invalid JSON INI and oversized text fail before writes'] = function () {
    [$s,$r] = fixture(); $r->files['Configs/a.json']='{}'; $svc=new ConfigManagementService;
    fails(fn () => $svc->save($s,'Configs/a.json','{}',hash('sha256','stale')),'changed');
    fails(fn () => $svc->save($s,'Configs/a.json','{bad',hash('sha256','{}')));
    fails(fn () => $svc->validate($s,'Configs/a.ini','[broken'));
    fails(fn () => $svc->validate($s,'Configs/a.txt',str_repeat('x',1024*1024+1)));
    check($r->files['Configs/a.json']==='{}','Invalid save modified file');
};
$tests['config permissions traversal symlinks running and recovery states fail closed'] = function () {
    [$s,$r,$p,$m,$store] = fixture(); $r->files['Configs/a.json']='{}'; $svc=new ConfigManagementService;
    fails(fn () => $svc->read($s,'../secret'));
    $r->symlinks['Configs']=true; fails(fn () => $svc->read($s,'Configs/a.json')); $r->symlinks=[];
    \Illuminate\Support\Facades\Gate::$allow=false; fails(fn () => $svc->save($s,'Configs/a.json','[]',hash('sha256','{}')));
    \Illuminate\Support\Facades\Gate::$allow=true; $GLOBALS['services'][DaemonServerRepository::class]->state='running';
    fails(fn () => $svc->save($s,'Configs/a.json','[]',hash('sha256','{}')));
    $GLOBALS['services'][DaemonServerRepository::class]->state='offline';
    $store->save($s,['id'=>str_repeat('b',24),'status'=>'running','started_at'=>'now']);
    fails(fn () => $svc->save($s,'Configs/a.json','[]',hash('sha256','{}')));
    check($r->files['Configs/a.json']==='{}','Blocked save changed file');
};
$tests['config ambiguous write rollback restores exact prior bytes'] = function () {
    [$s,$r] = fixture(); $r->files['Configs/a.json']='{}'; $r->failAt=2; $r->failAfterMove=true;
    fails(fn () => (new ConfigManagementService)->save($s,'Configs/a.json','[]',hash('sha256','{}')));
    check($r->files['Configs/a.json']==='{}','Config rollback failed');
};
$tests['config format hooks do not silently accept unavailable parsers'] = function () {
    [$s] = fixture(); $svc=new ConfigManagementService;
    if (!class_exists(\Symfony\Component\Yaml\Yaml::class) && !function_exists('yaml_parse')) {
        fails(fn () => $svc->validate($s,'Configs/a.yaml','key: value'),'requires');
    } else { $svc->validate($s,'Configs/a.yaml','key: value'); fails(fn () => $svc->validate($s,'Configs/a.yaml','key: [broken')); }
    if (!class_exists(\Yosymfony\Toml\Toml::class)) {
        fails(fn () => $svc->validate($s,'Configs/a.toml','key = 1'),'requires');
    } else { $svc->validate($s,'Configs/a.toml','key = 1'); fails(fn () => $svc->validate($s,'Configs/a.toml','key = [broken')); }
    fails(fn () => $svc->validate($s,'Configs/a.eco','bad json'));
};
$tests['config revisions reject cross-path and traversal references'] = function () {
    [$s] = fixture(); $store=new ConfigRevisionStore; $entry=$store->backup($s,'Configs/a.json','{}');
    fails(fn () => $store->contents($s,'Configs/b.json',$entry['id']));
    fails(fn () => $store->contents($s,'Configs/a.json','../escape'));
};
$tests['dependency normalization distinguishes optional suggested and conflicting edges'] = function () {
    $rows=DependencyGraph::normalize([['id'=>'a'],['id'=>'b','optional'=>true],['id'=>'c','type'=>'suggested'],['id'=>'d','type'=>'conflict','provider'=>'other']], 'source');
    check(array_column($rows,'type')===['required','optional','suggested','conflict'],'Edge types lost');
    check($rows[3]['key']==='other:d','Cross-provider identity lost');
    fails(fn () => DependencyGraph::normalize([['id'=>'a','type'=>'mystery']],'source'));
};
$tests['version comparisons enforce ranges and reject malformed constraints'] = function () {
    check(DependencyGraph::satisfies('1.5.0','>=1.0, <2.0'),'Valid range failed');
    check(!DependencyGraph::satisfies('2.1.0','>=1.0, <2.0'),'Invalid range passed');
    fails(fn () => DependencyGraph::satisfies('1.0','nonsense ???'));
};
$tests['dependency graph rejects cycles missing disabled and conflicting requirements'] = function () {
    $edge=DependencyGraph::normalize([['id'=>'b']],'p')[0];
    $mods=['p:a'=>['version'=>'1','enabled'=>true,'dependency_specs'=>[$edge]],'p:b'=>['version'=>'1','enabled'=>true]];
    DependencyGraph::validate($mods);
    unset($mods['p:b']); fails(fn()=>DependencyGraph::validate($mods),'Missing');
    $mods['p:b']=['version'=>'1','enabled'=>false]; fails(fn()=>DependencyGraph::validate($mods),'disabled');
    $mods['p:b']=['version'=>'1','enabled'=>true,'dependency_specs'=>DependencyGraph::normalize([['id'=>'a']],'p')];
    fails(fn()=>DependencyGraph::validate($mods),'Cyclic');
    $mods['p:a']['dependency_specs'][0]['type']='conflict'; fails(fn()=>DependencyGraph::validate($mods),'Conflicting');
};
$tests['nested provider dependencies install and remove atomically'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture(); $p->dependencyMap=[77=>[88],88=>[3561559]];
    $engine->run($s,'install','modio:77'); check(count($m->mods($s))===3,'Nested dependency absent');
    $engine->run($s,'remove','modio:77'); check($m->mods($s)===[],'Nested orphan survived');
};
$tests['provider cycles and conflicts fail before any live move'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture(); $p->dependencyMap=[77=>[88],88=>[77]];
    fails(fn()=> $engine->run($s,'install','modio:77'),'Cyclic'); check($r->moves===0,'Cycle moved files');
    $p->dependencyMap=[]; $engine->run($s,'install','modio:88'); $before=$r->moves;
    $p->dependencyMap=[77=>[['id'=>'88','type'=>'conflict']]];
    fails(fn()=> $engine->run($s,'install','modio:77'),'Conflicting'); check($r->moves===$before,'Conflict moved files');
};
$tests['optional and suggested provider edges remain informational'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture();
    $p->dependencyMap=[77=>[['id'=>'88','type'=>'optional'],['id'=>'3561559','type'=>'suggested']]];
    $engine->run($s,'install','modio:77'); check(count($m->mods($s))===1,'Optional dependencies installed');
    check(count($m->mods($s)['modio:77']['dependency_specs'])===2,'Informational edges lost');
};
$tests['required version range is checked on prepared releases before moves'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture(); $p->dependencyMap=[77=>[['id'=>'88','constraint'=>'>=2.0']]];
    fails(fn()=> $engine->run($s,'install','modio:77'),'required version');
    check($r->moves===0 && $m->mods($s)===[],'Unsatisfied range changed files');
};
$tests['audit records provider version transitions and filters before limiting'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture(); $engine->run($s,'install','modio:88');
    $event=$store->history($s)[0]; check($event['transitions'][0]['after']['provider']==='modio','Missing provider');
    check($event['transitions'][0]['after']['version']==='1.0','Missing version');
    for($i=0;$i<105;$i++)$store->save($s,['id'=>sprintf('%024x',$i+1),'action'=>'test','name'=>'noise','status'=>'completed','started_at'=>'now','started_us'=>microtime(true)]);
    check(count($store->history($s,'','install','completed'))===1,'Filtering happened after limit');
    check(count($store->history($s,'modio:88'))===1,'Provider/key search failed');
};
$tests['audit projection excludes manifests contents and arbitrary journal secrets'] = function () {
    $row=AuditTrail::display(['id'=>'abc','status'=>'failed','manifest_before'=>['secret'=>'password'],
        'content'=>'token','download_url'=>'https://private','moves'=>[['from'=>'Mods/a.dll','to'=>'.gamenest/private']],
        'action'=>'install','name'=>'A']);
    check(!str_contains(json_encode($row),'password') && !isset($row['content'],$row['download_url']),'Audit leaked internals');
    check($row['affected_files']===['Mods/a.dll'] && $row['rollback']==='Rolled back','Legacy projection failed');
    $encoded=rtrim(strtr(base64_encode('https://fixture/private?token=secret'),'+/','-_'),'=');
    $row=AuditTrail::display(['status'=>'completed','name'=>'source:'.$encoded,'transitions'=>[
        ['key'=>'source:'.$encoded,'after'=>['provider_id'=>$encoded]]]]);
    check(!str_contains(json_encode($row),$encoded) && !str_contains(json_encode($row),'token=secret'),'Encoded URL leaked');
};
$tests['journal save rejects path traversal identifiers'] = function () {
    [$s,$r,$p,$m,$store]=fixture(); fails(fn()=> $store->save($s,['id'=>'../escape','status'=>'running']),'reference');
};
$tests['recovery verifies chained replace moves and is idempotent with saved progress'] = function () {
    [$s,$r]=fixture(); seedFiles($r,['Configs/a.json'=>'old','.gamenest/mod-manager/operations/test/new'=>'new']);
    $saved=[]; $files=new FileTransaction($r,'.gamenest/mod-manager/operations/test',function($moves)use(&$saved){$saved=$moves;});
    $files->move('Configs/a.json',$files->root.'/before'); $files->move($files->root.'/new','Configs/a.json');
    $files->recover($saved); check($r->files['Configs/a.json']==='old','Recovery failed');
    $again=new FileTransaction($r,$files->root,function($moves)use(&$saved){$saved=$moves;});
    $again->recover($saved); check($r->files['Configs/a.json']==='old','Repeated recovery changed bytes');
};
$tests['partial rollback retains progress and retry completes safely'] = function () {
    [$s,$r]=fixture(); seedFiles($r,['Configs/a.json'=>'old','.gamenest/mod-manager/operations/test/new'=>'new']);
    $saved=[]; $files=new FileTransaction($r,'.gamenest/mod-manager/operations/test',function($moves)use(&$saved){$saved=$moves;});
    $files->move('Configs/a.json',$files->root.'/before'); $files->move($files->root.'/new','Configs/a.json');
    $r->failAt=4; fails(fn()=> $files->rollback()); check(!empty($saved[1]['restored']),'Progress not persisted');
    $r->failAt=null; $files->recover($saved); check($r->files['Configs/a.json']==='old','Retry failed');
};
$tests['recovery lock refuses multiple unresolved operations and concurrent writers'] = function () {
    [$s,$r,$p,$m,$store]=fixture();
    foreach(['a','b']as$id)$store->save($s,['id'=>str_repeat($id,24),'status'=>'running','started_at'=>'now']);
    fails(fn()=> $store->recoveryExclusive($s,str_repeat('a',24),fn()=>true),'Multiple');
    [$s,$r,$p,$m,$store]=fixture();
    $store->exclusive($s,function()use($s,$store){fails(fn()=> $store->exclusive($s,fn()=>true),'Another');});
};
$tests['engine recovery records recovery event and unlocks verified operation'] = function () {
    [$s,$r,$p,$m,$store,$engine]=fixture(); $id=str_repeat('c',24);
    $journal=['id'=>$id,'action'=>'config-save','name'=>'a.json','status'=>'running','started_at'=>'now','moves'=>[],
        'manifest_before'=>$m->read($s),'message'=>'Interrupted'];
    $store->save($s,$journal);
    $files=new FileTransaction($r,'.gamenest/mod-manager/operations/'.$id,function($moves)use($s,$store,&$journal){$journal['moves']=$moves;$store->save($s,$journal);});
    seedFiles($r,['Configs/a.json'=>'old',$files->root.'/new'=>'new']);
    $files->move('Configs/a.json',$files->root.'/before'); $files->move($files->root.'/new','Configs/a.json');
    $engine->recoverOperation($s,$id);
    check($r->files['Configs/a.json']==='old' && $store->unresolved($s)===[],'Recovery did not unlock');
    check($store->history($s)[0]['action']==='recovery','Recovery event missing');
};

$tests['third-party adapter participates in shared discovery adoption config and lifecycle'] = function () {
    [$s,$r,$p,$m,$store]=fixture(); $s->egg->name='Independent Test Game';
    $adapter=new class extends \GameNest\GameNestModManager\Adapters\AbstractGameAdapter {
        public function key(): string { return 'independent'; }
        public function name(): string { return 'Independent Test Game'; }
        protected function eggIdentifiers(): array { return ['independent']; }
        public function sources(): array { return []; }
        public function modDirectories(): array { return ['/Extensions']; }
        public function configDirectories(): array { return ['/Settings']; }
        public function scanRules(Server $server): array { return [['root'=>'Extensions','patterns'=>['*.pkg'],'depth'=>1]]; }
    };
    $registry=new class($adapter) extends AdapterRegistry {
        public function __construct(private $adapter) {}
        public function forServer(Server $server): ?\GameNest\GameNestModManager\Contracts\GameAdapter { return $this->adapter; }
    };
    $GLOBALS['services'][AdapterRegistry::class]=$registry;
    seedFiles($r,['Extensions/Test.pkg'=>'independent bytes','Settings/Test.ini'=>'value=1']);
    check(count((new ManagedFileScanner)->scan($s))===1,'Independent scanner failed');
    (new ExistingModService)->adopt($s,['Extensions/Test.pkg']);
    check(count((new ConfigManagementService)->listing($s))===1,'Independent config roots failed');
    $engine=new ModLifecycleService($registry,$m,$store);
    $key=array_key_first($m->mods($s)); $engine->run($s,'disable',$key); $engine->run($s,'enable',$key);
    check($r->files['Extensions/Test.pkg']==='independent bytes','Independent lifecycle failed');
};
$tests['failed repair is audited as repair and corrupted journals block writes'] = function () {
    [$s,$r,$p,$m,$store,$engine]=adoptedFixture(); $key=array_key_first($m->mods($s));
    foreach(array_keys($r->files)as$path)if(str_starts_with($path,'.gamenest/mod-manager/adopted/'))unset($r->files[$path]);
    fails(fn()=> $engine->repair($s,$key)); check($store->history($s)[0]['action']==='repair','Failed repair mislabeled');
    file_put_contents($store->directory($s).'/'.str_repeat('d',24).'.json','{"status":"unknown"}');
    fails(fn()=> $store->exclusive($s,fn()=>true),'Invalid operation journal');
};
