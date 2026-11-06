<?php

declare(strict_types=1);

use BackupTool\Config;
use BackupTool\Database\Database;
use BackupTool\Database\DatabaseBackup;
use BackupTool\Files\FilesBackup;
use BackupTool\Files\Path;
use BackupTool\LoggerFactory;
use BackupTool\Uploader\UploaderFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run `composer install --no-dev` first\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';

try {
    $config = Config::load(__DIR__ . '/config.php');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$logger = LoggerFactory::create($config);

// keyed on the real config path: __DIR__ changes with every deploy
$lock = (new LockFactory(new FlockStore()))->createLock('backup-tool:' . realpath(__DIR__ . '/config.php'));
if (!$lock->acquire()) {
    $logger->error('Backup skipped: the previous run is still running');
    exit(1);
}

try {
    $uploaders = [];
    foreach ($config->uploaders as $name => $settings) {
        $uploaders[$name] = UploaderFactory::create($name, $settings);
    }
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}

$backups = [
    ...array_map(fn (Database $db) => new DatabaseBackup($db, $config, $uploaders, $logger), $config->databases),
    ...array_map(fn (Path $path) => new FilesBackup($path, $config, $uploaders, $logger), $config->paths),
];

$logger->info(sprintf('Backup started: %d databases, %d paths', count($config->databases), count($config->paths)));
$results = ['ok' => [], 'failed' => []];
foreach ($backups as $backup) {
    $results[$backup->run() ? 'ok' : 'failed'][] = $backup->id;
}

$summary = sprintf('Backup finished: %d ok, %d failed', count($results['ok']), count($results['failed']));
foreach (array_filter($results) as $status => $ids) {
    $summary .= "\n$status: " . implode(', ', $ids);
}
$results['failed'] ? $logger->error($summary) : $logger->notice($summary);

exit($results['failed'] ? 1 : 0);
