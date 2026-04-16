<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Db\MultipartPart;
use OCA\NcS3Api\Db\MultipartPartMapper;
use OCA\NcS3Api\Db\MultipartUpload;
use OCA\NcS3Api\Db\MultipartUploadMapper;
use OCA\NcS3Api\Exception\NoSuchUploadException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Storage\BucketService;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Storage\StorageMapper;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;

class MultipartHandler {
    private const MIN_PART_SIZE = 5 * 1024 * 1024; // 5 MiB (S3 minimum, except last part)

    public function __construct(
        private readonly MultipartUploadMapper $uploadMapper,
        private readonly MultipartPartMapper   $partMapper,
        private readonly BucketService         $bucketService,
        private readonly ObjectService         $objectService,
        private readonly StorageMapper         $storageMapper,
        private readonly IRootFolder           $rootFolder,
        private readonly XmlWriter             $xmlWriter,
        private readonly XmlReader             $xmlReader,
    ) {}

    // -------------------------------------------------------------------------
    // InitiateMultipartUpload
    // -------------------------------------------------------------------------

    public function initiateMultipartUpload(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';
        $key    = $request->key ?? '';

        // Assert bucket exists
        $this->bucketService->getBucketFolder($auth->userId, $bucket);

        $uploadId = bin2hex(random_bytes(16));

        $entity = new MultipartUpload();
        $entity->setUploadId($uploadId);
        $entity->setBucket($bucket);
        $entity->setObjectKey($key);
        $entity->setUserId($auth->userId);
        $entity->setContentType($request->getHeader('content-type') ?: null);

        // Collect x-amz-meta-* headers
        $meta = [];
        foreach ($request->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $meta[$name] = $value;
            }
        }
        if (!empty($meta)) {
            $entity->setMetadata(json_encode($meta, JSON_UNESCAPED_UNICODE));
        }

        $entity->setCreatedAt(time());
        $this->uploadMapper->insert($entity);

        $xml = $this->xmlWriter->initiateMultipartUploadResult($bucket, $key, $uploadId);
        return S3Response::ok($xml, ['x-amz-id-2' => $uploadId]);
    }

    // -------------------------------------------------------------------------
    // UploadPart
    // -------------------------------------------------------------------------

    public function uploadPart(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $uploadId   = $request->getQuery('uploadId');
        $partNumber = (int)$request->getQuery('partNumber');

        if ($partNumber < 1 || $partNumber > 10000) {
            throw new S3Exception(S3ErrorCodes::INVALID_ARGUMENT, 'partNumber must be between 1 and 10000');
        }

        $upload = $this->getUpload($uploadId, $auth->userId);

        // Write part content to a temp file in Nextcloud storage
        $userFolder = $this->rootFolder->getUserFolder($auth->userId);
        $tmpPath    = $this->storageMapper->partPath($uploadId, $partNumber);

        // Ensure uploads directory exists
        $uploadsDir = $this->storageMapper->uploadDir($uploadId);
        $this->ensureDir($userFolder, $uploadsDir);

        // Write to temp file
        $dirParts = explode('/', dirname($tmpPath));
        $dir      = $userFolder;
        foreach ($dirParts as $segment) {
            if ($segment === '' || $segment === '.') continue;
            $dir = $dir->nodeExists($segment) ? $dir->get($segment) : $dir->newFolder($segment);
        }
        $fileName = basename($tmpPath);

        if ($dir->nodeExists($fileName)) {
            $tmpFile = $dir->get($fileName);
            $handle  = $tmpFile->fopen('w');
        } else {
            $tmpFile = $dir->newFile($fileName);
            $handle  = $tmpFile->fopen('w');
        }

        if ($handle === false) {
            throw new S3Exception(S3ErrorCodes::INTERNAL_ERROR, 'Cannot write part data');
        }

        $size = 0;
        $md5ctx = hash_init('md5');
        if (is_resource($request->bodyStream)) {
            while (!feof($request->bodyStream)) {
                $chunk = fread($request->bodyStream, 1024 * 1024);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($handle, $chunk);
                    hash_update($md5ctx, $chunk);
                    $size += strlen($chunk);
                }
            }
        }
        fclose($handle);

        $etag = '"' . hash_final($md5ctx) . '"';

        // Upsert part record
        $part = new MultipartPart();
        $part->setUploadId($uploadId);
        $part->setPartNumber($partNumber);
        $part->setEtag($etag);
        $part->setSize($size);
        $part->setTmpPath($tmpPath);
        $part->setCreatedAt(time());
        $this->partMapper->upsertPart($part);

        return new S3Response(statusCode: 200, headers: ['ETag' => $etag]);
    }

    // -------------------------------------------------------------------------
    // CompleteMultipartUpload
    // -------------------------------------------------------------------------

    public function completeMultipartUpload(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $uploadId = $request->getQuery('uploadId');
        $bucket   = $request->bucket ?? '';
        $key      = $request->key ?? '';

        $upload = $this->getUpload($uploadId, $auth->userId);

        $body    = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
        $reqParts = $this->xmlReader->completeMultipartUpload($body);

        // Validate part numbers are in ascending order
        $prevNumber = 0;
        foreach ($reqParts as $rp) {
            if ($rp['part_number'] <= $prevNumber) {
                throw new S3Exception(S3ErrorCodes::INVALID_PART_ORDER, 'Parts must be in ascending order');
            }
            $prevNumber = $rp['part_number'];
        }

        // Load stored parts
        $storedParts = [];
        foreach ($this->partMapper->findByUploadId($uploadId) as $p) {
            $storedParts[$p->getPartNumber()] = $p;
        }

        // Validate each requested part exists with matching ETag
        foreach ($reqParts as $rp) {
            $stored = $storedParts[$rp['part_number']] ?? null;
            if ($stored === null) {
                throw new S3Exception(S3ErrorCodes::INVALID_PART, "Part {$rp['part_number']} not found");
            }
            // ETags may or may not have quotes — normalise
            $storedEtag   = trim($stored->getEtag(), '"');
            $requestedEtag = trim($rp['etag'], '"');
            if ($storedEtag !== $requestedEtag) {
                throw new S3Exception(S3ErrorCodes::INVALID_PART, "ETag mismatch for part {$rp['part_number']}");
            }
        }

        // Concatenate parts into final object
        $userFolder = $this->rootFolder->getUserFolder($auth->userId);
        $finalEtag  = $this->concatenateParts($userFolder, $reqParts, $storedParts, $bucket, $key, $upload);

        // Clean up multipart data
        $this->cleanupUpload($userFolder, $uploadId);

        $location = "https://{$request->host}/apps/nc_s3api/s3/{$bucket}/{$key}";
        $xml      = $this->xmlWriter->completeMultipartUploadResult($location, $bucket, $key, $finalEtag);
        return S3Response::ok($xml);
    }

    // -------------------------------------------------------------------------
    // AbortMultipartUpload
    // -------------------------------------------------------------------------

    public function abortMultipartUpload(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $uploadId = $request->getQuery('uploadId');

        $upload = $this->getUpload($uploadId, $auth->userId);

        $userFolder = $this->rootFolder->getUserFolder($auth->userId);
        $this->cleanupUpload($userFolder, $uploadId);

        return S3Response::noContent();
    }

    // -------------------------------------------------------------------------
    // ListMultipartUploads
    // -------------------------------------------------------------------------

    public function listMultipartUploads(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $bucket = $request->bucket ?? '';

        $this->bucketService->getBucketFolder($auth->userId, $bucket);

        $uploads = $this->uploadMapper->findByUserAndBucket($auth->userId, $bucket);
        $list    = array_map(fn(MultipartUpload $u) => [
            'key'       => $u->getObjectKey(),
            'upload_id' => $u->getUploadId(),
            'initiated' => gmdate('Y-m-d\TH:i:s.000\Z', $u->getCreatedAt()),
        ], $uploads);

        $xml = $this->xmlWriter->listMultipartUploads($bucket, $list, false);
        return S3Response::ok($xml);
    }

    // -------------------------------------------------------------------------
    // ListParts
    // -------------------------------------------------------------------------

    public function listParts(S3Request $request, AuthContext $auth): S3Response {
        $auth->requireAuthenticated();
        $uploadId = $request->getQuery('uploadId');
        $bucket   = $request->bucket ?? '';
        $key      = $request->key ?? '';

        $this->getUpload($uploadId, $auth->userId);

        $maxParts  = min((int)($request->queryParams['max-parts'] ?? 1000), 1000);
        $partMarker = (int)$request->getQuery('part-number-marker');

        $parts = $this->partMapper->findByUploadId($uploadId);
        $parts = array_filter($parts, fn($p) => $p->getPartNumber() > $partMarker);
        $parts = array_values($parts);

        $isTruncated = count($parts) > $maxParts;
        $parts       = array_slice($parts, 0, $maxParts);

        $list = array_map(fn(MultipartPart $p) => [
            'part_number'  => $p->getPartNumber(),
            'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z', $p->getCreatedAt()),
            'etag'         => $p->getEtag(),
            'size'         => $p->getSize(),
        ], $parts);

        $nextMarker = $isTruncated && !empty($parts) ? end($parts)['part_number'] : null;
        $xml = $this->xmlWriter->listParts($bucket, $key, $uploadId, $list, $isTruncated, $nextMarker);
        return S3Response::ok($xml);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @throws NoSuchUploadException */
    private function getUpload(string $uploadId, string $userId): MultipartUpload {
        try {
            $upload = $this->uploadMapper->findByUploadId($uploadId);
            if ($upload->getUserId() !== $userId) {
                throw new NoSuchUploadException($uploadId);
            }
            return $upload;
        } catch (DoesNotExistException) {
            throw new NoSuchUploadException($uploadId);
        }
    }

    private function concatenateParts(
        \OCP\Files\Folder $userFolder,
        array             $reqParts,
        array             $storedParts,
        string            $bucket,
        string            $key,
        MultipartUpload   $upload,
    ): string {
        $finalPath = $this->storageMapper->objectPath($bucket, $key);

        // Ensure parent directories
        $this->ensureDirForFile($userFolder, $finalPath);

        $parentPath = dirname($finalPath);
        $parent = $parentPath !== '.' ? $userFolder->get($parentPath) : $userFolder;
        $fileName   = basename($finalPath);

        if ($parent->nodeExists($fileName)) {
            $finalFile = $parent->get($fileName);
            $handle    = $finalFile->fopen('w');
        } else {
            $finalFile = $parent->newFile($fileName);
            $handle    = $finalFile->fopen('w');
        }

        if ($handle === false) {
            throw new S3Exception(S3ErrorCodes::INTERNAL_ERROR, 'Cannot write final object');
        }

        $md5ctx = hash_init('md5');
        foreach ($reqParts as $rp) {
            $stored     = $storedParts[$rp['part_number']];
            $tmpPath    = $stored->getTmpPath();
            $tmpNode    = $userFolder->get($tmpPath);
            $partHandle = $tmpNode->fopen('r');
            if ($partHandle !== false) {
                while (!feof($partHandle)) {
                    $chunk = fread($partHandle, 1024 * 1024);
                    if ($chunk !== false && $chunk !== '') {
                        fwrite($handle, $chunk);
                        hash_update($md5ctx, $chunk);
                    }
                }
                fclose($partHandle);
            }
        }
        fclose($handle);

        return '"' . hash_final($md5ctx) . '-' . count($reqParts) . '"';
    }

    private function cleanupUpload(\OCP\Files\Folder $userFolder, string $uploadId): void {
        // Delete temp files
        $uploadDir = $this->storageMapper->uploadDir($uploadId);
        try {
            $userFolder->get($uploadDir)->delete();
        } catch (\Throwable) {
            // Best effort
        }

        // Delete DB records
        $this->partMapper->deleteByUploadId($uploadId);
        $this->uploadMapper->deleteByUploadId($uploadId);
    }

    private function ensureDir(\OCP\Files\Folder $userFolder, string $path): void {
        $parts   = explode('/', $path);
        $current = $userFolder;
        foreach ($parts as $segment) {
            if ($segment === '' || $segment === '.') continue;
            $current = $current->nodeExists($segment)
                ? $current->get($segment)
                : $current->newFolder($segment);
        }
    }

    private function ensureDirForFile(\OCP\Files\Folder $userFolder, string $filePath): void {
        $dir = dirname($filePath);
        if ($dir !== '.' && $dir !== '') {
            $this->ensureDir($userFolder, $dir);
        }
    }
}
