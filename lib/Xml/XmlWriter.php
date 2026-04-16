<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Xml;

/**
 * Builds S3-compatible XML response bodies.
 *
 * All methods return a complete UTF-8 XML string.  The S3 namespace
 * http://s3.amazonaws.com/doc/2006-03-01/ is included where required.
 */
final class XmlWriter {
    private const NS = 'http://s3.amazonaws.com/doc/2006-03-01/';

    // -------------------------------------------------------------------------
    // ListBuckets
    // -------------------------------------------------------------------------

    /**
     * @param array{id: string, display_name: string} $owner
     * @param list<array{name: string, creation_date: string}> $buckets
     */
    public function listBuckets(array $owner, array $buckets): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListAllMyBucketsResult', self::NS);

        $w->startElement('Owner');
        $w->writeElement('ID', $owner['id']);
        $w->writeElement('DisplayName', $owner['display_name']);
        $w->endElement(); // Owner

        $w->startElement('Buckets');
        foreach ($buckets as $b) {
            $w->startElement('Bucket');
            $w->writeElement('Name', $b['name']);
            $w->writeElement('CreationDate', $b['creation_date']);
            $w->endElement();
        }
        $w->endElement(); // Buckets

        $w->endElement(); // ListAllMyBucketsResult
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // ListObjects / ListObjectsV2
    // -------------------------------------------------------------------------

    /**
     * @param array{
     *   name: string,
     *   prefix: string,
     *   delimiter: string,
     *   max_keys: int,
     *   is_truncated: bool,
     *   marker: string,
     *   next_marker: string,
     *   encoding_type?: string,
     * } $ctx
     * @param list<array{key: string, last_modified: string, etag: string, size: int, storage_class: string, owner?: array}> $objects
     * @param list<string> $commonPrefixes
     */
    public function listObjects(array $ctx, array $objects, array $commonPrefixes): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListBucketResult', self::NS);

        $w->writeElement('Name', $ctx['name']);
        $w->writeElement('Prefix', $ctx['prefix']);
        $w->writeElement('Delimiter', $ctx['delimiter']);
        $w->writeElement('MaxKeys', (string) $ctx['max_keys']);
        $w->writeElement('IsTruncated', $ctx['is_truncated'] ? 'true' : 'false');
        $w->writeElement('Marker', $ctx['marker']);
        if ($ctx['next_marker'] !== '') {
            $w->writeElement('NextMarker', $ctx['next_marker']);
        }
        if (!empty($ctx['encoding_type'])) {
            $w->writeElement('EncodingType', $ctx['encoding_type']);
        }

        foreach ($objects as $obj) {
            $this->writeObject($w, $obj);
        }
        foreach ($commonPrefixes as $cp) {
            $w->startElement('CommonPrefixes');
            $w->writeElement('Prefix', $cp);
            $w->endElement();
        }

        $w->endElement();
        return $this->flush($w);
    }

    /**
     * @param array{
     *   name: string,
     *   prefix: string,
     *   delimiter: string,
     *   max_keys: int,
     *   is_truncated: bool,
     *   key_count: int,
     *   continuation_token?: string,
     *   next_continuation_token?: string,
     *   start_after?: string,
     *   encoding_type?: string,
     * } $ctx
     * @param list<array{key: string, last_modified: string, etag: string, size: int, storage_class: string}> $objects
     * @param list<string> $commonPrefixes
     */
    public function listObjectsV2(array $ctx, array $objects, array $commonPrefixes): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListBucketResult', self::NS);

        $w->writeElement('Name', $ctx['name']);
        $w->writeElement('Prefix', $ctx['prefix']);
        $w->writeElement('Delimiter', $ctx['delimiter']);
        $w->writeElement('MaxKeys', (string) $ctx['max_keys']);
        $w->writeElement('KeyCount', (string) $ctx['key_count']);
        $w->writeElement('IsTruncated', $ctx['is_truncated'] ? 'true' : 'false');
        if (!empty($ctx['continuation_token'])) {
            $w->writeElement('ContinuationToken', $ctx['continuation_token']);
        }
        if (!empty($ctx['next_continuation_token'])) {
            $w->writeElement('NextContinuationToken', $ctx['next_continuation_token']);
        }
        if (!empty($ctx['start_after'])) {
            $w->writeElement('StartAfter', $ctx['start_after']);
        }
        if (!empty($ctx['encoding_type'])) {
            $w->writeElement('EncodingType', $ctx['encoding_type']);
        }

        foreach ($objects as $obj) {
            $this->writeObject($w, $obj);
        }
        foreach ($commonPrefixes as $cp) {
            $w->startElement('CommonPrefixes');
            $w->writeElement('Prefix', $cp);
            $w->endElement();
        }

        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Multipart Upload responses
    // -------------------------------------------------------------------------

    public function initiateMultipartUploadResult(string $bucket, string $key, string $uploadId): string {
        $w = $this->writer();
        $w->startElementNS(null, 'InitiateMultipartUploadResult', self::NS);
        $w->writeElement('Bucket', $bucket);
        $w->writeElement('Key', $key);
        $w->writeElement('UploadId', $uploadId);
        $w->endElement();
        return $this->flush($w);
    }

    public function completeMultipartUploadResult(string $location, string $bucket, string $key, string $etag): string {
        $w = $this->writer();
        $w->startElementNS(null, 'CompleteMultipartUploadResult', self::NS);
        $w->writeElement('Location', $location);
        $w->writeElement('Bucket', $bucket);
        $w->writeElement('Key', $key);
        $w->writeElement('ETag', $etag);
        $w->endElement();
        return $this->flush($w);
    }

    /**
     * @param list<array{part_number: int, last_modified: string, etag: string, size: int}> $parts
     */
    public function listParts(string $bucket, string $key, string $uploadId, array $parts, bool $isTruncated, ?int $nextPartNumberMarker = null): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListPartsResult', self::NS);
        $w->writeElement('Bucket', $bucket);
        $w->writeElement('Key', $key);
        $w->writeElement('UploadId', $uploadId);
        $w->writeElement('IsTruncated', $isTruncated ? 'true' : 'false');
        if ($nextPartNumberMarker !== null) {
            $w->writeElement('NextPartNumberMarker', (string) $nextPartNumberMarker);
        }
        foreach ($parts as $p) {
            $w->startElement('Part');
            $w->writeElement('PartNumber', (string) $p['part_number']);
            $w->writeElement('LastModified', $p['last_modified']);
            $w->writeElement('ETag', $p['etag']);
            $w->writeElement('Size', (string) $p['size']);
            $w->endElement();
        }
        $w->endElement();
        return $this->flush($w);
    }

    /**
     * @param list<array{key: string, upload_id: string, initiated: string}> $uploads
     */
    public function listMultipartUploads(string $bucket, array $uploads, bool $isTruncated): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListMultipartUploadsResult', self::NS);
        $w->writeElement('Bucket', $bucket);
        $w->writeElement('IsTruncated', $isTruncated ? 'true' : 'false');
        foreach ($uploads as $u) {
            $w->startElement('Upload');
            $w->writeElement('Key', $u['key']);
            $w->writeElement('UploadId', $u['upload_id']);
            $w->writeElement('Initiated', $u['initiated']);
            $w->endElement();
        }
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Versioning
    // -------------------------------------------------------------------------

    public function getBucketVersioning(string $status): string {
        // status: '' (never set), 'Enabled', 'Suspended'
        $w = $this->writer();
        $w->startElementNS(null, 'VersioningConfiguration', self::NS);
        if ($status !== '') {
            $w->writeElement('Status', $status);
        }
        $w->endElement();
        return $this->flush($w);
    }

    /**
     * @param list<array{key: string, version_id: string, is_latest: bool, last_modified: string, etag: string, size: int}> $versions
     */
    public function listObjectVersions(string $bucket, array $versions, bool $isTruncated): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ListVersionsResult', self::NS);
        $w->writeElement('Name', $bucket);
        $w->writeElement('IsTruncated', $isTruncated ? 'true' : 'false');
        foreach ($versions as $v) {
            $w->startElement('Version');
            $w->writeElement('Key', $v['key']);
            $w->writeElement('VersionId', $v['version_id']);
            $w->writeElement('IsLatest', $v['is_latest'] ? 'true' : 'false');
            $w->writeElement('LastModified', $v['last_modified']);
            $w->writeElement('ETag', $v['etag']);
            $w->writeElement('Size', (string) $v['size']);
            $w->endElement();
        }
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Tagging
    // -------------------------------------------------------------------------

    /** @param list<array{key: string, value: string}> $tags */
    public function tagging(array $tags): string {
        $w = $this->writer();
        $w->startElementNS(null, 'Tagging', self::NS);
        $w->startElement('TagSet');
        foreach ($tags as $t) {
            $w->startElement('Tag');
            $w->writeElement('Key', $t['key']);
            $w->writeElement('Value', $t['value']);
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // ACL
    // -------------------------------------------------------------------------

    /**
     * @param array{id: string, display_name: string} $owner
     * @param list<array{grantee: array, permission: string}> $grants
     */
    public function acl(array $owner, array $grants): string {
        $w = $this->writer();
        $w->startElementNS(null, 'AccessControlPolicy', self::NS);

        $w->startElement('Owner');
        $w->writeElement('ID', $owner['id']);
        $w->writeElement('DisplayName', $owner['display_name']);
        $w->endElement();

        $w->startElement('AccessControlList');
        foreach ($grants as $grant) {
            $w->startElement('Grant');
            $w->startElement('Grantee');
            $w->writeAttributeNS('xsi', 'type', 'http://www.w3.org/2001/XMLSchema-instance', $grant['grantee']['type'] ?? 'CanonicalUser');
            $w->writeElement('ID', $grant['grantee']['id'] ?? '');
            $w->writeElement('DisplayName', $grant['grantee']['display_name'] ?? '');
            $w->endElement(); // Grantee
            $w->writeElement('Permission', $grant['permission']);
            $w->endElement(); // Grant
        }
        $w->endElement(); // AccessControlList

        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Error
    // -------------------------------------------------------------------------

    public function error(string $code, string $message, string $resource, string $requestId): string {
        $w = $this->writer();
        $w->startElement('Error');
        $w->writeElement('Code', $code);
        $w->writeElement('Message', $message);
        $w->writeElement('Resource', $resource);
        $w->writeElement('RequestId', $requestId);
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // DeleteObjects result
    // -------------------------------------------------------------------------

    /**
     * @param list<array{key: string, version_id?: string}> $deleted
     * @param list<array{key: string, code: string, message: string}> $errors
     */
    public function deleteObjectsResult(array $deleted, array $errors): string {
        $w = $this->writer();
        $w->startElementNS(null, 'DeleteResult', self::NS);
        foreach ($deleted as $d) {
            $w->startElement('Deleted');
            $w->writeElement('Key', $d['key']);
            if (isset($d['version_id'])) {
                $w->writeElement('VersionId', $d['version_id']);
            }
            $w->endElement();
        }
        foreach ($errors as $e) {
            $w->startElement('Error');
            $w->writeElement('Key', $e['key']);
            $w->writeElement('Code', $e['code']);
            $w->writeElement('Message', $e['message']);
            $w->endElement();
        }
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Location
    // -------------------------------------------------------------------------

    public function getBucketLocation(string $locationConstraint): string {
        $w = $this->writer();
        $w->startElementNS(null, 'LocationConstraint', self::NS);
        $w->text($locationConstraint);
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // CORS
    // -------------------------------------------------------------------------

    /**
     * @param list<array{allowed_origins: list<string>, allowed_methods: list<string>, allowed_headers?: list<string>, expose_headers?: list<string>, max_age_seconds?: int}> $rules
     */
    public function corsConfiguration(array $rules): string {
        $w = $this->writer();
        $w->startElementNS(null, 'CORSConfiguration', self::NS);
        foreach ($rules as $rule) {
            $w->startElement('CORSRule');
            foreach ($rule['allowed_origins'] as $origin) {
                $w->writeElement('AllowedOrigin', $origin);
            }
            foreach ($rule['allowed_methods'] as $method) {
                $w->writeElement('AllowedMethod', $method);
            }
            foreach ($rule['allowed_headers'] ?? [] as $header) {
                $w->writeElement('AllowedHeader', $header);
            }
            foreach ($rule['expose_headers'] ?? [] as $header) {
                $w->writeElement('ExposeHeader', $header);
            }
            if (isset($rule['max_age_seconds'])) {
                $w->writeElement('MaxAgeSeconds', (string) $rule['max_age_seconds']);
            }
            $w->endElement();
        }
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Encryption
    // -------------------------------------------------------------------------

    public function serverSideEncryptionConfiguration(string $algorithm = 'AES256'): string {
        $w = $this->writer();
        $w->startElementNS(null, 'ServerSideEncryptionConfiguration', self::NS);
        $w->startElement('Rule');
        $w->startElement('ApplyServerSideEncryptionByDefault');
        $w->writeElement('SSEAlgorithm', $algorithm);
        $w->endElement();
        $w->endElement();
        $w->endElement();
        return $this->flush($w);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writer(): \XMLWriter {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(false);
        $w->startDocument('1.0', 'UTF-8');
        return $w;
    }

    private function flush(\XMLWriter $w): string {
        $w->endDocument();
        return $w->outputMemory();
    }

    /** @param array{key: string, last_modified: string, etag: string, size: int, storage_class: string, owner?: array} $obj */
    private function writeObject(\XMLWriter $w, array $obj): void {
        $w->startElement('Contents');
        $w->writeElement('Key', $obj['key']);
        $w->writeElement('LastModified', $obj['last_modified']);
        $w->writeElement('ETag', $obj['etag']);
        $w->writeElement('Size', (string) $obj['size']);
        $w->writeElement('StorageClass', $obj['storage_class'] ?? 'STANDARD');
        if (isset($obj['owner'])) {
            $w->startElement('Owner');
            $w->writeElement('ID', $obj['owner']['id'] ?? '');
            $w->writeElement('DisplayName', $obj['owner']['display_name'] ?? '');
            $w->endElement();
        }
        $w->endElement();
    }
}
