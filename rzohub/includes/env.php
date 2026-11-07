<?php
/**
 * RZO HUB - Native Environment Variable (.env) Loader
 * Zero-dependency, lightweight, serverless & production compatible.
 */

declare(strict_types=1);

function loadEnv(string $envFilePath): void {
    if (!file_exists($envFilePath) || !is_readable($envFilePath)) {
        return;
    }

    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $val = trim($parts[1]);

        // Strip enclosing quotes
        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        // Only populate if not already set by system/serverless environment
        if (getenv($key) === false) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

/**
 * Fetch environment variable with default fallback
 */
function env(string $key, mixed $default = null): mixed {
    $val = getenv($key);

    if ($val === false) {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    if ($val === null || $val === false) {
        return $default;
    }

    // Type casting
    $lower = strtolower((string)$val);
    if ($lower === 'true' || $lower === '(true)') return true;
    if ($lower === 'false' || $lower === '(false)') return false;
    if ($lower === 'null' || $lower === '(null)') return null;
    if (is_numeric($val)) return str_contains((string)$val, '.') ? (float)$val : (int)$val;

    return $val;
}
