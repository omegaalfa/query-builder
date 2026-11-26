# Comparação isolada do ecossistema PHP

O ambiente usa uma rede Compose exclusiva, bancos descartáveis em tmpfs e nenhum porto publicado. Não utiliza os bancos do Compose principal. Os dados artificiais são recriados pelo runner. Não execute este script com configurações de conexão diferentes das constantes do próprio ambiente.

## Reprodução

Na raiz do projeto:

```sh
docker compose -f benchmarks/ecosystem/compose.yml run --build --rm runner composer install --no-interaction --prefer-dist
docker compose -f benchmarks/ecosystem/compose.yml run --rm runner php -d opcache.enable_cli=0 -d memory_limit=512M run.php
docker compose -f benchmarks/ecosystem/compose.yml run --rm runner php -d opcache.enable_cli=0 -d memory_limit=512M inventory.php
docker compose -f benchmarks/ecosystem/compose.yml run --rm runner php report.php
```

`composer.lock` fixa as dependências exclusivas deste diretório. A imagem PHP é construída a partir do Dockerfile de testes existente; o autoloader usado é o deste benchmark, com o código Omegaalfa montado em modo somente leitura. Xdebug não pode estar carregado. Nenhum cache Redis participa dos cenários. Para encerrar apenas este ambiente: `docker compose -f benchmarks/ecosystem/compose.yml down` (os bancos tmpfs são descartados).

## Escopo e equivalência

- `build_*`: construção e compilação com APIs nativas de Omegaalfa, DBAL, Illuminate e Cycle; nenhuma execução durante o intervalo. Illuminate usa o compilador de sua gramática para DML. PDO não tem builder e Medoo não oferece uma API pública equivalente de compilação com bindings sem efeitos de execução/debug; ambos ficam fora desse ranking.
- `native_*`: builder nativo até fetch/materialização, ou DML nativo; PDO monta SQL parametrizado como referência. Identificadores artificiais são estáticos. Não são comparados Eloquent ORM, entidades ou hidratação de domínio.
- `execute_*`: SQL parametrizado comum executado pelas APIs de baixo nível das seis bibliotecas. É uma comparação de execução/fetch, não de construção fluente. Medoo exige remapeamento de `?` para nomes no adaptador; esse custo está incluído e não deve ser atribuído exclusivamente à biblioteca.
- `phase_execute1000` / `phase_fetch1000`: etapas separadas onde a API expõe cursor. Illuminate fica de fora: `select()` materializa, e `cursor()` adia a execução. O tempo de fetch inclui normalização para arrays associativos. No MySQL buffered, transferência do servidor já pode ocorrer na fase de execução.
- `materialize10000` materializa 10.000 linhas; `iterate10000` percorre todas sem guardar a coleção. `unbuffered10000` usa conexões dedicadas, PDO MySQL sem buffering e `execute(false)` no Omegaalfa. PostgreSQL PDO ainda pode bufferizar internamente: Generator não equivale a cursor de servidor. SQLite é incremental no driver.
- Batch: APIs nativas para Omegaalfa, Illuminate, Cycle e Medoo. DBAL e PDO recebem um INSERT multivalues gerado pelo adaptador, pois não possuem API batch equivalente nesse runner. Omegaalfa divide SQLite em blocos e coordena transação. Cycle usa compound SELECT em SQLite: 1.000 e 10.000 linhas excederam o limite nativo e foram excluídos sem adaptação. Estratégias e instruções extras não são equivalentes em custo, embora o estado final seja verificado.
- Transações: 10/100 INSERTs parametrizados, seguidos de commit, rollback ou rollback até savepoint e commit. Controle transacional nativo onde disponível; Medoo usa seu PDO exposto. Savepoints usam comandos SQL iguais, não medem especificamente as abstrações de transação aninhada. Uma operação/s significa uma transação completa, não um INSERT.

Antes de medir construção, cada SQL compilado é executado fora do timer com seus bindings e comparado com os demais. `equivalence.json` preserva SQL e bindings. Uma reflexão lê os bindings do Omegaalfa apenas nessa verificação, fora do timer. Os cenários reais validam resultados ou estado final após cada amostra. Dados, índices, conexões, resets e validações ficam fora do intervalo medido. O dataset tem 10.000 linhas e distribuição determinística, com índices iguais.

## Amostragem

Uma execução de aquecimento validada por biblioteca/cenário; 15 amostras, divididas em três rodadas consecutivas de cinco, com rotação e inversão da ordem das bibliotecas. Somente uma operação de biblioteca roda por vez. Construção repete 1.000 vezes por amostra; demais cenários executam uma chamada por amostra. Tempos usam `hrtime(true)`. As rodadas compartilham processo e caches aquecidos; não são três replicações independentes do ambiente.

Mediana, p95 interpolado, desvio-padrão populacional, throughput, memória e variabilidade entre medianas das rodadas estão nos artefatos. O pico usa `memory_reset_peak_usage()`/`memory_get_usage(false)`, descontando o baseline imediatamente anterior. Conexões, classes carregadas e entradas batch já alocadas não entram nesse incremento. Não representa RSS nem memória nativa dos bancos/drivers. Os clientes permanecem carregados no mesmo processo; resultados de memória não comparam o custo de bootstrap isolado de cada pacote.

## Limitações

- Bancos em tmpfs: resultados de escrita não representam durabilidade e latência de disco de produção. SQLite usa WAL/NORMAL; não compare bancos entre si como ranking de motores.
- Sem afinidade de CPU nem quotas explícitas; há ruído do host/WSL e da rede Docker. Os bancos de testes preexistentes podem permanecer ociosos no host, mas testes e benchmarks não são executados simultaneamente.
- PDO nativo com prepares emulados desativados, quando aplicável; MySQL buffered é o padrão salvo cenário unbuffered. Cache de statements do Cycle e query log do Illuminate desativados; caches internos de identificadores permanecem aquecidos.
- PostgreSQL usa `Pdo\Pgsql::ATTR_DISABLE_PREPARES=false` explicitamente em todos os clientes. Isso sobrescreve o padrão diferente do DBAL. Os atributos efetivamente expostos por PDO estão no inventário; atributos não consultáveis são identificados.
- Memória incremental pode ser afetada por objetos retidos entre chamadas (por exemplo, a última instrução do Medoo). O pico de construção corresponde às 1.000 compilações da amostra, incluindo objetos aguardando coleta cíclica, e não deve ser dividido como se fosse alocação independente por consulta.
- Todas as linhas são consumidas. Normalização de objetos do Illuminate para arrays faz parte do custo para alcançar o mesmo formato de saída. Comparações de resultados usam igualdade de valores, tolerando tipos escalares representados diferentemente pelo driver.
- Não há teste de significância ou declaração de empate. Rankings são ordenações descritivas da mediana observada; diferenças pequenas e p95 com 15 amostras são sensíveis ao ruído.
- Não há conclusão de superioridade geral. Tempos de banco, rede, compilação, fetch e representação de dados precisam ser interpretados separadamente.
- Paginação no cenário de construção complexo significa LIMIT/OFFSET, sem a consulta COUNT adicional de paginação completa. Rotinas nativas de DML podem produzir diagnósticos/identidade além do estado final verificado; não se interpreta essa diferença como eficiência intrínseca do motor.
- Resets e verificações aquecem páginas do banco. Autovacuum, checkpoints e manutenção interna não foram desabilitados; os efeitos podem aparecer na cauda de latência. O warmup é uma passagem validada, sem critério automático de convergência.

Veja [tabelas e rankings](results/REPORT.md), [CSV](results/summary.csv), [amostras](results/samples.jsonl), [ambiente](results/environment.json) e [equivalência](results/equivalence.json).
