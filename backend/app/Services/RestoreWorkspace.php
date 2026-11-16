<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class RestoreWorkspace
{
    public static function root(string $id, string $database, string $environment): string
    {
        if (! Str::isUuid($id) || $database !== 'kkp_pinjaman_restore_test' || ! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException('Invalid isolated restore context');
        }
        $parent = realpath(base_path('../.tools/local-restores'));
        $root = realpath(base_path('../.tools/local-restores/'.$id));
        $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        if (! $parent || ! $root || ! str_starts_with($normalize($root), $normalize($parent.DIRECTORY_SEPARATOR))
            || ! is_file($root.'/DATA_RESTORED_BROWSER_NOT_VERIFIED') || ! is_file($root.'/validation.json') || ! is_dir($root.'/private')) {
            throw new RuntimeException('Restore workspace not verified');
        }
        $validation = json_decode(file_get_contents($root.'/validation.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($validation['database'] ?? null) !== $database) {
            throw new RuntimeException('Restore workspace database mismatch');
        }
        foreach (['row_counts_match', 'constraints_match', 'indexes_match', 'private_integrity_passed', 'model_hashes_match'] as $key) {
            if (($validation[$key] ?? null) !== true) {
                throw new RuntimeException('Restore workspace validation incomplete');
            }
        }
        $private = realpath($root.'/private');
        $artifacts = realpath($root.'/artifact-root');
        if (! $private || ! $artifacts || ! str_starts_with($normalize($private), $normalize($root.DIRECTORY_SEPARATOR))
            || ! str_starts_with($normalize($artifacts), $normalize($root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Restore disk escaped workspace');
        }

        return $root;
    }
}
