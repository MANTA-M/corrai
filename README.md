# Corrai

The Corrai application repository - An Exam paper correction AI.

## Directory structure

```
corrai/
├── client/        # Vue 3 + TypeScript frontend application
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
  - Volumes:
    - `./server/log` → `/var/log/corrai`
    - `./server/php` → `/var/corrai/php`
    - Nginx config mounted from `docker/php/nginx-default.conf`
- **seaweedfs**: S3-compatible object store for schools, users, exams, and files
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

## Server (PHP Backend)

See [server/readme.md](server/readme.md) for setup, API documentation, and development procedures.

## Application Overview

Corrai is an Exam paper correction application. See `doc/corrai.md` for detailed use cases and technical specifications.

## License

Corrai is licenced by MANTA M S.A.S.
See individual component licenses for details.
