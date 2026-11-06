<?php

declare(strict_types=1);

namespace BackupTool;

use BackupTool\Uploader\Uploader;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

abstract readonly class Backup
{
    private string $dir;

    /** @param array<string, Uploader> $uploaders */
    public function __construct(
        public string $id,
        string $rootDir,
        private ?int $keepDays,
        private array $uploaders,
        private LoggerInterface $logger,
    ) {
        $this->dir = "$rootDir/$id";
    }

    abstract protected function extension(): string;

    abstract protected function write(string $file): void;

    public function run(): bool
    {
        try {
            return $this->backup();
        } catch (Throwable $e) {
            $this->logger->error("$this->id: backup failed: {$e->getMessage()}");
            return false;
        }
    }

    /** @param list<list<string>> $commands run one after another, their joined output is gzipped into $file */
    protected function gzipOutput(array $commands, string $file): void
    {
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new RuntimeException("cannot write $file");
        }

        try {
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            foreach ($commands as $command) {
                $this->runCommand($command, fn (string $chunk) => $this->writeChunk($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH)));
            }
            $this->writeChunk($out, deflate_add($deflate, '', ZLIB_FINISH));
        } finally {
            fclose($out);
        }
    }

    private function backup(): bool
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true)) {
            throw new RuntimeException("cannot create $this->dir");
        }

        $started = microtime(true);
        $file = $this->createFile();
        $this->logger->info(sprintf('%s: written to %s (%.1f MB, %.1fs)', $this->id, $file, filesize($file) / 1048576, microtime(true) - $started));

        $allUploaded = $this->upload($file);

        if ($this->keepDays > 0) {
            $this->deleteOldBackups($file);
        } elseif ($this->keepDays === 0 && $allUploaded) {
            $this->deleteOldBackups($file);
            $this->delete($file);
        }

        return $allUploaded;
    }

    private function createFile(): string
    {
        $file = "$this->dir/" . basename($this->id) . '_' . date('Ymd_His') . $this->extension();

        $this->deletePartialFiles();
        try {
            $this->write("$file.part");
            if (!rename("$file.part", $file)) {
                throw new RuntimeException("cannot rename $file.part");
            }
        } finally {
            $this->deletePartialFiles();
        }

        return $file;
    }

    private function upload(string $file): bool
    {
        $allUploaded = true;
        foreach ($this->uploaders as $name => $uploader) {
            $started = microtime(true);
            try {
                $uploader->upload($file, $this->id);
                $this->logger->info(sprintf('%s: uploaded to %s (%.1fs)', $this->id, $name, microtime(true) - $started));
            } catch (Throwable $e) {
                $allUploaded = false;
                $this->logger->error("$this->id: upload to $name failed: {$e->getMessage()}");
                continue;
            }

            try {
                $uploader->deleteOldBackups($this->id, $file);
            } catch (Throwable $e) {
                $this->logger->error("$this->id: deleting old backups on $name failed: {$e->getMessage()}");
            }
        }

        return $allUploaded;
    }

    private function deletePartialFiles(): void
    {
        foreach (glob("$this->dir/*.part") ?: [] as $file) {
            unlink($file);
        }
    }

    private function deleteOldBackups(string $newFile): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob("$this->dir/*.gz") ?: [] as $file) {
            if ($file !== $newFile && filemtime($file) < $cutoff) {
                $this->delete($file);
            }
        }
    }

    private function delete(string $file): void
    {
        unlink($file);
        $this->logger->info("deleted $file");
    }

    private function runCommand(array $command, Closure $onOutput): void
    {
        $stderr = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if ($process === false) {
            throw new RuntimeException("cannot start $command[0]");
        }

        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);
                if ($chunk === false) {
                    throw new RuntimeException("cannot read $command[0] output");
                }
                $onOutput($chunk);
            }
        } finally {
            fclose($pipes[1]);
            $exitCode = proc_close($process);
        }

        if ($exitCode !== 0) {
            rewind($stderr);
            throw new RuntimeException(basename($command[0]) . " exit $exitCode: " . trim(stream_get_contents($stderr)));
        }
    }

    // fclose() returns true even when the final write fails, so every write is checked
    private function writeChunk($out, string $data): void
    {
        if ($data !== '' && fwrite($out, $data) !== strlen($data)) {
            throw new RuntimeException('cannot write the backup (disk full?)');
        }
    }
}
