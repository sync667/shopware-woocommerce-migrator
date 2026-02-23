#!/bin/bash

# Project-wide Helper Script - Local Development
# Thin wrapper around docker/local/mc.sh for convenience.
# Usage: ./local.sh <command> [arguments]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DOCKER_SCRIPT="$SCRIPT_DIR/docker/local/mc.sh"

if [ ! -f "$DOCKER_SCRIPT" ]; then
    echo "❌ Docker helper not found at: $DOCKER_SCRIPT" >&2
    exit 1
fi

exec bash "$DOCKER_SCRIPT" "$@"
