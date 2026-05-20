#!/bin/bash
set -e

echo "Creating data directories..."
mkdir -p data/storage/db data/storage/cache data/covers

echo "Starting media-server..."
# Synology uses standalone docker-compose (v1); fall back to plugin (v2) if not found
if command -v docker-compose &>/dev/null; then
  docker-compose up -d "$@"
else
  docker compose up -d "$@"
fi

echo "Done. Open http://$(hostname -I | awk '{print $1}'):8080"
