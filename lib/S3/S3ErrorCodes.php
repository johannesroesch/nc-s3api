<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\S3;

/**
 * S3 error code constants and their default HTTP status codes.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/ErrorResponses.html
 */
final class S3ErrorCodes {
    // 400 Bad Request
    public const BAD_DIGEST                = 'BadDigest';
    public const ENTITY_TOO_SMALL          = 'EntityTooSmall';
    public const ENTITY_TOO_LARGE          = 'EntityTooLarge';
    public const INCOMPLETE_BODY           = 'IncompleteBody';
    public const INVALID_ARGUMENT          = 'InvalidArgument';
    public const INVALID_BUCKET_NAME       = 'InvalidBucketName';
    public const INVALID_PART             = 'InvalidPart';
    public const INVALID_PART_ORDER        = 'InvalidPartOrder';
    public const INVALID_REQUEST           = 'InvalidRequest';
    public const INVALID_URI               = 'InvalidURI';
    public const KEY_TOO_LONG_ERROR        = 'KeyTooLongError';
    public const MALFORMED_XML            = 'MalformedXML';
    public const MISSING_CONTENT_LENGTH    = 'MissingContentLength';
    public const MISSING_REQUEST_BODY_ERROR = 'MissingRequestBodyError';
    public const NO_SUCH_VERSION           = 'NoSuchVersion';
    public const REQUEST_TIMEOUT           = 'RequestTimeout';
    public const TOO_MANY_BUCKETS          = 'TooManyBuckets';
    public const TOO_MANY_TAGS             = 'InvalidTagError';

    // 403 Forbidden
    public const ACCESS_DENIED             = 'AccessDenied';
    public const INVALID_ACCESS_KEY_ID     = 'InvalidAccessKeyId';
    public const INVALID_SECURITY          = 'InvalidSecurity';
    public const SIGNATURE_DOES_NOT_MATCH  = 'SignatureDoesNotMatch';
    public const REQUEST_EXPIRED           = 'RequestExpired';

    // 404 Not Found
    public const NO_SUCH_BUCKET            = 'NoSuchBucket';
    public const NO_SUCH_KEY               = 'NoSuchKey';
    public const NO_SUCH_UPLOAD            = 'NoSuchUpload';

    // 405 Method Not Allowed
    public const METHOD_NOT_ALLOWED        = 'MethodNotAllowed';

    // 409 Conflict
    public const BUCKET_ALREADY_EXISTS     = 'BucketAlreadyExists';
    public const BUCKET_ALREADY_OWNED_BY_YOU = 'BucketAlreadyOwnedByYou';
    public const BUCKET_NOT_EMPTY          = 'BucketNotEmpty';

    // 411 Length Required
    public const MISSING_CONTENT_LENGTH_411 = 'MissingContentLength';

    // 412 Precondition Failed
    public const PRECONDITION_FAILED       = 'PreconditionFailed';

    // 416 Range Not Satisfiable
    public const INVALID_RANGE             = 'InvalidRange';

    // 500 Internal Server Error
    public const INTERNAL_ERROR            = 'InternalError';

    // 503 Slow Down
    public const SLOW_DOWN                 = 'SlowDown';

    /** Maps error code → default HTTP status code */
    private const STATUS_MAP = [
        self::ACCESS_DENIED             => 403,
        self::BUCKET_ALREADY_EXISTS     => 409,
        self::BUCKET_ALREADY_OWNED_BY_YOU => 409,
        self::BUCKET_NOT_EMPTY          => 409,
        self::ENTITY_TOO_LARGE          => 400,
        self::ENTITY_TOO_SMALL          => 400,
        self::INCOMPLETE_BODY           => 400,
        self::INTERNAL_ERROR            => 500,
        self::INVALID_ACCESS_KEY_ID     => 403,
        self::INVALID_ARGUMENT          => 400,
        self::INVALID_BUCKET_NAME       => 400,
        self::INVALID_PART             => 400,
        self::INVALID_PART_ORDER        => 400,
        self::INVALID_RANGE             => 416,
        self::INVALID_REQUEST           => 400,
        self::INVALID_SECURITY          => 403,
        self::KEY_TOO_LONG_ERROR        => 400,
        self::MALFORMED_XML            => 400,
        self::METHOD_NOT_ALLOWED        => 405,
        self::MISSING_CONTENT_LENGTH    => 411,
        self::NO_SUCH_BUCKET            => 404,
        self::NO_SUCH_KEY               => 404,
        self::NO_SUCH_UPLOAD            => 404,
        self::NO_SUCH_VERSION           => 404,
        self::PRECONDITION_FAILED       => 412,
        self::REQUEST_EXPIRED           => 403,
        self::REQUEST_TIMEOUT           => 400,
        self::SIGNATURE_DOES_NOT_MATCH  => 403,
        self::SLOW_DOWN                 => 503,
        self::TOO_MANY_BUCKETS          => 400,
        self::TOO_MANY_TAGS             => 400,
    ];

    public static function httpStatus(string $code): int {
        return self::STATUS_MAP[$code] ?? 400;
    }
}
