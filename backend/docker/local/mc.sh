#!/bin/bash

# Docker Local Development Helper Script
# Usage: ./mc.sh <command> [arguments]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
info() { echo -e "${BLUE}ℹ️  $1${NC}"; }
success() { echo -e "${GREEN}✅ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
error() { echo -e "${RED}❌ $1${NC}"; exit 1; }

# Check if Docker is running
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        error "Docker is not running. Please start Docker and try again."
    fi
}

# Commands
cmd_help() {
    echo -e "${BLUE}🐳 Docker Local Development Commands${NC}"
    echo ""
    echo "Usage: ./mc.sh <command> [arguments]"
    echo ""
    echo "Container Management:"
    echo "  up              Start all containers"
    echo "  down            Stop all containers"
    echo "  restart         Restart all containers"
    echo "  build           Rebuild containers (no cache)"
    echo "  ps              Show container status"
    echo "  logs [service]  View logs (all or specific service)"
    echo "  clean           Stop containers and remove volumes"
    echo ""
    echo "Shell Access:"
    echo "  shell           Access app container shell"
    echo "  psql            Access PostgreSQL CLI"
    echo "  redis           Access Redis CLI"
    echo ""
    echo "Laravel Commands:"
    echo "  artisan <cmd>   Run artisan command"
    echo "  migrate         Run database migrations"
    echo "  fresh           Fresh migrations with seeding"
    echo "  seed            Run database seeders"
    echo "  test            Run tests"
    echo "  tinker          Open Laravel Tinker"
    echo "  cache           Clear all caches"
    echo ""
    echo "Dependencies:"
    echo "  composer <cmd>  Run composer command"
    echo "  install         Install PHP dependencies"
    echo ""
    echo "Setup:"
    echo "  setup           Initial setup (build, start, install deps)"
    echo "  init            Initialize Laravel (key, migrate)"
    echo ""
}

cmd_up() {
    check_docker
    info "Starting containers..."
    docker compose up -d
    success "Containers started!"
    echo ""
    echo "🌐 Access points:"
    echo "   - API:         http://localhost:${APP_PORT:-8080}"
    echo "   - Mailpit:     http://localhost:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}"
}

cmd_down() {
    info "Stopping containers..."
    docker compose down
    success "Containers stopped!"
}

cmd_restart() {
    cmd_down
    cmd_up
}

cmd_build() {
    check_docker
    info "Building containers..."
    docker compose build --no-cache
    success "Containers rebuilt!"
}

cmd_ps() {
    docker compose ps
}

cmd_logs() {
    if [ -z "$1" ]; then
        docker compose logs -f
    else
        docker compose logs -f "$1"
    fi
}

cmd_clean() {
    warn "This will remove all containers and volumes (database data will be lost)!"
    read -p "Are you sure? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        docker compose down -v
        success "Containers and volumes removed!"
    fi
}

cmd_shell() {
    docker compose exec api sh
}

cmd_psql() {
    docker compose exec pgsql psql -U "${DB_USERNAME:-laravel}" -d "${DB_DATABASE:-laravel}"
}

cmd_redis() {
    docker compose exec redis redis-cli
}

cmd_artisan() {
    docker compose exec api php artisan "$@"
}

cmd_migrate() {
    docker compose exec api php artisan migrate
}

cmd_fresh() {
    docker compose exec api php artisan migrate:fresh --seed
}

cmd_seed() {
    docker compose exec api php artisan db:seed
}

cmd_test() {
    docker compose exec api php artisan test "$@"
}

cmd_tinker() {
    docker compose exec api php artisan tinker
}

cmd_cache() {
    info "Clearing caches..."
    docker compose exec api php artisan config:clear
    docker compose exec api php artisan cache:clear
    docker compose exec api php artisan route:clear
    docker compose exec api php artisan view:clear
    success "All caches cleared!"
}

cmd_composer() {
    docker compose exec api composer "$@"
}

cmd_install() {
    info "Installing PHP dependencies..."
    docker compose exec api composer install
    success "PHP dependencies installed!"
}

cmd_setup() {
    check_docker

    info "Setting up Docker environment..."

    # Copy .env if needed
    if [ ! -f .env ]; then
        cp .env.example .env
        success ".env file created"
    fi

    # Build and start
    info "Building containers..."
    docker compose build

    info "Starting containers..."
    docker compose up -d

    # Wait for services
    info "Waiting for services..."
    sleep 5

    cmd_install
    cmd_init

    success "Setup complete!"
    cmd_up
}

cmd_init() {
    info "Initializing Laravel..."

    # Check if .env exists in project root
    if [ ! -f ../../.env ]; then
        cp ../../.env.example ../../.env 2>/dev/null || true
    fi

    docker compose exec api php artisan key:generate --force
    docker compose exec api php artisan migrate --force

    success "Laravel initialized!"
}

# Main command router
case "${1:-help}" in
    help|--help|-h) cmd_help ;;
    up) cmd_up ;;
    down) cmd_down ;;
    restart) cmd_restart ;;
    build) cmd_build ;;
    ps) cmd_ps ;;
    logs) shift; cmd_logs "$@" ;;
    clean) cmd_clean ;;
    shell) cmd_shell ;;
    psql) cmd_psql ;;
    redis) cmd_redis ;;
    artisan) shift; cmd_artisan "$@" ;;
    migrate) cmd_migrate ;;
    fresh) cmd_fresh ;;
    seed) cmd_seed ;;
    test) shift; cmd_test "$@" ;;
    tinker) cmd_tinker ;;
    cache) cmd_cache ;;
    composer) shift; cmd_composer "$@" ;;
    install) cmd_install ;;
    setup) cmd_setup ;;
    init) cmd_init ;;
    *) error "Unknown command: $1. Run './mc.sh help' for usage." ;;
esac

