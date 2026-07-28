# Environment configuration

`EnvLoader` provides a small, dependency-free loader for trusted `.env` files. The same variable names can also be injected by Docker, a process manager, CI, or a secrets platform. Existing process variables are never overwritten unless `overwrite: true` is explicitly requested.

## Local development

```bash
cp .env.example .env
chmod 600 .env
```

```php
use Omegaalfa\QueryBuilder\Utils\EnvLoader;

EnvLoader::load(__DIR__, required: true);

$host = EnvLoader::get('DB_HOST', '127.0.0.1');
$port = EnvLoader::getInt('DB_PORT', 3306);
$debug = EnvLoader::getBool('APP_DEBUG', false);
$password = EnvLoader::require('DB_PASSWORD');
```

Values returned by `get()` remain strings. Use `getInt()` and `getBool()` for explicit conversion. `require()` rejects missing and empty values.

Docker Compose automatically reads the project `.env` for interpolation. Variables are passed to PostgreSQL, MySQL, and Redis only through explicit entries in `docker-compose.yml`.

## Production

Prefer environment variables or secrets injected by the deployment platform. They take precedence because `EnvLoader` does not overwrite existing values by default. If production uses an actual `.env` file, restrict it to the application user and enable permission validation:

```bash
chmod 600 /path/to/application/.env
ENV_STRICT_PERMISSIONS=1 php vectorPgsql.php
```

```php
EnvLoader::load(
    '/path/to/application',
    required: true,
    strictPermissions: true,
);
```

The strict mode rejects files accessible by group or other users on Unix. The loader also validates variable names, rejects malformed entries and NUL bytes, limits files to 1 MiB, obtains a shared read lock, and never copies loaded secrets into `$_SERVER`.

Do not commit `.env`. Commit only `.env.example` with placeholders. Do not print configuration arrays, passwords, or the contents of `$_ENV` in logs or exception pages.

## Supported syntax

```dotenv
KEY=value
EMPTY=
QUOTED="value with spaces"
SINGLE_QUOTED='literal value'
INLINE=value # comment
export EXPORTED=value
```

Multiline values, shell expansion, and variable interpolation are deliberately unsupported. Use injected secrets or mounted secret files for complex secret material.
