# Contributing to lphenom/realtime
Thank you for your interest in contributing!
## Requirements
- PHP >= 8.1
- Docker + Docker Compose (all commands run inside Docker)
- SSH key configured for GitHub (for VCS package access)
## Development setup
```bash
# Clone the repository
git clone git@github.com:lphenom/realtime.git
cd realtime
# Start services and install dependencies
make up
# Run tests
make test
# Run linter
make lint
# Run static analysis
make analyse
# Verify KPHP compatibility + PHAR build
make kphp-check
```
## Code style
- `declare(strict_types=1);` in every PHP file
- No trailing commas in function calls (KPHP compatibility)
- No `__destruct()`, no constructor property promotion, no `readonly` (KPHP compatibility)
- PSR-12 coding standard, enforced via PHP-CS-Fixer
## Commits
- Small, focused commits
- Conventional Commits format: `feat(realtime): ...`, `fix(bus): ...`, `docs: ...`, `chore: ...`
- Push to `main` after each commit
## KPHP compatibility
All code in `src/` must be compilable with `vkcom/kphp`. Run `make kphp-check` before submitting a PR.
See [docs/realtime.md](docs/realtime.md) and the KPHP compatibility rules in the project docs.
