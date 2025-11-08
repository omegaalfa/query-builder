<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\utils;

/**
 * Carrega e gerencia variáveis de ambiente de um arquivo .env.
 */
final class EnvLoader
{
    /** @var bool */
    private static bool $loaded = false;

    /**
     * Carrega as variáveis de ambiente de um arquivo .env.
     *
     * @param string|null $path Caminho completo do arquivo .env ou diretório base.
     * @return void
     */
    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        $envPath = $path ?? dirname(__DIR__, 2) . '/.env';
        if (is_dir($envPath)) {
            $envPath = rtrim($envPath, '/') . '/.env';
        }

        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Divide apenas na primeira ocorrência de "="
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove aspas externas
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Ignora se já estiver definido
            if (isset($_ENV[$key]) || getenv($key) !== false) {
                continue;
            }

            // Define nas variáveis globais
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        self::$loaded = true;
    }

    /**
     * Obtém o valor de uma variável de ambiente.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $lower = strtolower($value);

        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            is_numeric($value) && str_contains($value, '.') => (float)$value,
            ctype_digit($value) => (int)$value,
            default => $value,
        };
    }
}
