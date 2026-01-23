#!/bin/bash

# Project-wide Helper Script - Local Development
# Usage: ./local.sh [back|front] <command> [arguments]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Helper functions
info() { echo -e "${BLUE}ℹ️  $1${NC}"; }
success() { echo -e "${GREEN}✅ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
error() { echo -e "${RED}❌ $1${NC}"; exit 1; }

# Show help
cmd_help() {
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}   🚀 Local Development Helper${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════${NC}"
    echo ""
    echo "Usage: ./local.sh [back|front] <command> [arguments]"
    echo ""
    echo -e "${BLUE}Backend Commands:${NC}"
    echo "  ./local.sh back <command>   - Run backend Docker commands"
    echo ""
    echo "  Container Management:"
    echo "    up, down, restart, build, ps, logs, clean"
    echo ""
    echo "  Shell Access:"
    echo "    shell, psql, redis"
    echo ""
    echo "  Laravel:"
    echo "    artisan, migrate, fresh, seed, test, tinker, cache"
    echo ""
    echo "  Dependencies:"
    echo "    composer, install"
    echo ""
    echo "  Setup:"
    echo "    setup, init"
    echo ""
    echo -e "${BLUE}Frontend Commands:${NC}"
    echo "  ./local.sh front <command>  - Run frontend commands"
    echo ""
    echo "  Available:"
    echo "    dev        - Start dev server"
    echo "    build      - Build for production"
    echo "    install    - Install dependencies"
    echo "    (add more as needed)"
    echo ""
    echo -e "${BLUE}Quick Access:${NC}"
    echo "  ./local.sh help             - Show this help"
    echo ""
    echo -e "${BLUE}Examples:${NC}"
    echo "  ./local.sh back up          - Start backend services"
    echo "  ./local.sh back artisan migrate"
    echo "  ./local.sh front dev        - Start frontend dev server"
    echo ""
    echo "For detailed backend help: ./local.sh back help"
    echo ""
}

# Backend commands
cmd_back() {
    local backend_script="$PROJECT_ROOT/backend/docker/local/mc.sh"
    
    if [ ! -f "$backend_script" ]; then
        error "Backend script not found at: $backend_script"
    fi
    
    info "Running backend command: $*"
    bash "$backend_script" "$@"
}

# Frontend commands
cmd_front() {
    local frontend_dir="$PROJECT_ROOT/frontend"
    
    if [ ! -d "$frontend_dir" ]; then
        error "Frontend directory not found at: $frontend_dir"
    fi
    
    cd "$frontend_dir"
    
    case "${1:-help}" in
        dev)
            info "Starting frontend dev server..."
            pnpm dev
            ;;
        build)
            info "Building frontend for production..."
            pnpm build
            ;;
        install)
            info "Installing frontend dependencies..."
            pnpm install
            success "Frontend dependencies installed!"
            ;;
        help|--help|-h)
            echo ""
            echo "Frontend Commands:"
            echo "  dev       - Start development server"
            echo "  build     - Build for production"
            echo "  install   - Install dependencies"
            echo ""
            ;;
        *)
            # Pass through any other pnpm command
            info "Running pnpm command: $*"
            pnpm "$@"
            ;;
    esac
}

# Main command router
case "${1:-help}" in
    back|backend)
        shift
        cmd_back "$@"
        ;;
    front|frontend)
        shift
        cmd_front "$@"
        ;;
    help|--help|-h)
        cmd_help
        ;;
    *)
        error "Unknown target: $1. Use 'back', 'front', or 'help'"
        ;;
esac
