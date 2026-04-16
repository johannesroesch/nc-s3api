<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Storage;

use OCA\NcS3Api\Exception\NoSuchBucketException;
use OCA\NcS3Api\Exception\NoSuchKeyException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * CRUD operations on S3 objects, backed by Nextcloud files.
 */
class ObjectService {
    private const CHUNK_SIZE = 1024 * 1024 * 4; // 4 MiB streaming chunks

    public function __construct(
        private readonly IRootFolder   $rootFolder,
        private readonly BucketService $bucketService,
        private readonly StorageMapper $storageMapper,
    ) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * @throws NoSuchBucketException
     * @throws NoSuchKeyException
     * @return array{file: File, etag: string, content_type: string, size: int, last_modified: string}
     */
    public function getObjectMeta(string $userId, string $bucket, string $key): array {
        $file = $this->getFile($userId, $bucket, $key);
        return $this->buildMeta($file, $key);
    }

    /**
     * Open a read stream for the object.
     * @return resource
     */
    public function openReadStream(string $userId, string $bucket, string $key): mixed {
        $file = $this->getFile($userId, $bucket, $key);
        $handle = $file->fopen('r');
        if ($handle === false) {
            throw new S3Exception(S3ErrorCodes::INTERNAL_ERROR, 'Cannot open file for reading');
        }
        return $handle;
    }

    /**
     * Open a range-limited read stream.
     * Returns [stream, actualLength, contentRange] or throws InvalidRange.
     *
     * @return array{resource, int, string}
     */
    public function openRangeStream(string $userId, string $bucket, string $key, string $rangeHeader): array {
        $file = $this->getFile($userId, $bucket, $key);
        $size = $file->getSize();

        [$start, $end] = $this->parseRange($rangeHeader, $size);
        $length        = $end - $start + 1;
        $contentRange  = "bytes {$start}-{$end}/{$size}";

        $handle = $file->fopen('r');
        if ($handle === false) {
            throw new S3Exception(S3ErrorCodes::INTERNAL_ERROR, 'Cannot open file for reading');
        }
        if ($start > 0) {
            fseek($handle, $start);
        }

        // Wrap in a length-limited stream
        $limited = $this->limitStream($handle, $length);
        return [$limited, $length, $contentRange];
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Write an object from a stream.
     * Returns the ETag of the written file.
     */
    public function putObject(
        string  $userId,
        string  $bucket,
        string  $key,
        mixed   $bodyStream,
        ?string $contentType = null,
    ): string {
        $this->bucketService->getBucketFolder($userId, $bucket); // assert bucket exists

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path       = $this->storageMapper->objectPath($bucket, $key);

        // Ensure parent directories exist
        $this->ensureParentDir($userFolder, $path);

        // Write the file
        try {
            $parent   = $this->getParentFolder($userFolder, $path);
            $fileName = basename($path);

            if ($parent->nodeExists($fileName)) {
                /** @var File $file */
                $file = $parent->get($fileName);
                if (!$file instanceof File) {
                    throw new S3Exception(S3ErrorCodes::INVALID_REQUEST, "Key '$key' refers to a directory");
                }
                $handle = $file->fopen('w');
            } else {
                $file   = $parent->newFile($fileName);
                $handle = $file->fopen('w');
            }

            if ($handle === false) {
                throw new S3Exception(S3ErrorCodes::INTERNAL_ERROR, 'Cannot open file for writing');
            }

            if (is_resource($bodyStream)) {
                while (!feof($bodyStream)) {
                    fwrite($handle, fread($bodyStream, self::CHUNK_SIZE));
                }
            } elseif (is_string($bodyStream)) {
                fwrite($handle, $bodyStream);
            }
            fclose($handle);

        } catch (NotPermittedException $e) {
            throw new \OCA\NcS3Api\Exception\AccessDeniedException('Write access denied');
        }

        return $this->computeETag($file);
    }

    /**
     * Delete an object.
     * @throws NoSuchKeyException
     */
    public function deleteObject(string $userId, string $bucket, string $key): void {
        $file = $this->getFile($userId, $bucket, $key);
        try {
            $file->delete();
        } catch (NotPermittedException) {
            throw new \OCA\NcS3Api\Exception\AccessDeniedException('Delete access denied');
        }
    }

    /**
     * Copy an object (within the same Nextcloud instance).
     * Returns the new ETag.
     */
    public function copyObject(
        string $userId,
        string $srcBucket,
        string $srcKey,
        string $dstBucket,
        string $dstKey,
    ): array {
        $srcFile    = $this->getFile($userId, $srcBucket, $srcKey);
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $dstPath    = $this->storageMapper->objectPath($dstBucket, $dstKey);

        $this->ensureParentDir($userFolder, $dstPath);
        $parent   = $this->getParentFolder($userFolder, $dstPath);
        $fileName = basename($dstPath);

        // Delete target if it exists (S3 overwrites silently)
        if ($parent->nodeExists($fileName)) {
            $parent->get($fileName)->delete();
        }

        $newFile  = $srcFile->copy($parent->getPath() . '/' . $fileName);
        $etag     = $this->computeETag($newFile);
        $modified = gmdate('Y-m-d\TH:i:s.000\Z', $newFile->getMtime());

        return ['etag' => $etag, 'last_modified' => $modified];
    }

    // -------------------------------------------------------------------------
    // Listing helpers
    // -------------------------------------------------------------------------

    /**
     * List all objects with an optional prefix, returning a flat list of path → meta arrays.
     *
     * @return list<array{key: string, last_modified: string, etag: string, size: int, storage_class: string}>
     */
    public function listObjects(string $userId, string $bucket, string $prefix = ''): array {
        $bucketFolder = $this->bucketService->getBucketFolder($userId, $bucket);
        $results      = [];
        $this->walkFolder($bucketFolder, $prefix, '', $results);
        return $results;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @throws NoSuchBucketException|NoSuchKeyException */
    public function getFile(string $userId, string $bucket, string $key): File {
        $this->bucketService->getBucketFolder($userId, $bucket); // assert bucket

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path       = $this->storageMapper->objectPath($bucket, $key);
        try {
            $node = $userFolder->get($path);
            if (!$node instanceof File) {
                throw new NoSuchKeyException($bucket, $key);
            }
            return $node;
        } catch (NotFoundException) {
            throw new NoSuchKeyException($bucket, $key);
        }
    }

    public function objectExists(string $userId, string $bucket, string $key): bool {
        try {
            $this->getFile($userId, $bucket, $key);
            return true;
        } catch (NoSuchKeyException|NoSuchBucketException) {
            return false;
        }
    }

    /** @return array{file: File, etag: string, content_type: string, size: int, last_modified: string} */
    private function buildMeta(File $file, string $key): array {
        return [
            'file'          => $file,
            'etag'          => $this->computeETag($file),
            'content_type'  => $file->getMimeType(),
            'size'          => $file->getSize(),
            'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z', $file->getMtime()),
        ];
    }

    private function computeETag(File $file): string {
        // Use MD5 of content if available via hash(), else fall back to size+mtime
        try {
            return '"' . $file->hash('md5') . '"';
        } catch (\Throwable) {
            return '"' . md5($file->getSize() . '-' . $file->getMtime()) . '"';
        }
    }

    private function ensureParentDir(Folder $userFolder, string $objectPath): void {
        $dir = dirname($objectPath);
        if ($dir === '.' || $dir === '') return;

        $parts   = explode('/', $dir);
        $current = $userFolder;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($current->nodeExists($part)) {
                $current = $current->get($part);
            } else {
                $current = $current->newFolder($part);
            }
        }
    }

    private function getParentFolder(Folder $userFolder, string $objectPath): Folder {
        $dir = dirname($objectPath);
        if ($dir === '.' || $dir === '') return $userFolder;
        try {
            $node = $userFolder->get($dir);
            if ($node instanceof Folder) return $node;
        } catch (NotFoundException) {}
        return $userFolder->newFolder($dir);
    }

    /** @param list<array> $results Passed by reference */
    private function walkFolder(Folder $folder, string $prefix, string $currentPath, array &$results): void {
        foreach ($folder->getDirectoryListing() as $node) {
            $nodePath = $currentPath !== '' ? $currentPath . '/' . $node->getName() : $node->getName();

            if ($node instanceof File) {
                if ($prefix === '' || str_starts_with($nodePath, $prefix)) {
                    $results[] = [
                        'key'           => $nodePath,
                        'last_modified' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMtime()),
                        'etag'          => '"' . md5($node->getSize() . '-' . $node->getMtime()) . '"',
                        'size'          => $node->getSize(),
                        'storage_class' => 'STANDARD',
                    ];
                }
            } elseif ($node instanceof Folder) {
                $this->walkFolder($node, $prefix, $nodePath, $results);
            }
        }
    }

    private function parseRange(string $header, int $fileSize): array {
        // Format: "bytes=start-end" or "bytes=start-" or "bytes=-suffix"
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m)) {
            throw new S3Exception(S3ErrorCodes::INVALID_RANGE, 'Invalid Range header');
        }
        if ($m[1] === '' && $m[2] === '') {
            throw new S3Exception(S3ErrorCodes::INVALID_RANGE, 'Invalid Range header');
        }

        if ($m[1] === '') {
            // Suffix range: bytes=-N
            $start = max(0, $fileSize - (int)$m[2]);
            $end   = $fileSize - 1;
        } elseif ($m[2] === '') {
            $start = (int)$m[1];
            $end   = $fileSize - 1;
        } else {
            $start = (int)$m[1];
            $end   = (int)$m[2];
        }

        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            throw new S3Exception(S3ErrorCodes::INVALID_RANGE, "Range $header not satisfiable for size $fileSize");
        }

        return [$start, $end];
    }

    /** Wrap a stream to limit reading to $length bytes. */
    private function limitStream(mixed $source, int $length): mixed {
        $temp   = fopen('php://temp', 'r+b');
        $copied = stream_copy_to_stream($source, $temp, $length);
        rewind($temp);
        return $temp;
    }
}
