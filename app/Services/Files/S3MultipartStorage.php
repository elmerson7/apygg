<?php

namespace App\Services\Files;

use App\Contracts\MultipartStorageInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Subida por partes sobre un disco S3 de Laravel (`AwsS3V3Adapter`). Sin estado: el cliente se pide al disco
 * en cada llamada (Octane).
 */
class S3MultipartStorage implements MultipartStorageInterface
{
    public function supports(string $disk): bool
    {
        return Storage::disk($disk) instanceof AwsS3V3Adapter;
    }

    public function initiate(string $disk, string $path, string $mimeType): string
    {
        $result = $this->client($disk)->createMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'ContentType' => $mimeType,
        ]);

        return (string) $result['UploadId'];
    }

    public function presignPart(string $disk, string $path, string $uploadId, int $partNumber, \DateTimeInterface $expiresAt): array
    {
        $client = $this->client($disk);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return ['url' => (string) $client->createPresignedRequest($command, $expiresAt)->getUri(), 'headers' => []];
    }

    public function listParts(string $disk, string $path, string $uploadId): ?array
    {
        $client = $this->client($disk);
        $parts = [];
        $marker = 0;

        try {
            do {
                $page = $client->listParts([
                    'Bucket' => $this->bucket($disk),
                    'Key' => $this->key($disk, $path),
                    'UploadId' => $uploadId,
                    'PartNumberMarker' => $marker,
                    'MaxParts' => 1000,
                ]);

                foreach ((array) ($page['Parts'] ?? []) as $part) {
                    $parts[(int) $part['PartNumber']] = ['etag' => (string) $part['ETag'], 'size' => (int) $part['Size']];
                }

                $marker = (int) ($page['NextPartNumberMarker'] ?? 0);
            } while (! empty($page['IsTruncated']));
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                return null;
            }

            throw $e;
        }

        ksort($parts);

        return $parts;
    }

    public function complete(string $disk, string $path, string $uploadId, array $etags): void
    {
        ksort($etags);

        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $this->key($disk, $path),
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(
                fn (int $number, string $etag): array => ['PartNumber' => $number, 'ETag' => $etag],
                array_keys($etags),
                array_values($etags),
            )],
        ]);
    }

    public function abort(string $disk, string $path, string $uploadId): void
    {
        try {
            $this->client($disk)->abortMultipartUpload([
                'Bucket' => $this->bucket($disk),
                'Key' => $this->key($disk, $path),
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
        }
    }

    private function client(string $disk): S3Client
    {
        /** @var AwsS3V3Adapter $adapter */
        $adapter = Storage::disk($disk);

        return $adapter->getClient();
    }

    private function bucket(string $disk): string
    {
        return (string) config("filesystems.disks.{$disk}.bucket");
    }

    /** Clave real en el bucket: respeta el `root` opcional del disco (Flysystem lo antepone). */
    private function key(string $disk, string $path): string
    {
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');

        return ($root !== '' ? $root.'/' : '').ltrim($path, '/');
    }
}
