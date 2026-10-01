# Corrai

The Corrai application repository - An Assessment paper correction AI.

## Directory structure

```
corrai/
├── client/        # Vue 3 + TypeScript frontend application
├── admin_client/  # Vue 3 + TypeScript admin frontend (schools list)
├── server/        # PHP backend
├── docker/        # Docker configuration
├── doc/           # Documentation
├── etc/           # Miscellaneous configuration
├── docker-compose.yml
├── TODO.md
└── README.md
```

## Docker Compose development environment

### Prerequisites

- Docker and Docker Compose installed on your machine
- Configure environment variables in `server/php/.env` as needed

### Starting the development environment

From the project root directory:

```bash
docker-compose up
```

Or with Docker Compose v2:

```bash
docker compose up
```

The PHP backend will be available at `http://localhost:80`

### Docker services

- **php**: Nginx + PHP 8.3, serving the API endpoints
  - Port: 80
  - PHP Redis extension, and a Python virtualenv at `/var/corrai/python/.venv` with PaddleOCR and the Redis client
  - `Corrai\Utils\OCR` runs that interpreter against the mounted `pycorrai` package
  - Volumes:
    - `./server/log` → `/var/log/corrai`
    - `./server/php` → `/var/corrai/php`
    - `./server/python/pycorrai` → `/var/corrai/python/pycorrai`
    - `paddle_cache` → `/var/corrai/home` (PaddleX model cache)
    - Nginx config mounted from `docker/php/nginx-default.conf`
  - Starts after **php-consumer** and **ocr-consumer** so a plain `docker compose up` brings up the full worker stack
- **php-consumer**: PHP Redis file-status worker (`server/php/cli/redis_consumer.php`)
  - Consumes `corrai:files`, loads assessment/file attributes from SeaweedFS, dispatches `on_{status}`
  - `restart: unless-stopped`
- **ocr-consumer**: Python OCR worker (`python -m pycorrai.consumer`)
  - Consumes `corrai:ocr`, writes OCR results to S3, enqueues work for the PHP consumer
  - `restart: unless-stopped`
- **redis**: Redis 7
  - Port: 6379
  - Reachable from the PHP container as `redis:6379` (`REDIS_HOST`, `REDIS_PORT`)
- **seaweedfs**: S3-compatible object store for schools, users, assessments, and files
  - Ports: 8333 (S3 API), 8888 (filer UI)
  - Volume: `./docker/storage` → `/data`
  - Existing MinIO data in that volume is not compatible; clear `docker/storage` before the first SeaweedFS start

### Environment variables

Configure environment variables in `server/php/.env`:
- `APP_ENV`: Application environment (dev/prod)
- `LOG_DIR`: Local directory for application logs (`/var/log/corrai`)
- `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`: SeaweedFS S3 gateway (`S3_ENDPOINT` should be `http://seaweedfs:8333` in Compose)

## Client (Vue Frontend)

See [client/README.md](client/README.md) for setup and features.

## Admin Client (Vue Frontend)

See [admin_client/README.md](admin_client/README.md) for setup. Served at `/corrai_test_adm/`.

## Server (PHP Backend)

See [server/readme.md](server/readme.md) for setup, API documentation, and development procedures.

## Application Overview

Corrai is an Assessment paper correction application. See `doc/corrai.md` for detailed use cases and technical specifications.

## License

Corrai is licenced by MANTA M S.A.S.
See individual component licenses for details.
