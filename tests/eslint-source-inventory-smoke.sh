#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ESLINT_BIN="${ESLINT_BIN:?Set ESLINT_BIN to the provider ESLint binary}"
ESLINT_CONFIG="${ESLINT_CONFIG:?Set ESLINT_CONFIG to the provider runner config}"

mapfile -d '' tracked_sources < <(
	git -C "$ROOT_DIR" ls-files -z -- 'inc/Core/Admin/**/*.js' 'inc/Core/Admin/**/*.jsx'
)

shipped_sources=()
for source in "${tracked_sources[@]}"; do
	case "$source" in
		*/node_modules/*|*/vendor/*|*/build/*|*.min.js)
			continue
			;;
	esac
	shipped_sources+=("$source")
done

if [ "${#shipped_sources[@]}" -eq 0 ]; then
	echo "No tracked shipped JS/JSX admin sources found" >&2
	exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
printf '%s\n' "${shipped_sources[@]}" > "$TMP_DIR/expected.txt"

export HOMEBOY_ESLINT_COMPONENT_PATH="$ROOT_DIR"

set +e
(
	cd "$ROOT_DIR"
	"$ESLINT_BIN" --config "$ESLINT_CONFIG" --format json \
		"${shipped_sources[@]}" > "$TMP_DIR/report.json"
)
eslint_status=$?
set -e

if [ "$eslint_status" -gt 1 ]; then
	echo "ESLint could not inventory the shipped sources" >&2
	exit "$eslint_status"
fi

(
	cd "$ROOT_DIR"
	"$ESLINT_BIN" --config "$ESLINT_CONFIG" --print-config \
		"inc/Core/Admin/Modal/assets/js/modal-manager.js" > "$TMP_DIR/js-config.json"
	"$ESLINT_BIN" --config "$ESLINT_CONFIG" --print-config \
		"inc/Core/Admin/shared/components/Pagination.jsx" > "$TMP_DIR/jsx-config.json"
)

node - "$ROOT_DIR" "$TMP_DIR" <<'JS'
const fs = require( 'fs' );
const path = require( 'path' );

const root = process.argv[ 2 ];
const tmp = process.argv[ 3 ];
const expected = fs
	.readFileSync( path.join( tmp, 'expected.txt' ), 'utf8' )
	.trim()
	.split( '\n' );
const report = JSON.parse(
	fs.readFileSync( path.join( tmp, 'report.json' ), 'utf8' )
);
const reported = new Map(
	report.map( ( result ) => [ path.relative( root, result.filePath ), result ] )
);

for ( const source of expected ) {
	const result = reported.get( source );
	if ( ! result ) {
		throw new Error( `ESLint omitted tracked shipped source: ${ source }` );
	}
	if ( result.fatalErrorCount > 0 ) {
		throw new Error( `ESLint could not parse tracked shipped source: ${ source }` );
	}
	if (
		result.messages.some( ( message ) =>
			message.message.includes(
				'File ignored because no matching configuration was supplied'
			)
		)
	) {
		throw new Error( `ESLint has no matching configuration for: ${ source }` );
	}
}

for ( const extension of [ 'js', 'jsx' ] ) {
	const config = JSON.parse(
		fs.readFileSync( path.join( tmp, `${ extension }-config.json` ), 'utf8' )
	);
	if ( config.rules.eqeqeq[ 0 ] !== 2 ) {
		throw new Error( `Provider defaults were not composed for ${ extension }` );
	}
	if ( extension === 'jsx' && ! config.languageOptions.parserOptions.ecmaFeatures.jsx ) {
		throw new Error( 'JSX parser support is not configured' );
	}
}
JS

echo "ESLint source inventory passed: ${#shipped_sources[@]} tracked shipped JS/JSX files configured"
