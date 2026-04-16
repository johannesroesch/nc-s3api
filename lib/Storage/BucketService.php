<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Storage;

use OCA\NcS3Api\Exception\BucketAlreadyExistsException;
use OCA\NcS3Api\Exception\BucketNotEmptyException;
use OCA\NcS3Api\Exception\NoSuchBucketException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * CRUD operations on S3 buckets, backed by Nextcloud folders.
 */
class BucketService {
    public function __construct(
        private readonly IRootFolder   $rootFolder,
        private readonly StorageMapper $storageMapper,
    ) {}

    /**
     * @return list<array{name: string, creation_date: string}>
     */
    public function listBuckets(string $userId): array {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $baseDir    = $this->getOrCreateBaseDir($userFolder);

        $buckets = [];
        foreach ($baseDir->getDirectoryListing() as $node) {
            // Skip the hidden .uploads directory
            if ($node->getName() === '.uploads') {
                continue;
            }
            if ($node instanceof Folder) {
                $buckets[] = [
                    'name'          => $node->getName(),
                    'creation_date' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMtime()),
                ];
            }
        }

        // Sort by name (S3 returns buckets alphabetically)
        usort($buckets, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $buckets;
    }

    /**
     * @throws BucketAlreadyExistsException
     */
    public function createBucket(string $userId, string $bucket): void {
        $this->validateBucketName($bucket);
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $baseDir    = $this->getOrCreateBaseDir($userFolder);

        if ($baseDir->nodeExists($bucket)) {
            throw new BucketAlreadyExistsException($bucket);
        }
        $baseDir->newFolder($bucket);
    }

    /**
     * @throws NoSuchBucketException
     * @throws BucketNotEmptyException
     */
    public function deleteBucket(string $userId, string $bucket): void {
        $folder = $this->getBucketFolder($userId, $bucket);

        if (count($folder->getDirectoryListing()) > 0) {
            throw new BucketNotEmptyException($bucket);
        }
        $folder->delete();
    }

    /**
     * @throws NoSuchBucketException
     */
    public function headBucket(string $userId, string $bucket): array {
        $folder = $this->getBucketFolder($userId, $bucket);
        return [
            'name'  => $folder->getName(),
            'mtime' => $folder->getMtime(),
        ];
    }

    /**
     * @throws NoSuchBucketException
     */
    public function getBucketFolder(string $userId, string $bucket): Folder {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path       = $this->storageMapper->bucketPath($bucket);
        try {
            $node = $userFolder->get($path);
            if (!$node instanceof Folder) {
                throw new NoSuchBucketException($bucket);
            }
            return $node;
        } catch (NotFoundException) {
            throw new NoSuchBucketException($bucket);
        }
    }

    public function bucketExists(string $userId, string $bucket): bool {
        try {
            $this->getBucketFolder($userId, $bucket);
            return true;
        } catch (NoSuchBucketException) {
            return false;
        }
    }

    // -------------------------------------------------------------------------

    private function getOrCreateBaseDir(\OCP\Files\Folder $userFolder): Folder {
        $path = StorageMapper::BASE_DIR;
        try {
            $node = $userFolder->get($path);
            if ($node instanceof Folder) {
                return $node;
            }
        } catch (NotFoundException) {
            // create below
        }
        return $userFolder->newFolder($path);
    }

    private function validateBucketName(string $bucket): void {
        // S3 bucket name rules: 3-63 chars, lowercase letters, numbers, hyphens, dots
        // Cannot start or end with a hyphen or dot, no consecutive dots, not IP-like
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new \OCA\NcS3Api\Exception\S3Exception(
                \OCA\NcS3Api\S3\S3ErrorCodes::INVALID_BUCKET_NAME,
                "Invalid bucket name: $bucket",
            );
        }
        if (str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')) {
            throw new \OCA\NcS3Api\Exception\S3Exception(
                \OCA\NcS3Api\S3\S3ErrorCodes::INVALID_BUCKET_NAME,
                "Invalid bucket name: $bucket",
            );
        }
    }
}
