# Docker Setup for Multi-RAG Application


## Services

- **FastAPI Application**: Main application running on Uvicorn
- **Nginx**: Web server for serving the FastAPI application
- **PostgreSQL (pgvector)**: Vector-enabled database for storing embeddings
- **Postgres-Exporter**: Exports PostgreSQL metrics for Prometheus
- **Prometheus**: Metrics collection
- **Grafana**: Visualization dashboard for metrics
- **Node-Exporter**: System metrics collection
- **ollama**: local LLM server
- **celery**: worker for Multimodal tasks



## Setup Instructions

### 1. Set up environment files

Create your environment files from the examples:

```bash
# Create all required .env files from examples
cd docker/env
cp .env.example.app .env.app
cp .env.example.postgres .env.postgres
cp .env.example.grafana .env.grafana
cp .env.example.postgres-exporter .env.postgres-exporter
cp .env.example.moodle .env.moodle
cp .env.example.pgadmin .env.pgadmin
# Setup the Alembic configuration for the FastAPI application
cd ..
cd docker/minirag
cp alembic.example.ini alembic.ini

### 2. Start the services

```bash
cd docker
docker compose up --build -d
```

To start only specific services:

```bash
docker compose up -d fastapi nginx pgvector
```

If you encounter connection issues, you may want to start the database services first and let them initialize before starting the application:

```bash
# Start databases first
docker compose up -d pgvector postgres-exporter
# Wait for databases to be healthy
sleep 30
# Start the application services
docker compose up fastapi nginx prometheus grafana node-exporter --build -d
```

In case deleting all containers and volumes is necessary, you can run:

```bash
docker compose down -v --remove-orphans
```