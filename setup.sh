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

IP=$(ip route get 1 2>/dev/null | awk '/src/{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1)
echo "Done. Open http://${IP:-<nas-ip>}:8080"
