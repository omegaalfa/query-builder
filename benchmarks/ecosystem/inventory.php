<?php
declare(strict_types=1);
$file=__DIR__.'/results/environment.json';
$env=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
foreach(['pdo','pdo_mysql','pdo_pgsql','pdo_sqlite','redis','Zend OPcache'] as $extension)$env['extension_versions'][$extension]=phpversion($extension);
foreach(['opcache.enable','opcache.enable_cli','opcache.jit','opcache.jit_buffer_size','memory_limit','zend.assertions','error_reporting'] as $key)$env['ini'][$key]=ini_get($key);
$env['ini_files']=['loaded'=>php_ini_loaded_file(),'scanned'=>php_ini_scanned_files()];
$env['timestamp_utc']=gmdate(DATE_ATOM);
$env['source_sha256']=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/project/src',FilesystemIterator::SKIP_DOTS)) as $f){if($f->getExtension()==='php')$env['source_sha256'][substr($f->getPathname(),9)]=hash_file('sha256',$f->getPathname());}
ksort($env['source_sha256']);
$env['benchmark_lock_sha256']=hash_file('sha256',__DIR__.'/composer.lock');
file_put_contents($file,json_encode($env,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));