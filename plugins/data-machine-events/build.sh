#!/bin/bash

# Data Machine Events - Production Build Script
# Creates optimized package in /dist directory.

set -euo pipefail

echo "🚀 Starting Data Machine Events build process..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

VERSION="$(awk -F': *' '/^[[:space:]]*\*[[:space:]]*Version:/ { print $2; exit }' datamachine-events.php | tr -d '\r')"
DIST_DIR="build"
PACKAGE_NAME="datamachine-events"
TEMP_DIR="${DIST_DIR}/${PACKAGE_NAME}"

if [[ -z "${VERSION}" ]]; then
  echo -e "${RED}Error: Could not determine plugin version.${NC}"
  exit 1
fi

echo -e "${BLUE}📦 Building version: ${VERSION}${NC}"

build_block() {
  local block_dir="$1"
  local block_label="$2"

  echo -e "${YELLOW}${block_label}${NC}"
  (cd "${block_dir}" && npm ci --silent --no-audit --no-fund && npm run build)
}

# Clean and create dist directory
echo -e "${YELLOW}🧹 Cleaning dist directory...${NC}"
rm -rf "${DIST_DIR}"
mkdir -p "${TEMP_DIR}"

rm -rf "inc/Blocks/Calendar/node_modules" "inc/Blocks/EventDetails/node_modules"

# Install composer dependencies (production only)
echo -e "${YELLOW}📚 Installing composer dependencies...${NC}"
composer install --no-dev --optimize-autoloader --no-interaction

build_block "inc/Blocks/Calendar" "🗓️ Building Calendar block..."
build_block "inc/Blocks/EventDetails" "📝 Building Event Details block..."

# Copy plugin files to temp directory
echo -e "${YELLOW}📂 Copying plugin files...${NC}"

# Copy main plugin files
cp datamachine-events.php "${TEMP_DIR}/"
cp readme.txt "${TEMP_DIR}/"
cp composer.json "${TEMP_DIR}/"
cp composer.lock "${TEMP_DIR}/"

# Copy directories (excluding development files)
rsync -av --exclude='node_modules' --exclude='src' --exclude='webpack.config.js' --exclude='package*.json' --exclude='.git*' --exclude='docs' inc/ "${TEMP_DIR}/inc/"
rsync -av assets/ "${TEMP_DIR}/assets/"
rsync -av templates/ "${TEMP_DIR}/templates/"
rsync -av vendor/ "${TEMP_DIR}/vendor/"

# Create languages directory (even if empty)
mkdir -p "${TEMP_DIR}/languages"

# Remove development files from blocks
echo -e "${YELLOW}🧹 Removing development files...${NC}"
find "${TEMP_DIR}/inc/Blocks" -name "src" -type d -exec rm -rf {} + 2>/dev/null || true
find "${TEMP_DIR}/inc/Blocks" -name "node_modules" -type d -exec rm -rf {} + 2>/dev/null || true
find "${TEMP_DIR}/inc/Blocks" -name "package*.json" -exec rm -f {} + 2>/dev/null || true
find "${TEMP_DIR}/inc/Blocks" -name "webpack.config.js" -exec rm -f {} + 2>/dev/null || true

# Create .zip file
echo -e "${YELLOW}📦 Creating .zip package...${NC}"
(
  cd "${DIST_DIR}"
  zip -r "${PACKAGE_NAME}.zip" "${PACKAGE_NAME}" -q
)

# Generate build info
echo -e "${YELLOW}📋 Generating build info...${NC}"
cat > "${DIST_DIR}/build-info.txt" << EOF
Data Machine Events - Build Information
=====================================
Version: ${VERSION}
Built: $(date)
Builder: $(whoami)@$(hostname)
PHP Version Required: >=8.0
WordPress Version Required: >=6.0

Package Contents:
- Plugin files
- Optimized frontend assets
- Production composer dependencies
- Built block assets (Calendar & Event Details)

Installation:
1. Upload ${PACKAGE_NAME}.zip to WordPress
2. Activate the plugin
3. Configure via Settings > Data Machine Events
EOF

# Calculate file sizes
ZIP_SIZE=$(du -sh "${DIST_DIR}/${PACKAGE_NAME}.zip" | cut -f1)

# Remove temporary build directory
echo -e "${YELLOW}🧹 Cleaning up temporary files...${NC}"
rm -rf "${TEMP_DIR}"

echo ""
echo -e "${GREEN}✅ Build completed successfully!${NC}"
echo ""
echo -e "${BLUE}📊 Build Summary:${NC}"
echo -e "  ZIP file: ${ZIP_SIZE}"
echo -e "  Location: ${DIST_DIR}/${PACKAGE_NAME}.zip"
echo ""
echo -e "${GREEN}🎉 Ready for production deployment!${NC}"