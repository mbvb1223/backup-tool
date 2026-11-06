<?php

declare(strict_types=1);

namespace BackupTool\Uploader;

interface Uploader
{
    public function upload(string $file, string $folder): void;

    public function deleteOldBackups(string $folder, string $newFile): void;
}
