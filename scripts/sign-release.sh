#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# scripts/sign-release.sh
#
# Creates a signed release package for the Nextcloud App Store.
#
# Prerequisites:
#   1. A signing key-pair registered with Nextcloud (see docs/code-signing.md)
#   2. occ (the Nextcloud CLI) on $PATH or passed as first argument
#   3. The app mounted/symlinked in the Nextcloud apps directory
#
# Usage:
#   ./scripts/sign-release.sh [path-to-occ]
#
# Output:
#   nc_s3api-<version>.tar.gz          (the release tarball)
#   nc_s3api-<version>.tar.gz.asc      (detached GPG signature, optional)

set -euo pipefail

OCC="${1:-occ}"
APP_ID="nc_s3api"
VERSION=$(grep -m1 '<version>' appinfo/info.xml | sed 's/.*<version>\(.*\)<\/version>.*/\1/')

echo "==> Signing app ${APP_ID} v${VERSION}"

# 1. Generate appinfo/signature.json via occ
"$OCC" integrity:sign-app \
    --privateKey="${APP_ID}.key" \
    --certificate="${APP_ID}.crt" \
    --path="$(pwd)"

echo "==> signature.json written"

# 2. Build the tarball (exclude dev/test/git artefacts)
TARBALL="${APP_ID}-${VERSION}.tar.gz"
tar -czf "../${TARBALL}" \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='tests' \
    --exclude='docker-compose.yml' \
    --exclude='scripts' \
    --exclude='*.tar.gz' \
    --transform "s|^\.|${APP_ID}|" \
    .

echo "==> Release tarball: ../${TARBALL}"

# 3. Optional: GPG-sign the tarball
if command -v gpg &>/dev/null; then
    gpg --armor --detach-sign "../${TARBALL}"
    echo "==> GPG signature: ../${TARBALL}.asc"
fi

echo "==> Done. Upload ../${TARBALL} to https://apps.nextcloud.com/developer/apps/releases/new"
