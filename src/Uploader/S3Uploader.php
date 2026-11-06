<?php

declare(strict_types=1);

namespace BackupTool\Uploader;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;

readonly class S3Uploader implements Uploader
{
    public const TYPE = 's3';

    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix,
        private ?int $keepDays,
    ) {
    }

    public static function fromConfig(string $name, array $settings): self
    {
        foreach (['bucket', 'key', 'secret'] as $key) {
            if (empty($settings[$key])) {
                throw new RuntimeException("Uploader '$name': '$key' is not set in config.php");
            }
        }

        $options = [
            'version' => 'latest',
            'region' => $settings['region'] ?? 'auto',
            'credentials' => ['key' => $settings['key'], 'secret' => $settings['secret']],
        ];
        if (!empty($settings['endpoint'])) {
            $options['endpoint'] = $settings['endpoint'];
        }

        return new self(new S3Client($options), $settings['bucket'], trim($settings['prefix'] ?? '', '/'), $settings['keep_days'] ?? null);
    }

    public function upload(string $file, string $folder): void
    {
        $this->s3->upload($this->bucket, $this->prefix($folder) . basename($file), Utils::tryFopen($file, 'r'), acl: null);
    }

    public function deleteOldBackups(string $folder, string $newFile): void
    {
        if ($this->keepDays === null) {
            return;
        }

        $prefix = $this->prefix($folder);
        $cutoff = time() - $this->keepDays * 86400;
        $objects = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'Delimiter' => '/']);
        foreach ($objects->search('Contents[]') as $object) {
            if ($object['Key'] !== $prefix . basename($newFile) && str_ends_with($object['Key'], '.gz') && $object['LastModified']->getTimestamp() < $cutoff) {
                $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
            }
        }
    }

    private function prefix(string $folder): string
    {
        return ltrim("$this->prefix/$folder/", '/');
    }
}
