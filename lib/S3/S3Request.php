<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\S3;

/**
 * Normalised representation of an incoming S3 request.
 *
 * Built once in S3Controller::dispatch() and passed unchanged through the
 * entire handler stack so no component needs to touch the raw HTTP request.
 */
final class S3Request {
    /**
     * @param string       $method      Upper-case HTTP verb (GET, PUT, POST, DELETE, HEAD)
     * @param string|null  $bucket      Bucket name parsed from the URL, or null for ListBuckets
     * @param string|null  $key         Object key (may contain slashes), or null for bucket-level ops
     * @param array<string,string> $queryParams  All query parameters (already decoded)
     * @param array<string,string> $headers      Request headers, normalised to lower-case keys
     * @param resource|string $bodyStream  Raw body as a stream resource (or string for small bodies)
     * @param string       $rawPath     The raw path component as seen by the router (e.g. "/s3/b/k")
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $bucket,
        public readonly ?string $key,
        public readonly array $queryParams,
        public readonly array $headers,
        public readonly mixed $bodyStream,
        public readonly string $rawPath,
        public readonly string $host,
    ) {}

    /** Returns true when the given query parameter is present (value may be empty). */
    public function hasQuery(string $name): bool {
        return array_key_exists($name, $this->queryParams);
    }

    public function getQuery(string $name, string $default = ''): string {
        return $this->queryParams[$name] ?? $default;
    }

    public function getHeader(string $name): string {
        return $this->headers[strtolower($name)] ?? '';
    }
}
