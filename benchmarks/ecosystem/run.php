<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Doctrine\DBAL\DriverManager;
use Illuminate\Database\Capsule\Manager;

// All persistent changes are confined to the disposable Compose databases.
if (!is_file('/.dockerenv') || extension_loaded('xdebug')) { throw new RuntimeException('Run inside the benchmark container without Xdebug.'); }
$names=['PDO','Omegaalfa','Doctrine','Illuminate','Cycle','Medoo'];
$output=__DIR__.'/results';
if (!is_dir($output)) mkdir($output);
$samples=fopen($output.'/samples.jsonl','w');
$proofs=[]; $environment=['php'=>PHP_VERSION,'architecture'=>php_uname(),'cpu'=>file_get_contents('/proc/cpuinfo'),'memory'=>file_get_contents('/proc/meminfo'),'opcache_cli'=>ini_get('opcache.enable_cli'),'jit'=>ini_get('opcache.jit'),'memory_limit'=>ini_get('memory_limit'),'extensions'=>get_loaded_extensions(),'packages'=>[]];
foreach(['doctrine/dbal','illuminate/database','cycle/database','catfan/medoo'] as $package) $environment['packages'][$package]=Composer\InstalledVersions::getPrettyVersion($package);
foreach(['/sys/fs/cgroup/memory.max','/sys/fs/cgroup/cpu.max'] as $file) $environment[$file]=is_file($file)?trim(file_get_contents($file)):null;

final class Adapter {
    public mixed $client;
    public PDO $pdo;
    public function __construct(public string $name,public string $driver,public bool $buffered=true) {
        $host=$driver==='mysql'?'mysql':'postgres';
        $port=$driver==='mysql'?3306:5432;
        $path='/bench/benchmark.sqlite';
        $dsn=$driver==='sqlite'?'sqlite:'.$path:"$driver:host=$host;port=$port;dbname=benchmark";
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
        if($driver==='pgsql') $options[Pdo\Pgsql::ATTR_DISABLE_PREPARES]=false;
        if($driver==='mysql') $options[Pdo\Mysql::ATTR_USE_BUFFERED_QUERY]=$buffered;
        if($name==='Omegaalfa') {
            $this->client=new PDOConnection(new DatabaseSettings($driver,$host,$driver==='sqlite'?$path:'benchmark',$port,'benchmark','benchmark',options:$options));
            $this->pdo=$this->client->pdo($buffered);
        } elseif($name==='Doctrine') {
            $this->client=DriverManager::getConnection(['driver'=>'pdo_'.$driver,'host'=>$host,'port'=>$port,'dbname'=>'benchmark','user'=>'benchmark','password'=>'benchmark','path'=>$path,'driverOptions'=>$options]);
            $this->pdo=$this->client->getNativeConnection();
        } elseif($name==='Illuminate') {
            $capsule=new Manager();
            $capsule->addConnection(['driver'=>$driver,'host'=>$host,'port'=>$port,'database'=>$driver==='sqlite'?$path:'benchmark','username'=>'benchmark','password'=>'benchmark','charset'=>$driver==='mysql'?'utf8mb4':'utf8','collation'=>'utf8mb4_unicode_ci','prefix'=>'','options'=>$options]);
            $this->client=$capsule->getConnection(); $this->pdo=$this->client->getPdo();
            $this->client->disableQueryLog();
        } elseif($name==='Cycle') {
            $type=match($driver){'mysql'=>'MySQL','pgsql'=>'Postgres','sqlite'=>'SQLite'};
            $conn='Cycle\\Database\\Config\\'.$type.'\\'.($driver==='sqlite'?'FileConnectionConfig':'DsnConnectionConfig');
            $cfg='Cycle\\Database\\Config\\'.$type.'DriverConfig';
            $drv='Cycle\\Database\\Driver\\'.$type.'\\'.$type.'Driver';
            $config=$driver==='sqlite'?new $conn($path,options:$options):new $conn($dsn,'benchmark','benchmark',options:$options);
            $driverObject=$drv::create(new $cfg(connection:$config,queryCache:false));
            $this->client=new Cycle\Database\Database('benchmark','',$driverObject);
            $driverObject->connect();
            $this->pdo=(new ReflectionProperty($driverObject,'pdo'))->getValue($driverObject);
        } else {
            $this->pdo=new PDO($dsn,'benchmark','benchmark',$options);
            $this->client=$name==='Medoo'?new Medoo\Medoo(['pdo'=>$this->pdo,'type'=>$driver==='pgsql'?'pgsql':$driver,'logging'=>false]):$this->pdo;
        }
        if($driver==='sqlite') {$this->pdo->exec('PRAGMA journal_mode=WAL');$this->pdo->exec('PRAGMA synchronous=NORMAL');$this->pdo->exec('PRAGMA busy_timeout=5000');}
        global $environment;
        $attrs=['errmode'=>PDO::ATTR_ERRMODE,'emulate_prepares'=>PDO::ATTR_EMULATE_PREPARES];
        if($driver==='mysql')$attrs['buffered']=Pdo\Mysql::ATTR_USE_BUFFERED_QUERY;
        if($driver==='pgsql')$attrs['disable_prepares']=Pdo\Pgsql::ATTR_DISABLE_PREPARES;
        foreach($attrs as $key=>$attr){try{$actual=$this->pdo->getAttribute($attr);}catch(Throwable){$actual='driver does not expose attribute';}$environment['pdo_attributes'][$driver][$name][$buffered?'buffered':'unbuffered'][$key]=$actual;}
    }
    public function read(string $sql,array $params,bool $stream=false): mixed {
        if($this->name==='Omegaalfa') return (new QueryBuilder($this->client))->raw($sql,$params)->execute($this->buffered)->data;
        if($this->name==='Doctrine') {$r=$this->client->executeQuery($sql,$params);return $stream?$r->iterateAssociative():$r->fetchAllAssociative();}
        if($this->name==='Illuminate') return $stream?$this->client->cursor($sql,$params):$this->client->select($sql,$params);
        if($this->name==='Cycle') {$r=$this->client->query($sql,$params);return $stream?$r:$r->fetchAll();}
        if($this->name==='Medoo') {
            $map=[]; foreach($params as $i=>$v){$sql=preg_replace('/\?/',':b'.$i,$sql,1);$map[':b'.$i]=$v;}
            $r=$this->client->query($sql,$map);
        } else {$r=$this->pdo->prepare($sql);$r->execute($params);}
        $r->setFetchMode(PDO::FETCH_ASSOC);
        return $stream?$r:$r->fetchAll(PDO::FETCH_ASSOC);
    }
    public function nativeRead(string $scenario): array {
        $join=$scenario==='join';$fields=$join?['items.id','bench_groups.label']:['id','score'];
        $limit=match($scenario){'rows100'=>100,'rows1000'=>1000,default=>null};
        $conditions=match($scenario){'pk'=>[['items.id','=',5000]],'filters'=>[['items.score','>=',20],['items.score','<',30]],'join'=>[['items.id','<=',100]],default=>[]};
        if($this->name==='PDO'){
            $sql='SELECT '.implode(', ',$fields).' FROM items'.($join?' INNER JOIN bench_groups ON items.group_id = bench_groups.id':'');$params=[];
            if($conditions){$sql.=' WHERE '.implode(' AND ',array_map(fn($c)=>$c[0].' '.$c[1].' ?',$conditions));$params=array_column($conditions,2);}
            $sql.=' ORDER BY items.id';if($limit)$sql.=' LIMIT '.$limit;return $this->read($sql,$params);
        }
        if($this->name==='Medoo'){
            $where=['ORDER'=>['items.id'=>'ASC']];foreach($conditions as [$column,$op,$value])$where[$column.($op==='='?'':'['.$op.']')]=$value;
            if($limit)$where['LIMIT']=$limit;
            return $join?$this->client->select('items',['[><]bench_groups'=>['group_id'=>'id']],$fields,$where):$this->client->select('items',$fields,$where);
        }
        if($this->name==='Omegaalfa'){$q=(new QueryBuilder($this->client))->select('items',$fields);if($join)$q->join('bench_groups','items.group_id','=','bench_groups.id');foreach($conditions as $c)$q->where(...$c);$q->orderBy('items.id');if($limit)$q->limit($limit);return consume($q->execute()->data,false);}
        if($this->name==='Doctrine'){$q=$this->client->createQueryBuilder()->select(...$fields)->from('items');if($join)$q->innerJoin('items','bench_groups','bench_groups','items.group_id = bench_groups.id');foreach($conditions as $i=>$c)$q->andWhere($c[0].' '.$c[1].' ?')->setParameter($i,$c[2]);$q->orderBy('items.id');if($limit)$q->setMaxResults($limit);return $q->executeQuery()->fetchAllAssociative();}
        if($this->name==='Illuminate'){$q=$this->client->table('items')->select($fields);if($join)$q->join('bench_groups','items.group_id','=','bench_groups.id');foreach($conditions as $c)$q->where(...$c);$q->orderBy('items.id');if($limit)$q->limit($limit);return consume($q->get(),false);}
        $q=$this->client->select($fields)->from('items');if($join)$q->innerJoin('bench_groups')->on('items.group_id','=','bench_groups.id');foreach($conditions as $c)$q->where(...$c);$q->orderBy('items.id');if($limit)$q->limit($limit);return $q->fetchAll();
    }
    public function nativeWrite(string $scenario): void {
        if($this->name==='PDO'){$this->write(match($scenario){'insert'=>'INSERT INTO writes (id, score) VALUES (?, ?)','update'=>'UPDATE writes SET score = ? WHERE id = ?','delete'=>'DELETE FROM writes WHERE id = ?'},match($scenario){'insert'=>[20001,7],'update'=>[7,20001],'delete'=>[20001]});return;}
        if($this->name==='Medoo'){match($scenario){'insert'=>$this->client->insert('writes',['id'=>20001,'score'=>7]),'update'=>$this->client->update('writes',['score'=>7],['id'=>20001]),'delete'=>$this->client->delete('writes',['id'=>20001])};return;}
        if($this->name==='Illuminate'){$q=$this->client->table('writes');match($scenario){'insert'=>$q->insert(['id'=>20001,'score'=>7]),'update'=>$q->where('id',20001)->update(['score'=>7]),'delete'=>$q->where('id',20001)->delete()};return;}
        [, $q]=$this->build($scenario,false);
        if($this->name==='Omegaalfa')$q->execute();elseif($this->name==='Doctrine')$q->executeStatement();else $q->run();
    }
    public function write(string $sql,array $params): void {
        if($this->name==='Omegaalfa') {(new QueryBuilder($this->client))->raw($sql,$params)->execute();return;}
        if($this->name==='Doctrine') {$this->client->executeStatement($sql,$params);return;}
        if($this->name==='Illuminate') {$this->client->statement($sql,$params);return;}
        if($this->name==='Cycle') {$this->client->execute($sql,$params);return;}
        if($this->name==='Medoo') {$map=[];foreach($params as $i=>$v){$sql=preg_replace('/\?/',':b'.$i,$sql,1);$map[':b'.$i]=$v;}$this->client->query($sql,$map);return;}
        $r=$this->pdo->prepare($sql);$r->execute($params);
    }
    public function transaction(string $action): void {
        if($this->name==='Omegaalfa'||$this->name==='Doctrine') {$this->client->{match($action){'begin'=>'beginTransaction','commit'=>'commit','rollback'=>'rollBack'}}();return;}
        if($this->name==='Illuminate') {$this->client->{match($action){'begin'=>'beginTransaction','commit'=>'commit','rollback'=>'rollBack'}}();return;}
        if($this->name==='Cycle') {$this->client->{$action}();return;}
        $this->pdo->{match($action){'begin'=>'beginTransaction','commit'=>'commit','rollback'=>'rollBack'}}();
    }
    public function build(string $scenario,bool $compile=true): array {
        $where=$scenario!=='simple'; $join=in_array($scenario,['join','complex'],true); $complex=$scenario==='complex';
        $fields=$join?['items.id','bench_groups.label']:['id','score'];
        $dml=in_array($scenario,['insert','update','delete'],true);
        if($this->name==='Omegaalfa') {
            $q=new QueryBuilder($this->client);
            if($scenario==='insert')$q->insert('writes',['id'=>20001,'score'=>7]);
            elseif($scenario==='update')$q->update('writes',['score'=>7])->where('id','=',20001);
            elseif($scenario==='delete')$q->delete('writes')->where('id','=',20001);
            else {$q->select('items',$fields);if($join)$q->join('bench_groups','items.group_id','=','bench_groups.id');if($where)$q->where('items.score','>=',20)->where('items.score','<',30);if($complex)$q->orderBy('items.id')->limit(10,5);}
            $sql=$compile?$q->getQuerySql():'';
            return [$sql,$q];
        }
        if($this->name==='Doctrine') {
            $q=$this->client->createQueryBuilder();
            if($scenario==='insert')$q->insert('writes')->values(['id'=>'?','score'=>'?'])->setParameters([20001,7]);
            elseif($scenario==='update')$q->update('writes')->set('score','?')->where('id = ?')->setParameters([7,20001]);
            elseif($scenario==='delete')$q->delete('writes')->where('id = ?')->setParameters([20001]);
            else {$q->select(...$fields)->from('items');if($join)$q->innerJoin('items','bench_groups','bench_groups','items.group_id = bench_groups.id');if($where)$q->where('items.score >= ?')->andWhere('items.score < ?')->setParameters([20,30]);if($complex)$q->orderBy('items.id')->setMaxResults(10)->setFirstResult(5);}
            return [$compile?$q->getSQL():'',$q];
        }
        if($this->name==='Illuminate') {
            $q=$this->client->table($dml?'writes':'items'); $g=$q->getGrammar();
            if($scenario==='insert')return [$g->compileInsert($q,[['id'=>20001,'score'=>7]]),[20001,7]];
            if($scenario==='update'){$q->where('id',20001);return [$g->compileUpdate($q,['score'=>7]),[7,20001]];}
            if($scenario==='delete'){$q->where('id',20001);return [$g->compileDelete($q),[20001]];}
            $q->select($fields);if($join)$q->join('bench_groups','items.group_id','=','bench_groups.id');if($where)$q->where('items.score','>=',20)->where('items.score','<',30);if($complex)$q->orderBy('items.id')->limit(10)->offset(5);
            return [$compile?$q->toSql():'',$q];
        }
        if($this->name==='Cycle') {
            if($scenario==='insert')$q=$this->client->insert('writes')->values(['id'=>20001,'score'=>7]);
            elseif($scenario==='update')$q=$this->client->update('writes',['score'=>7])->where('id',20001);
            elseif($scenario==='delete')$q=$this->client->delete('writes')->where('id',20001);
            else {$q=$this->client->select($fields)->from('items');if($join)$q->innerJoin('bench_groups')->on('items.group_id','=','bench_groups.id');if($where)$q->where('items.score','>=',20)->where('items.score','<',30);if($complex)$q->orderBy('items.id')->limit(10)->offset(5);}
            return [$compile?$q->sqlStatement():'',$q];
        }
        throw new RuntimeException('No equivalent public compiler');
    }
    public function bindings(array $compiled): array {
        $q=$compiled[1];
        if(is_array($q))return $q;
        if($this->name==='Omegaalfa')return array_values((new ReflectionProperty($q,'params'))->getValue($q));
        if($this->name==='Doctrine')return array_values($q->getParameters());
        if($this->name==='Illuminate')return $q->getBindings();
        $p=new Cycle\Database\Query\QueryParameters();$q->sqlStatement($p);return array_map(fn($x)=>$x->getValue(),$p->getParameters());
    }
}

function consume(mixed $rows,bool $stream): array|int {
    $out=[];$sum=0;
    foreach($rows as $row){$row=(array)$row;if($stream)$sum+=(int)$row['id'];else $out[]=$row;}
    return $stream?$sum:$out;
}
function measure(string $db,string $scenario,array $ops,callable $reset,callable $verify,int $iterations=1): void {
    global $samples;
    foreach($ops as $name=>$fn){$reset($name);$value=$fn();$verify($value,$name);unset($value);}
    $names=array_keys($ops);
    for($sample=0;$sample<15;$sample++){
        $order=array_merge(array_slice($names,$sample%count($names)),array_slice($names,0,$sample%count($names)));
        if(intdiv($sample,count($names))%2)$order=array_reverse($order);
        foreach($order as $name){
            $reset($name);gc_collect_cycles();memory_reset_peak_usage();$base=memory_get_usage(false);$t=hrtime(true);
            for($i=0;$i<$iterations;$i++){$value=$ops[$name]();}
            $ns=(hrtime(true)-$t)/$iterations;$peak=memory_get_peak_usage(false)-$base;$retained=memory_get_usage(false)-$base;
            $verify($value,$name);unset($value);
            fwrite($samples,json_encode(compact('db','scenario','name','sample','ns','peak','retained','iterations'),JSON_THROW_ON_ERROR)."\n");
        }
    }
    echo "$db $scenario OK\n";
}
function check(bool $condition): void {if(!$condition)throw new RuntimeException('Equivalence check failed');}

foreach(['sqlite','mysql','pgsql'] as $db){
    if($db==='sqlite')touch('/bench/benchmark.sqlite');
    $a=[];foreach($names as $name)$a[$name]=new Adapter($name,$db);
    $p=$a['PDO']->pdo;
    $environment['databases'][$db]=['server'=>$p->getAttribute(PDO::ATTR_SERVER_VERSION),'client'=>$p->getAttribute(PDO::ATTR_CLIENT_VERSION)];
    foreach(['items','bench_groups','writes'] as $table)$p->exec("DROP TABLE IF EXISTS $table");
    $p->exec('CREATE TABLE bench_groups (id INTEGER PRIMARY KEY, label VARCHAR(40))');
    $p->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, score INTEGER, group_id INTEGER)');
    $p->exec('CREATE INDEX items_score ON items (score)');$p->exec('CREATE INDEX items_group ON items (group_id)');
    $p->exec('CREATE TABLE writes (id INTEGER PRIMARY KEY, score INTEGER)');
    $p->beginTransaction();$s=$p->prepare('INSERT INTO bench_groups VALUES (?, ?)');for($i=1;$i<=10;$i++)$s->execute([$i,'group'.$i]);
    $s=$p->prepare('INSERT INTO items VALUES (?, ?, ?)');for($i=1;$i<=10000;$i++)$s->execute([$i,$i%100,$i%10+1]);$p->commit();
    foreach(['simple','filters','join','complex','insert','update','delete'] as $scenario){
        $ops=[];$baseline=null;
        foreach(['Omegaalfa','Doctrine','Illuminate','Cycle'] as $name){
            $compiled=$a[$name]->build($scenario);$bindings=$a[$name]->bindings($compiled);
            $proofs[$db][$scenario][$name]=['sql'=>$compiled[0],'bindings'=>$bindings];
            if(in_array($scenario,['insert','update','delete'])){
                $p->exec('DELETE FROM writes');if($scenario!=='insert')$p->exec('INSERT INTO writes VALUES (20001, 0)');
                $stmt=$p->prepare($compiled[0]);$stmt->execute($bindings);$actual=$p->query('SELECT * FROM writes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            }else{$stmt=$p->prepare($compiled[0]);$stmt->execute($bindings);$actual=$stmt->fetchAll(PDO::FETCH_ASSOC);}
            if($baseline===null)$baseline=$actual;else check($actual==$baseline);
            $ops[$name]=fn()=>$a[$name]->build($scenario);
        }
        measure($db,'build_'.$scenario,$ops,fn($n)=>null,fn($v,$n)=>check(is_string($v[0])),1000);
    }
    foreach(['pk','filters','rows100','rows1000','join'] as $scenario){
        $expected=$a['PDO']->nativeRead($scenario);$ops=[];
        foreach($names as $name)$ops[$name]=fn()=>$a[$name]->nativeRead($scenario);
        measure($db,'native_'.$scenario,$ops,fn($n)=>null,fn($v,$n)=>check($v==$expected));
    }
    $queries=[
        'pk'=>['SELECT id, score FROM items WHERE id = ?',[5000]],
        'filters'=>['SELECT id, score FROM items WHERE score >= ? AND score < ? ORDER BY id',[20,30]],
        'rows100'=>['SELECT id, score FROM items ORDER BY id LIMIT 100',[]],
        'rows1000'=>['SELECT id, score FROM items ORDER BY id LIMIT 1000',[]],
        'join'=>['SELECT items.id, bench_groups.label FROM items INNER JOIN bench_groups ON items.group_id = bench_groups.id WHERE items.id <= ? ORDER BY items.id',[100]],
        'materialize10000'=>['SELECT id, score FROM items ORDER BY id',[]],
        'iterate10000'=>['SELECT id, score FROM items ORDER BY id',[]],
    ];
    foreach($queries as $scenario=>[$sql,$params]){
        $stream=$scenario==='iterate10000';$expected=consume($a['PDO']->read($sql,$params,$stream),$stream);$ops=[];
        foreach($names as $name)$ops[$name]=fn()=>consume($a[$name]->read($sql,$params,$stream),$stream);
        measure($db,'execute_'.$scenario,$ops,fn($n)=>null,fn($v,$n)=>check($v==$expected),1);
    }
    $sql='SELECT id, score FROM items ORDER BY id LIMIT 1000';
    $expected=consume($a['PDO']->read($sql,[],false),false);
    $ops=[];
    foreach(array_diff($names,['Illuminate']) as $name)$ops[$name]=fn()=>$a[$name]->read($sql,[],true);
    measure($db,'phase_execute1000',$ops,fn($n)=>null,fn($v,$n)=>check(consume($v,false)==$expected));
    $cursors=[];$ops=[];
    foreach(array_diff($names,['Illuminate']) as $name)$ops[$name]=function()use(&$cursors,$name){return consume($cursors[$name],false);};
    measure($db,'phase_fetch1000',$ops,function($n)use(&$cursors,$a,$sql){$cursors[$n]=$a[$n]->read($sql,[],true);},fn($v,$n)=>check($v==$expected));
    unset($cursors);
    $unbuffered=[];$ops=[];
    foreach($names as $name){$unbuffered[$name]=new Adapter($name,$db,false);$ops[$name]=fn()=>consume($unbuffered[$name]->read('SELECT id, score FROM items ORDER BY id',[],true),true);}
    measure($db,'execute_unbuffered10000',$ops,fn($n)=>null,fn($v,$n)=>check($v===50005000));
    unset($unbuffered);
    foreach(['insert','update','delete'] as $scenario){
        $sql=match($scenario){'insert'=>'INSERT INTO writes (id, score) VALUES (?, ?)','update'=>'UPDATE writes SET score = ? WHERE id = ?','delete'=>'DELETE FROM writes WHERE id = ?'};
        $params=match($scenario){'insert'=>[20001,7],'update'=>[7,20001],'delete'=>[20001]};$ops=[];
        foreach($names as $name)$ops[$name]=fn()=>$a[$name]->write($sql,$params);
        $reset=function($n)use($p,$scenario){$p->exec('DELETE FROM writes');if($scenario!=='insert')$p->exec('INSERT INTO writes VALUES (20001, 0)');};
        $verify=function($v,$n)use($p,$scenario){$r=$p->query('SELECT score FROM writes')->fetchAll(PDO::FETCH_COLUMN);check($r==($scenario==='delete'?[]:[7]));};
        measure($db,'execute_'.$scenario,$ops,$reset,$verify);
        $ops=[];foreach($names as $name)$ops[$name]=fn()=>$a[$name]->nativeWrite($scenario);
        measure($db,'native_'.$scenario,$ops,$reset,$verify);
    }
    foreach([100,1000,10000] as $size){
        $rows=[];for($i=1;$i<=$size;$i++)$rows[]=['id'=>$i,'score'=>7];
        $ops=[];foreach($names as $name)$ops[$name]=function()use($a,$name,$rows){
            if($name==='Omegaalfa'){(new QueryBuilder($a[$name]->client))->insertBatch('writes',$rows)->execute();return;}
            if($name==='Illuminate'){$a[$name]->client->table('writes')->insert($rows);return;}
            if($name==='Cycle'){$a[$name]->client->insert('writes')->values($rows)->run();return;}
            if($name==='Medoo'){$a[$name]->client->insert('writes',$rows);return;}
            $sql='INSERT INTO writes (id, score) VALUES '.implode(',',array_fill(0,count($rows),'(?, ?)'));
            $params=[];foreach($rows as $r){$params[]=$r['id'];$params[]=$r['score'];}$a[$name]->write($sql,$params);
        };
        if($db==='sqlite' && $size>=1000) {
            unset($ops['Cycle']);
            $proofs['unsupported']['sqlite']['Cycle']['batch_'.$size]='Native batch generates compound SELECT exceeding SQLite limit; reproduced, no custom chunking applied.';
        }
        measure($db,'batch_'.$size,$ops,fn($n)=>$p->exec('DELETE FROM writes'),fn($v,$n)=>check((int)$p->query('SELECT COUNT(*) FROM writes WHERE score = 7')->fetchColumn()===$size));
    }
    foreach([10,100] as $size)foreach(['commit','rollback','savepoint'] as $mode){
        $ops=[];foreach($names as $name)$ops[$name]=function()use($a,$name,$size,$mode){
            $x=$a[$name];$x->transaction('begin');
            if($mode==='savepoint')$x->write('SAVEPOINT bench_sp',[]);
            for($i=1;$i<=$size;$i++)$x->write('INSERT INTO writes (id, score) VALUES (?, ?)',[$i,7]);
            if($mode==='savepoint'){$x->write('ROLLBACK TO SAVEPOINT bench_sp',[]);$x->write('RELEASE SAVEPOINT bench_sp',[]);}
            $x->transaction($mode==='rollback'?'rollback':'commit');
        };
        measure($db,'tx_'.$mode.'_'.$size,$ops,fn($n)=>$p->exec('DELETE FROM writes'),fn($v,$n)=>check((int)$p->query('SELECT COUNT(*) FROM writes')->fetchColumn()===($mode==='commit'?$size:0)));
    }
    unset($a);
}
file_put_contents($output.'/environment.json',json_encode($environment,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents($output.'/equivalence.json',json_encode($proofs,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
fclose($samples);
