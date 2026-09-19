#!/usr/bin/env bash

set -euo pipefail

REPO="Extra-Chill/homeboy"
TAG="v${HOMEBOY_VERSION}"
echo "Installing Homeboy ${HOMEBOY_VERSION}..."

OS=$(uname -s | tr '[:upper:]' '[:lower:]')
ARCH=$(uname -m)

case "${OS}-${ARCH}" in
  linux-x86_64) TARGET="x86_64-unknown-linux-gnu" ;;
  linux-aarch64) TARGET="aarch64-unknown-linux-gnu" ;;
  darwin-x86_64) TARGET="x86_64-apple-darwin" ;;
  darwin-arm64) TARGET="aarch64-apple-darwin" ;;
  *)
    echo "::error::Unsupported platform: ${OS}-${ARCH}"
    exit 1
    ;;
esac

ARCHIVE="homeboy-${TARGET}.tar.xz"
URL="https://github.com/${REPO}/releases/download/${TAG}/${ARCHIVE}"

echo "Downloading ${URL}..."
curl -fsSL "${URL}" -o "/tmp/${ARCHIVE}"

mkdir -p /tmp/homeboy-extract
tar -xJf "/tmp/${ARCHIVE}" -C /tmp/homeboy-extract

BINARY=$(find /tmp/homeboy-extract -name "homeboy" -type f | head -1)
if [ -z "${BINARY}" ]; then
  echo "::error::Could not find homeboy binary in archive"
  exit 1
fi

chmod +x "${BINARY}"
sudo mv "${BINARY}" /usr/local/bin/homeboy

echo "Homeboy $(homeboy --version) installed successfully"
