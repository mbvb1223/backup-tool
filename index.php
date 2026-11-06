<?php

declare(strict_types=1);

use DbBackup\Config;
use DbBackup\Database\DatabaseBackup;
use DbBackup\Files\FilesBackup;
use DbBackup\LoggerFactory;
use DbBackup\Uploader\UploaderFactory;

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

try {
    $uploaders = [];
    foreach ($config->uploaders as $name => $settings) {
        $uploaders[$name] = UploaderFactory::create($name, $settings);
    }
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}

$jobs = [];
foreach ($config->databases as $db) {
    $jobs['DB']["$db->connection/$db->name"] = new DatabaseBackup($db, $config, $uploaders, $logger);
}
foreach ($config->paths as $files) {
    $jobs['Files'][$files->name] = new FilesBackup($files, $config, $uploaders, $logger);
}

$logger->info(sprintf('Backup started: %d databases, %d files', count($config->databases), count($config->paths)));
$ok = 0;
$failed = 0;
$details = '';
foreach ($jobs as $type => $typeJobs) {
    $results = ['OK' => [], 'failed' => []];
    foreach ($typeJobs as $name => $backup) {
        try {
            $success = $backup->run();
        } catch (Throwable $e) {
            fwrite(STDERR, "$type $name: {$e->getMessage()}\n");
            $success = false;
        }
        $results[$success ? 'OK' : 'failed'][] = $name;
    }
    foreach ($results as $status => $names) {
        if ($names) {
            $details .= "\n$type $status: " . implode(', ', $names);
        }
    }
    $ok += count($results['OK']);
    $failed += count($results['failed']);
}

$summary = sprintf('Backup finished: %d ok, %d failed', $ok, $failed) . $details;
$failed ? $logger->error($summary) : $logger->notice($summary);

exit($failed ? 1 : 0);
