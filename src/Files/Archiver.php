<?php

declare(strict_types=1);

namespace DbBackup\Files;

use RuntimeException;

readonly class Archiver
{
    public function archive(Files $files, string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException("cannot create $dir");
        }
        $file = "$dir/{$files->name}_" . date('Ymd_His') . '.tar.gz';
        $part = "$file.part";

        $excludes = array_map(fn (string $pattern) => "--exclude=$pattern", $files->exclude);
        $command = ['tar', '-czf', $part, ...$excludes, '-C', dirname($files->path), basename($files->path)];

        $stderr = tmpfile();
        $process = proc_open($command, [2 => $stderr], $pipes);
        if ($process === false) {
            throw new RuntimeException('cannot start tar');
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            if (is_file($part)) {
                unlink($part);
            }
            rewind($stderr);
            throw new RuntimeException("tar exit $exitCode: " . trim(stream_get_contents($stderr)));
        }

        rename($part, $file);

        return $file;
    }
}
