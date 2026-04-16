<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Auth;

use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;

/**
 * Parses the AWS Signature Version 4 Authorization header.
 *
 * Header format:
 *   AWS4-HMAC-SHA256
 *   Credential=AKID/YYYYMMDD/region/service/aws4_request,
 *   SignedHeaders=host;x-amz-content-sha256;x-amz-date,
 *   Signature=abcdef...
 */
final class SigV4Parser {
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * Parsed representation of a Sig V4 Authorization header.
     */
    public readonly string $accessKey;
    public readonly string $date;       // YYYYMMDD
    public readonly string $region;
    public readonly string $service;
    /** @var list<string> Sorted, lower-cased signed header names */
    public readonly array  $signedHeaders;
    public readonly string $signature;

    private function __construct(
        string $accessKey,
        string $date,
        string $region,
        string $service,
        array  $signedHeaders,
        string $signature,
    ) {
        $this->accessKey     = $accessKey;
        $this->date          = $date;
        $this->region        = $region;
        $this->service       = $service;
        $this->signedHeaders = $signedHeaders;
        $this->signature     = $signature;
    }

    /**
     * Parse an Authorization header value.
     *
     * @throws S3Exception on malformed header
     */
    public static function fromHeader(string $header): self {
        $header = trim($header);

        if (!str_starts_with($header, self::ALGORITHM . ' ')) {
            throw new S3Exception(
                S3ErrorCodes::INVALID_SECURITY,
                'Unsupported Authorization algorithm. Only AWS4-HMAC-SHA256 is supported.',
            );
        }

        $payload = substr($header, strlen(self::ALGORITHM) + 1);
        $parts   = [];
        foreach (explode(',', $payload) as $part) {
            [$k, $v]       = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        // Parse Credential
        $credential = $parts['Credential'] ?? '';
        $credParts  = explode('/', $credential);
        if (count($credParts) < 5) {
            throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Invalid Credential in Authorization header');
        }

        [$accessKey, $date, $region, $service] = $credParts;

        $signedHeaders = array_filter(array_map('trim', explode(';', $parts['SignedHeaders'] ?? '')));
        $signature     = $parts['Signature'] ?? '';

        if ($accessKey === '' || $date === '' || $signature === '') {
            throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Missing required Authorization fields');
        }

        return new self(
            accessKey:     $accessKey,
            date:          $date,
            region:        $region,
            service:       $service,
            signedHeaders: array_values($signedHeaders),
            signature:     $signature,
        );
    }

    /**
     * Parse presigned URL query parameters into the same VO.
     *
     * @param array<string,string> $queryParams
     * @throws S3Exception
     */
    public static function fromQueryParams(array $queryParams): self {
        $algorithm = $queryParams['X-Amz-Algorithm'] ?? '';
        if ($algorithm !== self::ALGORITHM) {
            throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Unsupported algorithm in presigned URL');
        }

        $credential = $queryParams['X-Amz-Credential'] ?? '';
        $credParts  = explode('/', $credential);
        if (count($credParts) < 5) {
            throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Invalid Credential in presigned URL');
        }
        [$accessKey, $date, $region, $service] = $credParts;

        $signedHeaders = array_filter(array_map('trim', explode(';', $queryParams['X-Amz-SignedHeaders'] ?? '')));
        $signature     = $queryParams['X-Amz-Signature'] ?? '';

        if ($accessKey === '' || $date === '' || $signature === '') {
            throw new S3Exception(S3ErrorCodes::INVALID_SECURITY, 'Missing required presigned URL parameters');
        }

        return new self(
            accessKey:     $accessKey,
            date:          $date,
            region:        $region,
            service:       $service,
            signedHeaders: array_values($signedHeaders),
            signature:     $signature,
        );
    }
}
