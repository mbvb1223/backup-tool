<?php

declare(strict_types=1);

namespace DbBackup;

use Closure;
use DbBackup\Uploader\Uploader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

abstract readonly class Backup
{
    private LockFactory $locks;

    public function __construct(
        private string $dir,
        private ?int $keepDays,
        /** @var array<string, Uploader> */
        private array $uploaders,
        private LoggerInterface $logger,
    ) {
        $this->locks = new LockFactory(new FlockStore());
    }

    /** @param Closure(string $dir): string $dump */
    protected function runLocked(string $id, Closure $dump): bool
    {
        $lock = $this->locks->createLock("db-backup:$id");
        if (!$lock->acquire()) {
            $this->logger->error("$id: skipped, previous backup is still running");
            return false;
        }

        try {
            return $this->backup($id, $dump);
        } catch (Throwable $e) {
            $this->logger->error("$id: backup failed: {$e->getMessage()}");
            return false;
        } finally {
            $lock->release();
        }
    }

    private function backup(string $id, Closure $dump): bool
    {
        $dir = "$this->dir/$id";
        $this->deletePartialDumps($dir);

        $started = microtime(true);
        $file = $dump($dir);
        $this->logger->info(sprintf('%s: dumped to %s (%.1f MB, %.1fs)', $id, $file, filesize($file) / 1048576, microtime(true) - $started));

        $allUploaded = $this->upload($id, $file);

        if ($this->keepDays > 0 || ($this->keepDays === 0 && $allUploaded)) {
            $this->deleteOldDumps($dir, $file);
        }
        if ($this->keepDays === 0 && $allUploaded) {
            $this->delete($file);
        }

        return $allUploaded;
    }

    private function upload(string $id, string $file): bool
    {
        $allUploaded = true;
        foreach ($this->uploaders as $name => $uploader) {
            $started = microtime(true);
            try {
                $uploader->upload($file, $id);
                $this->logger->info(sprintf('%s: uploaded to %s (%.1fs)', $id, $name, microtime(true) - $started));
            } catch (Throwable $e) {
                $allUploaded = false;
                $this->logger->error("$id: upload to $name failed: {$e->getMessage()}");
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
