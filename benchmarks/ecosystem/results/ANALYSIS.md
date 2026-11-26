# Interpretação dos resultados

Foram aceitas 9.240 amostras, cobrindo 37 cenários por banco e 616 combinações banco/cenário/biblioteca. Cada combinação contém 15 amostras. Todos os resultados incluídos passaram pelas verificações implementadas no runner. A comparação é descritiva, sem teste de significância.

## Ambiente e versões

PHP 8.4.27 CLI, Linux/WSL2 x86_64, AMD Ryzen 7 5700X (8 núcleos, 16 processadores lógicos), aproximadamente 7,7 GiB de memória visível. Sem quotas de CPU/memória ou afinidade explícitas nos containers; PHP limitado a 512 MiB. Xdebug ausente; OPcache CLI desativado. Bancos em rede Docker própria, MySQL/PostgreSQL com dados em tmpfs. SQLite em arquivo no volume do benchmark, WAL/NORMAL.

| Componente | Versão |
|---|---|
| Omegaalfa | Working tree sobre HEAD registrado em git-head.txt; hashes do src no inventário |
| Doctrine DBAL | 4.5.1 |
| Illuminate Database | 13.42.0 |
| Cycle Database | 2.23.3 |
| Medoo | 2.6.1 |
| MySQL / mysqlnd | 8.4.10 / 8.4.27 |
| PostgreSQL / libpq | 17.12 / 17.11 |
| SQLite | 3.46.1 |

As opções PDO consultáveis foram registradas por biblioteca. PostgreSQL usa prepares habilitados em todos os clientes, sobrescrevendo o padrão distinto do DBAL. Resultados preliminares anteriores a esse alinhamento foram descartados.

## Onde Omegaalfa teve menor mediana relativa

Na construção de SELECT complexo, Omegaalfa ficou à frente de Cycle e Illuminate em todos os bancos, mas atrás de Doctrine:

| Banco | Doctrine µs | Omegaalfa µs | Cycle µs | Illuminate µs |
|---|---:|---:|---:|---:|
| SQLite | 3,63 | 6,38 | 19,91 | 25,25 |
| MySQL | 3,44 | 5,74 | 19,59 | 24,89 |
| PostgreSQL | 3,44 | 6,09 | 19,06 | 24,88 |

Em MySQL, a mediana de construção do Omegaalfa foi aproximadamente 76,9% menor que a do Illuminate nesse cenário. Essa diferença mede construção/compilação, não latência da consulta no banco.

Omegaalfa também apresentou menor mediana que Illuminate no SELECT nativo de 1.000 linhas nos três bancos: 235,24 vs 254,80 µs em SQLite; 588,50 vs 615,93 µs em MySQL; 740,49 vs 824,31 µs em PostgreSQL. Diferenças observadas não são declarações de significância estatística.

Incluindo PDO e todos os concorrentes presentes, Omegaalfa não obteve a menor mediana absoluta de nenhum cenário nesta execução. Isso não impede vantagens relativas a bibliotecas específicas, nem constitui conclusão sobre outros workloads.

## Onde ficou atrás

O custo mais elevado observado foi o batch nativo de 10.000 registros:

| Banco | PDO ms | Doctrine ms | Illuminate ms | Omegaalfa ms | Omegaalfa lotes/s |
|---|---:|---:|---:|---:|---:|
| SQLite | 5,52 | 7,21 | 12,01 | 85,82 | 11,65 |
| MySQL | 40,94 | 44,51 | 47,48 | 956,88 | 1,05 |
| PostgreSQL | 29,89 | 31,51 | 38,17 | 665,39 | 1,50 |

Esses cenários produzem o mesmo conteúdo, mas não usam a mesma estratégia: PDO/DBAL recebem multivalues montado pelo runner; SQLite/Omegaalfa divide em blocos e usa transação. Não se atribui toda a diferença a um único componente.

No batch SQLite de 10.000, Omegaalfa foi mais rápido que Medoo (1.142,32 ms), mas mais lento que PDO, DBAL e Illuminate. Cycle foi excluído porque seu SQL nativo ultrapassa o limite de compound SELECT, e não por uma escolha de tamanho de chunk do runner.

## Memória

No batch SQLite de 10.000 linhas, o incremento mediano de pico PHP foi 888 KiB no Omegaalfa, contra 2.979 KiB em PDO e 6.608 KiB no Illuminate. A estratégia de blocos do Omegaalfa limita a materialização intermediária; a medição exclui as linhas de entrada já construídas.

No MySQL, Omegaalfa materializando 10.000 linhas teve incremento de 4.291 KiB, contra 40,2 KiB ao percorrer o fluxo sem buffering e acumular apenas uma soma de IDs. São formas diferentes de consumo, explicitamente separadas nos rankings.

Construir 1.000 SELECTs complexos em uma amostra produziu pico incremental próximo de 4.376 KiB no Omegaalfa. Esse valor inclui objetos pendentes de GC, não é memória por consulta nem RSS. Dados dos demais casos, retenção e dispersão constam no CSV. Retenção da última instrução em alguns adaptadores pode reduzir o incremento aparente em relação ao baseline.

## Evidências e hipóteses de gargalos

**Comprovados neste experimento:** limites do batch Cycle/SQLite; estratégias diferentes de batch; custo total maior do Omegaalfa nos batches grandes; menor pico incremental de seu batch SQLite; redução de materialização ao percorrer um Generator. Esses resultados não identificam, sozinhos, a causa de cada tempo.

**Hipóteses apoiadas pelo código, sem atribuição causal medida:** `SqlCompilationContext::bind()` copia o array de parâmetros para uma variável local antes de modificá-lo e reatribuí-lo. Copy-on-write repetido é um candidato para o crescimento do custo de batches grandes. O contexto de compilação também guarda uma closure ligada ao builder; ciclos de referências e GC são candidatos para o pico de memória de construção repetida. Nenhum perfilador ou otimização foi utilizado para provar a participação percentual desses mecanismos.

A conversão de objetos do Illuminate para arrays está incluída para normalizar a saída; os caminhos Generator do Omegaalfa também incluem iteração PHP. Rede e driver dominam parte dos SELECTs pequenos em MySQL/PostgreSQL. Não há medição isolada que permita repartir quantitativamente esses custos.

## Artefatos

- [Todas as tabelas, p95, dispersão, throughput e rankings por cenário](REPORT.md).
- [CSV com memória retida e medianas das três rodadas](summary.csv).
- [Amostras individuais](samples.jsonl).
- [SQL e bindings verificados](equivalence.json).
- [Ambiente, opções PDO e hashes](environment.json).
- [Validação do projeto](VALIDATION.md).

As limitações de disco/tmpfs, GC, ordem, warmup, normalização, configurações e ausência de testes estatísticos estão na [metodologia](../README.md). Os números não sustentam superioridade geral de nenhuma biblioteca.
