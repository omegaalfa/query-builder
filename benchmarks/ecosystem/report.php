<?php
declare(strict_types=1);
$dir=__DIR__.'/results';$groups=[];
foreach(file($dir.'/samples.jsonl',FILE_IGNORE_NEW_LINES) as $line){$r=json_decode($line,true,flags:JSON_THROW_ON_ERROR);$groups[$r['db']][$r['scenario']][$r['name']][]=$r;}
function percentile(array $v,float $p): float {sort($v);$x=(count($v)-1)*$p;$lo=(int)floor($x);$hi=(int)ceil($x);return $v[$lo]+($v[$hi]-$v[$lo])*($x-$lo);}
function stats(array $v): array {$mean=array_sum($v)/count($v);return ['median'=>percentile($v,.5),'p95'=>percentile($v,.95),'sd'=>sqrt(array_sum(array_map(fn($x)=>($x-$mean)**2,$v))/count($v))];}
$md="# Resultados do benchmark\n\nResultados descritivos; não são testes de significância nem uma classificação geral. Consulte [metodologia](../README.md). Tempos em µs por chamada; throughput em chamadas/s (uma chamada de batch/transação representa o lote inteiro). Memória é incremento de pico PHP por amostra, em KiB, com conexões e dataset fora da medição.\n\n";
$csv=fopen($dir.'/summary.csv','w');fputcsv($csv,['database','scenario','library','rank','samples','median_us','p95_us','sd_us','ops_s','peak_KiB','retained_KiB','round1_us','round2_us','round3_us','round_medians_sd_us'],escape:'');
$summary=[];
foreach($groups as $db=>$scenarios){$md.="## $db\n\n| Cenário | Biblioteca | Posição | Mediana µs | p95 µs | DP µs | Chamadas/s | Pico KiB | DP medianas rodadas µs |\n|---|---|---:|---:|---:|---:|---:|---:|---:|\n";
foreach($scenarios as $scenario=>$libraries){$rows=[];
foreach($libraries as $name=>$samples){if(count($samples)!==15)throw new RuntimeException('Incomplete samples');$s=stats(array_map(fn($r)=>$r['ns']/1000,$samples));$rounds=[];foreach(array_chunk($samples,5) as $round)$rounds[]=percentile(array_map(fn($r)=>$r['ns']/1000,$round),.5);
$rows[$name]=$s+['peak'=>percentile(array_column($samples,'peak'),.5)/1024,'retained'=>percentile(array_column($samples,'retained'),.5)/1024,'rounds'=>$rounds,'roundsd'=>stats($rounds)['sd']];}
uasort($rows,fn($a,$b)=>$a['median']<=>$b['median']);$rank=0;
foreach($rows as $name=>$s){$rank++;$ops=1e6/$s['median'];$md.=sprintf("| %s | %s | %d | %.2f | %.2f | %.2f | %.1f | %.2f | %.2f |\n",$scenario,$name,$rank,$s['median'],$s['p95'],$s['sd'],$ops,$s['peak'],$s['roundsd']);fputcsv($csv,[$db,$scenario,$name,$rank,15,$s['median'],$s['p95'],$s['sd'],$ops,$s['peak'],$s['retained'],...$s['rounds'],$s['roundsd']],escape:'');}
$summary[$db][$scenario]=$rows;
}$md.="\n";}
$md.="## Posição observada do Omegaalfa\n\nPosições incluem PDO quando medido; ausência de outra biblioteca não conta como vitória sobre ela. Diferenças pequenas podem refletir ruído.\n\n";
foreach($summary as $db=>$scenarios){$wins=[];$loss=[];foreach($scenarios as $scenario=>$rows){$keys=array_keys($rows);if($keys[0]==='Omegaalfa')$wins[]=$scenario;else $loss[]=$scenario.' (menor mediana: '.$keys[0].')';}$md.="**$db** — menor mediana: ".($wins?implode(', ',$wins):'nenhum cenário').".\n\nFicou atrás: ".implode(', ',$loss).".\n\n";}
file_put_contents($dir.'/REPORT.md',$md);file_put_contents($dir.'/summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($csv);
echo "Report generated\n";