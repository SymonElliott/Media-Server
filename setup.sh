#!/bin/bash
set -e

echo "Creating data directories..."
mkdir -p data/storage/db data/storage/cache data/covers

echo "Starting media-server..."
docker compose up -d "$@"

echo "Done. Open http://$(hostname -I | awk '{print $1}'):8080"
