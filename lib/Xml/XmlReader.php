<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Xml;

use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;

/**
 * Parses S3 XML request bodies into PHP arrays.
 *
 * Used for:
 * - CompleteMultipartUpload body (list of parts + ETags)
 * - PutBucketTagging / PutObjectTagging
 * - PutBucketCors
 * - PutBucketVersioning
 * - DeleteObjects
 */
final class XmlReader {
    /**
     * Parse the CompleteMultipartUpload request body.
     *
     * Expected XML:
     * <CompleteMultipartUpload>
     *   <Part><PartNumber>1</PartNumber><ETag>"abc"</ETag></Part>
     *   ...
     * </CompleteMultipartUpload>
     *
     * @return list<array{part_number: int, etag: string}>
     * @throws S3Exception on invalid XML
     */
    public function completeMultipartUpload(string $xml): array {
        $sx = $this->parse($xml);
        $parts = [];
        foreach ($sx->Part as $part) {
            $number = (int)(string)$part->PartNumber;
            $etag   = trim((string)$part->ETag);
            if ($number < 1 || $number > 10000 || $etag === '') {
                throw new S3Exception(S3ErrorCodes::INVALID_PART, 'Invalid part in CompleteMultipartUpload body');
            }
            $parts[] = ['part_number' => $number, 'etag' => $etag];
        }
        if (empty($parts)) {
            throw new S3Exception(S3ErrorCodes::MALFORMED_XML, 'CompleteMultipartUpload must contain at least one Part');
        }
        return $parts;
    }

    /**
     * Parse a Tagging request body.
     *
     * Expected XML:
     * <Tagging><TagSet><Tag><Key>foo</Key><Value>bar</Value></Tag></TagSet></Tagging>
     *
     * @return list<array{key: string, value: string}>
     */
    public function tagging(string $xml): array {
        $sx   = $this->parse($xml);
        $tags = [];
        foreach ($sx->TagSet->Tag ?? [] as $tag) {
            $k = trim((string)$tag->Key);
            $v = trim((string)$tag->Value);
            if ($k === '') {
                throw new S3Exception(S3ErrorCodes::INVALID_ARGUMENT, 'Tag key must not be empty');
            }
            $tags[] = ['key' => $k, 'value' => $v];
        }
        return $tags;
    }

    /**
     * Parse a CORSConfiguration request body.
     *
     * @return list<array{allowed_origins: list<string>, allowed_methods: list<string>, allowed_headers: list<string>, expose_headers: list<string>, max_age_seconds: int|null}>
     */
    public function corsConfiguration(string $xml): array {
        $sx    = $this->parse($xml);
        $rules = [];
        foreach ($sx->CORSRule as $rule) {
            $rules[] = [
                'allowed_origins' => $this->textList($rule->AllowedOrigin),
                'allowed_methods' => $this->textList($rule->AllowedMethod),
                'allowed_headers' => $this->textList($rule->AllowedHeader ?? []),
                'expose_headers'  => $this->textList($rule->ExposeHeader ?? []),
                'max_age_seconds' => isset($rule->MaxAgeSeconds) ? (int)(string)$rule->MaxAgeSeconds : null,
            ];
        }
        return $rules;
    }

    /**
     * Parse a VersioningConfiguration body.
     * Returns 'Enabled', 'Suspended', or '' (if no Status element present).
     */
    public function versioningConfiguration(string $xml): string {
        $sx = $this->parse($xml);
        return trim((string)($sx->Status ?? ''));
    }

    /**
     * Parse a Delete (DeleteObjects) request body.
     *
     * @return array{quiet: bool, objects: list<array{key: string, version_id: string|null}>}
     */
    public function deleteObjects(string $xml): array {
        $sx      = $this->parse($xml);
        $quiet   = strtolower((string)($sx->Quiet ?? 'false')) === 'true';
        $objects = [];
        foreach ($sx->Object as $obj) {
            $objects[] = [
                'key'        => (string)$obj->Key,
                'version_id' => isset($obj->VersionId) ? (string)$obj->VersionId : null,
            ];
        }
        return ['quiet' => $quiet, 'objects' => $objects];
    }

    // -------------------------------------------------------------------------

    private function parse(string $xml): \SimpleXMLElement {
        if (trim($xml) === '') {
            throw new S3Exception(S3ErrorCodes::MISSING_REQUEST_BODY_ERROR, 'Request body is empty');
        }
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        if ($sx === false) {
            $error = libxml_get_last_error();
            throw new S3Exception(S3ErrorCodes::MALFORMED_XML, $error ? $error->message : 'Invalid XML');
        }
        return $sx;
    }

    /** @return list<string> */
    private function textList(mixed $elements): array {
        $result = [];
        foreach ($elements as $e) {
            $result[] = (string)$e;
        }
        return $result;
    }
}
