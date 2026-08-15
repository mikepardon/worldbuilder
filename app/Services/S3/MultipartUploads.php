<?php

declare(strict_types=1);

namespace App\Services\S3;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * A thin wrapper over the S3 client's multipart-upload API, used to sign browser-driven direct-to-S3
 * uploads (via Uppy) so multi-gigabyte session recordings never stream through PHP. Kept behind this
 * class so controllers depend on a small, fakeable seam rather than the AWS SDK directly.
 */
class MultipartUploads
{
    private const DISK = 's3';

    /** Start a multipart upload for a key and return the S3 upload id. */
    public function create(string $key, string $contentType): string
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
        ]);

        return (string) $result['UploadId'];
    }

    /** A short-lived presigned URL the browser PUTs a single part to. */
    public function signPart(string $key, string $uploadId, int $partNumber): string
    {
        $client = $this->client();
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $client->createPresignedRequest($command, '+2 hours')->getUri();
    }

    /**
     * The parts uploaded so far, so an interrupted upload can resume.
     *
     * @return list<array{PartNumber: int, Size: int, ETag: string}>
     */
    public function listParts(string $key, string $uploadId): array
    {
        $result = $this->client()->listParts([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        return collect($result['Parts'] ?? [])
            ->map(fn (array $part): array => [
                'PartNumber' => (int) $part['PartNumber'],
                'Size' => (int) ($part['Size'] ?? 0),
                'ETag' => (string) $part['ETag'],
            ])
            ->values()
            ->all();
    }

    /**
     * Assemble the uploaded parts into the final object and return its URL.
     *
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function complete(string $key, string $uploadId, array $parts): string
    {
        $result = $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_values($parts)],
        ]);

        return (string) ($result['Location'] ?? '');
    }

    /** Discard an abandoned upload so its parts don't linger and accrue storage cost. */
    public function abort(string $key, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
    }

    private function client(): S3Client
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk(self::DISK);

        return $disk->getClient();
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.'.self::DISK.'.bucket');
    }
}
