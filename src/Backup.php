<?php

declare(strict_types=1);

namespace BackupTool;

use BackupTool\Uploader\Uploader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

abstract readonly class Backup
{
    private LockFactory $locks;

    public function __construct(
        private string $id,
        private string $dir,
        private ?int $keepDays,
        /** @var array<string, Uploader> */
        private array $uploaders,
        private LoggerInterface $logger,
    ) {
        $this->locks = new LockFactory(new FlockStore());
    }

    abstract protected function dump(string $dir): string;

    public function run(): bool
    {
        $lock = $this->locks->createLock("backup-tool:$this->id");
        if (!$lock->acquire()) {
            $this->logger->error("$this->id: skipped, previous backup is still running");
            return false;
        }

        try {
            return $this->backup();
        } catch (Throwable $e) {
            $this->logger->error("$this->id: backup failed: {$e->getMessage()}");
            return false;
        } finally {
            $lock->release();
        }
    }

    private function backup(): bool
    {
        $dir = "$this->dir/$this->id";
        $this->deletePartialDumps($dir);

        $started = microtime(true);
        $file = $this->dump($dir);
        $this->logger->info(sprintf('%s: dumped to %s (%.1f MB, %.1fs)', $this->id, $file, filesize($file) / 1048576, microtime(true) - $started));

        $allUploaded = $this->upload($file);

        if ($this->keepDays > 0 || ($this->keepDays === 0 && $allUploaded)) {
            $this->deleteOldDumps($dir, $file);
        }
        if ($this->keepDays === 0 && $allUploaded) {
            $this->delete($file);
        }

        return $allUploaded;
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
            }
        }

        return $allUploaded;
    }

    private function deletePartialDumps(string $dir): void
    {
        foreach (glob("$dir/*.part") ?: [] as $file) {
            unlink($file);
        }
    }

    private function deleteOldDumps(string $dir, string $newDump): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob("$dir/*.gz") ?: [] as $file) {
            if ($file !== $newDump && filemtime($file) < $cutoff) {
                $this->delete($file);
            }
        }
    }

    private function delete(string $file): void
    {
        unlink($file);
        $this->logger->info("deleted $file");
    }
}
