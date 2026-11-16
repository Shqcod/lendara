<?php

namespace App\Services;

use RuntimeException;

class PrivateBackupFiles
{
    public function copy(string $sourceRoot, string $destinationRoot): array
    {
        $root = realpath($sourceRoot);
        if ($root === false || ! is_dir($root) || file_exists($destinationRoot)) {
            throw new RuntimeException('Backup root unavailable or destination exists');
        }
        $normalize = fn ($path) => PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        $prefix = $normalize($root.DIRECTORY_SEPARATOR);
        $parent = realpath(dirname($destinationRoot));
        if ($parent === false || $normalize($parent) === $normalize($root) || str_starts_with($normalize($parent), $prefix)) {
            throw new RuntimeException('Backup destination cannot be inside source');
        }
        mkdir($destinationRoot, 0700, true);
        $manifest = [];
        $seen = [];
        $walk = function (string $directory, string $relative) use (&$walk, &$manifest, &$seen, $destinationRoot, $root, $prefix, $normalize) {
            $resolved = realpath($directory);
            if ($resolved === false || isset($seen[$normalize($resolved)]) || ($normalize($resolved) !== $normalize($root) && ! str_starts_with($normalize($resolved), $prefix))) {
                throw new RuntimeException('Backup refuses escaped or repeated directory');
            }
            $seen[$normalize($resolved)] = true;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isLink()) {
                    throw new RuntimeException('Backup refuses symbolic links');
                }
                $name = $relative.$entry->getFilename();
                if ($entry->isDir()) {
                    mkdir($destinationRoot.'/'.$name, 0700);
                    $walk($entry->getPathname(), $name.'/');
                } elseif ($entry->isFile()) {
                    $manifest[$name] = $this->copyFile($entry->getPathname(), $destinationRoot.'/'.$name);
                } else {
                    throw new RuntimeException('Unsupported backup entry');
                }
            }
        };
        $walk($root, '');

        return $manifest;
    }

    public function copyFile(string $source, string $destination): array
    {
        if (file_exists($destination)) {
            throw new RuntimeException('Backup destination already exists');
        }
        $input = fopen($source, 'rb');
        if (! is_resource($input)) {
            throw new RuntimeException('Backup stream unavailable');
        }
        $output = null;
        try {
            $output = fopen($destination, 'xb');
            if (! is_resource($output)) {
                throw new RuntimeException('Backup output unavailable');
            }
            if (stream_copy_to_stream($input, $output) === false || ! fflush($output)) {
                throw new RuntimeException('Backup copy failed');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
        $hash = hash_file('sha256', $destination);
        if ($hash === false || ! hash_equals($hash, hash_file('sha256', $source))) {
            throw new RuntimeException('Backup copy hash mismatch');
        }

        return ['bytes' => filesize($destination), 'sha256' => $hash];
    }
}
