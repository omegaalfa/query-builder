# Docker development environment

The root `docker-compose.yml` provides three independent services:

| Service | Image | Host port | Purpose |
|---|---|---:|---|
| `pgvector` | `pgvector/pgvector:0.8.5-pg17-bookworm` | `54329` | PostgreSQL 17 and pgvector |
| `mysql` | `mysql:8.4.10` | `33069` | MySQL integration |
| `redis` | `redis:7.4.10-alpine3.21` | `63799` | Query-result cache |

All host ports and credentials come from `.env`. Copy the safe template before the first start:

```bash
cp .env.example .env
chmod 600 .env
docker compose up -d --wait
docker compose ps
```

Start only selected services when needed:

```bash
docker compose up -d --wait pgvector
docker compose up -d --wait mysql redis
```

From PHP running directly in WSL, use the published `127.0.0.1` ports from `.env`. From another container attached to the `query-builder_query_builder` network, use service DNS names and internal ports: `pgvector:5432`, `mysql:3306`, and `redis:6379`.

## Persistence and credential changes

Named volumes preserve data across normal container recreation:

- `query-builder_pgvector_data`
- `query-builder_mysql_data`
- `query-builder_redis_data`

Database initialization variables only apply when a data directory is empty. Editing a password in `.env` does not change an existing PostgreSQL or MySQL account. Change the credential inside the database, or reset the development volumes when losing all local data is acceptable:

```bash
docker compose down -v
docker compose up -d --wait
```

`down -v` permanently deletes this Compose project's database and Redis data. Never use it against production data.

## Validation

```bash
php vectorPgsql.php
php dockerServices.php
PGVECTOR_INTEGRATION=1 composer test
```

The Compose file is intended for local development and CI. For production, inject secrets from the deployment platform instead of storing real credentials in the project `.env`.
