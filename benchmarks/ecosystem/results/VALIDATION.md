# Validação do projeto

Executada no Compose de testes existente, após as medições e sem concorrência com o benchmark:

- `composer test`: 429 testes, 1.590 assertions; aprovado, sem skips ou falhas reportados.
- `composer quality`: PHPStan sem erros; PHP-CS-Fixer encontrou zero arquivos para corrigir entre 87 analisados.
- `git diff --check`: aprovado; avisos preexistentes de conversão LF/CRLF.
- Composer isolado resolveu dependências estáveis compatíveis com PHP 8.4.27 e não reportou advisories na instalação.

Os scripts executam verificações de equivalência antes de aceitar amostras. Tabelas comparativas só são geradas quando cada combinação incluída contém 15 amostras. Cycle/SQLite batch 1.000 e 10.000 foi excluído após reprodução de erro do limite de compound SELECT.

O diretório `benchmarks/ecosystem/` contém todas as adições desta etapa. Código de produção, testes existentes, composer.json principal e arquivos Compose existentes não foram editados. O HEAD é registrado em `git-head.txt`, mas o código medido inclui alterações preexistentes no working tree; seus hashes estão em `environment.json`.
