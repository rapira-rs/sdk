<?php

/**
 * Synchronises intra-`rapira/*` version constraints across every composer.json
 * in the split-monorepo.
 *
 * Intended to run inside the release workflow, RIGHT AFTER release-please has
 * created/updated the release PR, while the working tree is checked out on the
 * release PR branch. At that point .github/release-please/version.json already
 * holds the versions this release cycle will publish, so we just mirror them.
 *
 * We only touch composer.json of packages ACTUALLY BEING RELEASED this cycle
 * (their version changed in the manifest). For each such package every `rapira/*`
 * sibling in `require` / `require-dev` is refreshed to `^<version>` by composer
 * name. Dormant packages are left untouched — a release never churns the
 * constraints of packages it isn't publishing.
 *
 * The ROOT composer.json is the one exception: it is ALWAYS synced, even when it
 * isn't part of this cycle. It is the development aggregator (it is never
 * published) and wires every split package through path repositories under
 * `packages/*`, so both its `rapira/*` constraints AND the path-repo dev-aliases
 * in `repositories[].options.versions` must always mirror the manifest.
 * Otherwise a sibling-only release would move the constraint but leave the path
 * alias pinned to the old series, and composer would refuse to install. Editing
 * stays idempotent, so a root with nothing to update produces no diff.
 *
 * Unlike the testo monorepo this is modelled on, there is NO versioned root
 * package: the manifest carries only the split packages (plus the sentinel), and
 * every sibling is pinned with a plain caret — there is no meta-package needing
 * an open upper bound.
 *
 * Usage:
 *   php sync-deps.php [PREVIOUS_MANIFEST]
 * PREVIOUS_MANIFEST is the base branch's version.json. When given, packages whose
 * version differs from it are "released this cycle". Without it we cannot tell
 * what changed, so we fall back to refreshing every package.
 *
 * Notes:
 *  - Only `require` and `require-dev` are touched; `replace` and `suggest` are
 *    left alone. In the ROOT composer.json the `repositories[].options.versions`
 *    dev-aliases are also refreshed.
 *  - Editing is surgical (regex on the matched section block), so untouched lines
 *    keep their exact formatting — re-runs produce no spurious diff.
 *  - Refreshing a constraint here does NOT bump that package's own version: the
 *    release version is decided by release-please from conventional commits.
 *
 * Exit code is always 0; it prints a summary of what changed. Git add/commit/push
 * is handled by the workflow, not here.
 */

declare(strict_types=1);

const MANIFEST = '.github/release-please/version.json';
const SECTIONS = ['require', 'require-dev'];

/**
 * Trailing sentinel key in the manifest. It carries no package: its only job is
 * to keep every real entry comma-terminated so a `merge=union` of concurrent
 * per-package version bumps (see .gitattributes) stays valid JSON. Its value is
 * an inert but parseable version ("0.0.0") — release-please chokes on an empty
 * version string. Skipped everywhere a manifest key is treated as a package path.
 */
const SENTINEL = '_';

$root = \getcwd();

$manifest = readJson($root . '/' . MANIFEST);

// Optional previous manifest (base branch's version.json). Lets us tell which
// packages are being released this cycle.
$hasPrevious = isset($argv[1]);
$previous = $hasPrevious ? readJson($argv[1]) : [];

// Paths released this cycle: version new or changed vs the previous manifest —
// exactly the packages release-please is publishing now. These are the ONLY
// package composer.json files we touch. Without a previous manifest we cannot
// tell what changed, so we fall back to refreshing every package.
$released = [];
foreach ($manifest as $path => $version) {
    if ($path === SENTINEL) {
        continue;
    }
    if (!$hasPrevious || !\array_key_exists($path, $previous) || $previous[$path] !== $version) {
        $released[$path] = true;
    }
}

// Build map: real composer package name => target version, from the manifest.
$versions = [];
foreach ($manifest as $path => $version) {
    if ($path === SENTINEL) {
        continue;
    }
    $composer = readJson($root . "/$path/composer.json");
    $name = $composer['name'] ?? null;
    if (!\is_string($name) || $name === '') {
        \fwrite(\STDERR, "Skipping `$path/composer.json`: missing package name\n");
        continue;
    }
    $versions[$name] = (string) $version;
}

// Every composer.json that may reference a rapira/* package, mapped to its
// manifest path so we know whether the owning package is part of this release.
// The root ('.') is not a manifest entry — it is always synced (see header).
$files = ['composer.json' => '.'];
foreach (globFiles($root, 'packages/*/composer.json') as $abs) {
    $rel = \str_replace('\\', '/', \substr($abs, \strlen($root) + 1));
    $files[$rel] = \dirname($rel); // e.g. packages/http/composer.json => packages/http
}

$changed = [];
foreach ($files as $rel => $path) {
    $file = $root . '/' . $rel;
    if (!\is_file($file)) {
        continue;
    }
    $isRoot = $path === '.';
    // Subpackages are touched only when released this cycle; the root is always
    // synced because it wires every split package for local dev (see the header).
    if (!$isRoot && !isset($released[$path])) {
        continue;
    }
    $original = (string) \file_get_contents($file);
    $updated = syncFile($original, $versions);
    if ($isRoot) {
        // Keep the path-repo dev-aliases in lockstep with the constraints above.
        $updated = syncPathAliases($updated, $versions);
    }
    if ($updated !== $original) {
        \file_put_contents($file, $updated);
        $changed[] = $rel;
    }
}

if ($changed === []) {
    echo "No intra-package constraints needed updating.\n";
} else {
    echo "Synced rapira/* constraints in:\n";
    foreach ($changed as $rel) {
        echo "  - $rel\n";
    }
}

exit(0);

/**
 * Rewrite rapira/* constraints in the require / require-dev blocks only.
 *
 * Called only for packages released this cycle (the caller gates on that) plus
 * the always-synced root, so every managed rapira/* constraint here is refreshed.
 *
 * @param array<string, string> $versions sibling package name => version
 */
function syncFile(string $content, array $versions): string
{
    foreach (SECTIONS as $section) {
        // require/require-dev values are plain strings, so the block contains no
        // nested braces — [^{}]* captures the whole section body safely.
        $pattern = '/("' . \preg_quote($section, '/') . '"\s*:\s*\{)([^{}]*)(\})/';

        $content = (string) \preg_replace_callback($pattern, static function (array $m) use ($versions): string {
            $body = \preg_replace_callback(
                '/("(rapira\/[^"]+)"\s*:\s*")([^"]*)(")/',
                static function (array $dep) use ($versions): string {
                    $name = $dep[2];

                    // Not a managed split package — leave the constraint as authored.
                    if (!isset($versions[$name])) {
                        return $dep[0];
                    }
                    return $dep[1] . '^' . $versions[$name] . $dep[4];
                },
                $m[2],
            );

            return $m[1] . $body . $m[3];
        }, $content);
    }

    return $content;
}

/**
 * Refresh the path-repo dev-aliases in the ROOT composer.json's
 * `repositories[].options.versions` map so each local split package advertises
 * the series matching the constraint synced by {@see syncFile}.
 *
 * The values in that map are bare dev-aliases (`0.1.x-dev`, `1.x-dev`) — a shape
 * that never appears as a `require` constraint (those carry a `^`), so matching
 * on it targets the alias map without disturbing anything else. Only managed
 * split packages present in $versions are rewritten.
 *
 * @param array<string, string> $versions sibling package name => version
 */
function syncPathAliases(string $content, array $versions): string
{
    return (string) \preg_replace_callback(
        '/("(rapira\/[^"]+)"\s*:\s*")(\d+\.\d+\.x-dev|\d+\.x-dev)(")/',
        static function (array $m) use ($versions): string {
            $name = $m[2];
            if (!isset($versions[$name])) {
                return $m[0];
            }
            return $m[1] . devAlias($versions[$name]) . $m[4];
        },
        $content,
    );
}

/**
 * Dev-branch alias that satisfies a `^<version>` constraint. For a `0.y.z`
 * version the caret pins the minor, so the alias is `0.y.x-dev`; from `1.0.0` up
 * the caret pins the major, so it is `<major>.x-dev`.
 */
function devAlias(string $version): string
{
    [$major, $minor] = \array_pad(\explode('.', $version), 2, '0');
    return $major === '0' ? "0.{$minor}.x-dev" : "{$major}.x-dev";
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (!\is_file($path)) {
        \fwrite(\STDERR, "File not found: $path\n");
        exit(1);
    }
    $data = \json_decode((string) \file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
    \assert(\is_array($data));
    return $data;
}

/**
 * Cross-platform glob relative to a base dir, returning absolute paths.
 *
 * @return list<string>
 */
function globFiles(string $base, string $pattern): array
{
    $matches = \glob($base . '/' . $pattern, \GLOB_NOSORT);
    return $matches === false ? [] : $matches;
}
