# Use `just <recipe>` to run a recipe
# https://just.systems/man/en/

import ".shared/common.just"

# By default, run the `--list` command
default:
    @just --list

# Variables

transferDir := `if [ -d "$HOME/NextcloudPrivate/Transfer" ]; then echo "$HOME/NextcloudPrivate/Transfer"; else echo "$HOME/Nextcloud/Transfer"; fi`
projectName := 'qownnotes-nextcloud'

# Open a terminal with the project session
[group('dev')]
term-run:
    zellij --layout term.kdl attach {{ projectName }} -c

# Kill the project session
[group('dev')]
term-kill:
    -zellij delete-session {{ projectName }} -f

# Kill and run a terminal with the project session
[group('dev')]
term: term-kill term-run

# Apply the patch to the project repository
[group('patch')]
git-apply-patch:
    git apply {{ transferDir }}/{{ projectName }}.patch

# Create a patch from the staged changes in the project repository
[group('patch')]
@git-create-patch:
    echo "transferDir: {{ transferDir }}"
    git diff --no-ext-diff --staged --binary > {{ transferDir }}/{{ projectName }}.patch
    ls -l1t {{ transferDir }}/ | head -2

# Run the GitHub Actions test workflow locally with act
[group('dev')]
github-run-test:
    nix-shell -p act --run "act -W .github/workflows/vm-test.yml"

# Open the project in the browser
[group('dev')]
open-browser:
    xdg-open http://localhost:8081

# Run tests for multiple versions of Nextcloud
[group('test')]
vm-test args='':
    nix build -L .#nixosTests.nextcloud-qownnotes {{ args }}

# Run tests for a single version of Nextcloud (faster and needs less memory)
[group('test')]
vm-test-version version='34' args='':
    nix build -L .#nixosTests.nextcloud-qownnotes-{{ version }} {{ args }}

# Run the PHP unit tests
[group('test')]
test-unit:
    composer run test:unit

# Build the frontend into js/
[group('dev')]
build-js:
    npm ci
    npm run build

# Run the JavaScript checks (ESLint, unit tests)
[group('linter')]
lint-js:
    npm run lint
    npm test

# Run the end-to-end tests against the docker dev server (see docker/README.md)
[group('test')]
test-e2e args='':
    cd tests/e2e && npm ci && CHROMIUM_PATH="${CHROMIUM_PATH:-$(command -v chromium || true)}" npx playwright test {{ args }}

# Run all PHP checks (syntax, code style, static analysis, unit tests)
[group('linter')]
lint-php:
    composer run lint
    composer run cs:check
    composer run psalm
    composer run test:unit

# Build the frontend and create the signed release archive in build/ (see docs/release.md)
[group('release')]
sign-app: build-js
    cd docker && just sign-app

# Run the code format checker in CI
[group('ci')]
ci-format-check:
    composer install --no-interaction
    npm ci
    just format
    just lint-js

# Interactive NixOS test driver session for the combined Nextcloud versions.
# Tries driverInteractive first (non-executing build), then falls back to driver (also non-executing) if needed.
# Usage examples:
#   just vm-test-interactive
#   just vm-test-interactive args="--impure"        # allow env vars: FORCE_REBUILD_NONCE=...

# Interactive NixOS test driver session for the combined Nextcloud versions test
[group('test')]
vm-test-interactive args='':
    if nix build -L .#nixosTests.nextcloud-qownnotes.driverInteractive {{ args }}; then \
        echo "Built driverInteractive attribute"; \
    elif nix build -L .#nixosTests.nextcloud-qownnotes.driver {{ args }}; then \
        echo "driverInteractive missing; using driver attribute"; \
    else \
        echo "Failed to build either driverInteractive or driver attribute"; \
        exit 1; \
    fi
    ./result/bin/nixos-test-driver
