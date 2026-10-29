<?php
declare(strict_types=1);

function receipt_image_upload_roots(): array
{
    return [
        realpath(__DIR__ . '/uploads/receipts') ?: __DIR__ . '/uploads/receipts',
        realpath(__DIR__ . '/uploads') ?: __DIR__ . '/uploads',
    ];
}

function receipt_image_is_safe_relative_path(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) return false;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1) return false;
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '..') return false;
    }
    return true;
}

function receipt_image_urlencode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function receipt_image_candidates(string $storedPath): array
{
    $path = trim(rawurldecode($storedPath));
    if (parse_url($path, PHP_URL_SCHEME) !== null || parse_url($path, PHP_URL_HOST) !== null) {
        return [];
    }

    $urlPath = parse_url($path, PHP_URL_PATH);
    if (is_string($urlPath) && $urlPath !== '') {
        $path = $urlPath;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (str_starts_with($path, 'admin_panel/')) {
        $path = substr($path, strlen('admin_panel/'));
    }

    if (!receipt_image_is_safe_relative_path($path)) return [];

    $fileName = basename($path);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') return [];

    $candidates = [];
    if (str_starts_with($path, 'uploads/receipts/')) {
        $candidates[] = $path;
    } elseif (str_starts_with($path, 'receipts/')) {
        $candidates[] = 'uploads/' . $path;
    } elseif (str_starts_with($path, 'uploads/')) {
        $candidates[] = 'uploads/receipts/' . $fileName;
        $candidates[] = $path;
    } else {
        $candidates[] = 'uploads/receipts/' . $fileName;
    }

    return array_values(array_unique($candidates));
}

function receipt_image_public_url(?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return null;

    $allowedRoots = receipt_image_upload_roots();
    foreach (receipt_image_candidates($storedPath) as $relativePath) {
        $absolutePath = realpath(__DIR__ . '/' . $relativePath);
        if ($absolutePath === false || !is_file($absolutePath)) continue;

        foreach ($allowedRoots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $absolute = str_replace('\\', '/', $absolutePath);
            if ($absolute === $root || str_starts_with($absolute, $root . '/')) {
                return receipt_image_urlencode_path($relativePath);
            }
        }
    }

    return null;
}
