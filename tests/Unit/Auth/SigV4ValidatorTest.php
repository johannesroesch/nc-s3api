<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Auth;

use OCA\NcS3Api\Auth\SigV4Parser;
use OCA\NcS3Api\Auth\SigV4Validator;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Exception\SignatureDoesNotMatchException;
use OCA\NcS3Api\S3\S3Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests AWS Signature Version 4 computation.
 *
 * Official AWS test vectors:
 * https://docs.aws.amazon.com/general/latest/gr/signature-v4-test-suite.html
 */
class SigV4ValidatorTest extends TestCase {
    private SigV4Validator $validator;

    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const DATE       = '20150830';
    private const REGION     = 'us-east-1';
    private const SERVICE    = 'iam';

    protected function setUp(): void {
        $this->validator = new SigV4Validator();
    }

    // -------------------------------------------------------------------------
    // Signing key derivation
    // -------------------------------------------------------------------------

    public function testSigningKeyDerivation(): void {
        $key = $this->validator->signingKey(self::SECRET_KEY, self::DATE, self::REGION, self::SERVICE);
        $this->assertSame('c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex($key));
    }

    public function testSigningKeyDifferentRegionProducesDifferentKey(): void {
        $keyA = $this->validator->signingKey(self::SECRET_KEY, self::DATE, 'us-east-1', self::SERVICE);
        $keyB = $this->validator->signingKey(self::SECRET_KEY, self::DATE, 'eu-west-1', self::SERVICE);
        $this->assertNotSame(bin2hex($keyA), bin2hex($keyB));
    }

    public function testSigningKeyDifferentDateProducesDifferentKey(): void {
        $keyA = $this->validator->signingKey(self::SECRET_KEY, '20150830', self::REGION, self::SERVICE);
        $keyB = $this->validator->signingKey(self::SECRET_KEY, '20150831', self::REGION, self::SERVICE);
        $this->assertNotSame(bin2hex($keyA), bin2hex($keyB));
    }

    // -------------------------------------------------------------------------
    // Canonical URI
    // -------------------------------------------------------------------------

    public function testCanonicalUriRoot(): void {
        $this->assertSame('/', $this->validator->canonicalUri('/'));
    }

    public function testCanonicalUriSimplePath(): void {
        $this->assertSame('/doc/2006-03-01', $this->validator->canonicalUri('/doc/2006-03-01'));
    }

    public function testCanonicalUriEncodesSpaces(): void {
        $this->assertSame('/my%20folder/file.txt', $this->validator->canonicalUri('/my folder/file.txt'));
    }

    public function testCanonicalUriDoesNotDoubleEncode(): void {
        // Already encoded input must stay encoded (no double encoding)
        $this->assertSame('/my%20folder/file.txt', $this->validator->canonicalUri('/my%20folder/file.txt'));
    }

    public function testCanonicalUriDeepPath(): void {
        $this->assertSame('/a/b/c/d.txt', $this->validator->canonicalUri('/a/b/c/d.txt'));
    }

    // -------------------------------------------------------------------------
    // Canonical Query String
    // -------------------------------------------------------------------------

    public function testCanonicalQueryStringEmpty(): void {
        $this->assertSame('', $this->validator->canonicalQueryString([]));
    }

    public function testCanonicalQueryStringSingleParam(): void {
        $this->assertSame('Action=ListUsers', $this->validator->canonicalQueryString(['Action' => 'ListUsers']));
    }

    public function testCanonicalQueryStringTwoParamsSorted(): void {
        $this->assertSame(
            'Action=ListUsers&Version=2010-05-08',
            $this->validator->canonicalQueryString(['Action' => 'ListUsers', 'Version' => '2010-05-08'])
        );
    }

    public function testCanonicalQueryStringIsSortedByKey(): void {
        $result = $this->validator->canonicalQueryString(['Z' => 'last', 'A' => 'first']);
        $this->assertSame('A=first&Z=last', $result);
    }

    public function testCanonicalQueryStringEncodesSpecialChars(): void {
        $result = $this->validator->canonicalQueryString(['key' => 'value with spaces & symbols=1']);
        $this->assertStringContainsString('value%20with%20spaces', $result);
    }

    public function testCanonicalQueryStringEmptyValue(): void {
        $this->assertSame('versioning=', $this->validator->canonicalQueryString(['versioning' => '']));
    }

    // -------------------------------------------------------------------------
    // Canonical Headers
    // -------------------------------------------------------------------------

    public function testCanonicalHeadersBasic(): void {
        $headers = [
            'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'host'         => 'iam.amazonaws.com',
            'x-amz-date'   => '20150830T123600Z',
        ];
        $signed  = ['content-type', 'host', 'x-amz-date'];
        $result  = $this->validator->canonicalHeaders($headers, $signed);

        $expected = "content-type:application/x-www-form-urlencoded; charset=utf-8\n"
                  . "host:iam.amazonaws.com\n"
                  . "x-amz-date:20150830T123600Z\n";
        $this->assertSame($expected, $result);
    }

    public function testCanonicalHeadersTrimsWhitespace(): void {
        $result = $this->validator->canonicalHeaders(['host' => '  example.com  '], ['host']);
        $this->assertSame("host:example.com\n", $result);
    }

    public function testCanonicalHeadersCollapsesInternalWhitespace(): void {
        $result = $this->validator->canonicalHeaders(['x-amz-meta' => 'value   with   spaces'], ['x-amz-meta']);
        $this->assertSame("x-amz-meta:value with spaces\n", $result);
    }

    public function testCanonicalHeadersMissingHeaderUsesEmptyString(): void {
        $result = $this->validator->canonicalHeaders([], ['host']);
        $this->assertSame("host:\n", $result);
    }

    // -------------------------------------------------------------------------
    // Body hash
    // -------------------------------------------------------------------------

    public function testBodyHashEmptyBody(): void {
        $request = $this->makeRequest('GET', headers: ['x-amz-content-sha256' => hash('sha256', '')]);
        $this->assertSame(hash('sha256', ''), $this->validator->bodyHash($request));
    }

    public function testBodyHashTrustsProvidedHexHash(): void {
        $hash    = str_repeat('a', 64); // valid 64-char hex string
        $request = $this->makeRequest('PUT', headers: ['x-amz-content-sha256' => $hash]);
        $this->assertSame($hash, $this->validator->bodyHash($request));
    }

    public function testBodyHashUnsignedPayloadPassThrough(): void {
        $request = $this->makeRequest('PUT', headers: ['x-amz-content-sha256' => 'UNSIGNED-PAYLOAD']);
        $this->assertSame('unsigned-payload', $this->validator->bodyHash($request));
    }

    public function testBodyHashComputesFromStringBody(): void {
        $body    = 'hello world';
        $request = $this->makeRequest('PUT', body: $body);
        $this->assertSame(hash('sha256', $body), $this->validator->bodyHash($request));
    }

    public function testBodyHashComputesFromStreamBody(): void {
        $body   = 'stream body content';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        $request = $this->makeRequest('PUT', body: $stream);
        $this->assertSame(hash('sha256', $body), $this->validator->bodyHash($request));
        fclose($stream);
    }

    public function testBodyHashStreamRewound(): void {
        // After bodyHash the stream must still be readable from current position
        $body   = 'rewind test';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        $request = $this->makeRequest('PUT', body: $stream);
        $this->validator->bodyHash($request);

        // Stream must still be readable
        $this->assertSame($body, stream_get_contents($stream));
        fclose($stream);
    }

    // -------------------------------------------------------------------------
    // Canonical Request
    // -------------------------------------------------------------------------

    public function testCanonicalRequestFromAwsDocs(): void {
        // Vector from https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
        $request = new S3Request(
            method:      'GET',
            bucket:      null,
            key:         null,
            queryParams: ['Action' => 'ListUsers', 'Version' => '2010-05-08'],
            headers:     [
                'content-type'         => 'application/x-www-form-urlencoded; charset=utf-8',
                'host'                 => 'iam.amazonaws.com',
                'x-amz-date'           => '20150830T123600Z',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            ],
            bodyStream:  '',
            rawPath:     '/',
            host:        'iam.amazonaws.com',
        );
        $signedHeaders = ['content-type', 'host', 'x-amz-date'];
        $bodyHash      = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $canonical = $this->validator->canonicalRequest($request, $signedHeaders, $bodyHash);

        $expected = implode("\n", [
            'GET',
            '/',
            'Action=ListUsers&Version=2010-05-08',
            "content-type:application/x-www-form-urlencoded; charset=utf-8\nhost:iam.amazonaws.com\nx-amz-date:20150830T123600Z\n",
            '',
            'content-type;host;x-amz-date',
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ]);
        $this->assertSame($expected, $canonical);
    }

    // -------------------------------------------------------------------------
    // validateHeader — full round-trip
    // -------------------------------------------------------------------------

    public function testValidateHeaderSuccess(): void {
        [$request, $parsed] = $this->buildSignedRequest(self::SECRET_KEY);
        $this->validator->validateHeader($request, $parsed, self::SECRET_KEY);
        $this->addToAssertionCount(1);
    }

    public function testValidateHeaderFailsWrongSecret(): void {
        $this->expectException(SignatureDoesNotMatchException::class);
        [$request, $parsed] = $this->buildSignedRequest(self::SECRET_KEY);
        $this->validator->validateHeader($request, $parsed, 'wrong-secret');
    }

    public function testValidateHeaderFailsTamperedSignature(): void {
        $this->expectException(SignatureDoesNotMatchException::class);
        [$request, $parsed] = $this->buildSignedRequest(self::SECRET_KEY);
        // Build a new parser with a deliberately wrong signature (one digit changed)
        $wrongHeader = 'AWS4-HMAC-SHA256 Credential=' . self::ACCESS_KEY . '/' . self::DATE . '/us-east-1/iam/aws4_request,'
                     . 'SignedHeaders=content-type;host;x-amz-date,'
                     . 'Signature=0000000000000000000000000000000000000000000000000000000000000000';
        $tampered = SigV4Parser::fromHeader($wrongHeader);
        $this->validator->validateHeader($request, $tampered, self::SECRET_KEY);
    }

    public function testValidateHeaderMissingDateThrows(): void {
        $this->expectException(SignatureDoesNotMatchException::class);
        $request = new S3Request('GET', null, null, [], ['host' => 'iam.amazonaws.com'], '', '/', 'iam.amazonaws.com');
        $parsed  = SigV4Parser::fromHeader(
            'AWS4-HMAC-SHA256 Credential=AKID/20150830/us-east-1/s3/aws4_request,SignedHeaders=host,Signature=deadbeef'
        );
        $this->validator->validateHeader($request, $parsed, self::SECRET_KEY);
    }

    // -------------------------------------------------------------------------
    // validatePresigned
    // -------------------------------------------------------------------------

    public function testValidatePresignedSuccess(): void {
        $date      = '20150830';
        $timestamp = '20150830T120000Z';
        $ttl       = 3600;
        $expires   = (int) (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->getTimestamp() + $ttl;

        // Build the query params without the signature first (to compute canonical request)
        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => self::ACCESS_KEY . "/{$date}/us-east-1/s3/aws4_request",
            'X-Amz-Date'          => $timestamp,
            'X-Amz-Expires'       => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $headers = ['host' => 'nc.example.com'];

        $request = new S3Request('GET', 'bucket', 'key', $queryParams, $headers, '', '/s3/bucket/key', 'nc.example.com');

        // Compute the correct signature
        $canonical  = $this->validator->canonicalRequestPresigned($request, ['host']);
        $credScope  = "{$date}/us-east-1/s3/aws4_request";
        $sts        = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credScope}\n" . hash('sha256', $canonical);
        $signingKey = $this->validator->signingKey(self::SECRET_KEY, $date, 'us-east-1', 's3');
        $sig        = bin2hex(hash_hmac('sha256', $sts, $signingKey, true));

        // Add signature to query params and parse (no Reflection needed)
        $queryParams['X-Amz-Signature'] = $sig;
        $parsed = SigV4Parser::fromQueryParams($queryParams);

        // Must not throw (expiry far in the future)
        $this->validator->validatePresigned($request, $parsed, self::SECRET_KEY, (string) (time() + 9999));
        $this->addToAssertionCount(1);
    }

    public function testValidatePresignedExpiredThrows(): void {
        $this->expectException(S3Exception::class);
        $request = $this->makeRequest('GET');
        $parsed  = SigV4Parser::fromHeader(
            'AWS4-HMAC-SHA256 Credential=AKID/20150830/us-east-1/s3/aws4_request,SignedHeaders=host,Signature=aaa'
        );
        // expiresAt in the past
        $this->validator->validatePresigned($request, $parsed, self::SECRET_KEY, '1');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a correctly signed request + parsed header pair.
     * @return array{S3Request, SigV4Parser}
     */
    private function buildSignedRequest(string $secretKey): array {
        $date      = self::DATE;
        $timestamp = '20150830T123600Z';
        $body      = '';
        $bodyHash  = hash('sha256', $body);

        $headers = [
            'content-type'         => 'application/x-www-form-urlencoded; charset=utf-8',
            'host'                 => 'iam.amazonaws.com',
            'x-amz-date'           => $timestamp,
            'x-amz-content-sha256' => $bodyHash,
        ];
        $signedHeaders = ['content-type', 'host', 'x-amz-date'];
        $queryParams   = ['Action' => 'ListUsers', 'Version' => '2010-05-08'];

        $request = new S3Request('GET', null, null, $queryParams, $headers, $body, '/', 'iam.amazonaws.com');

        $canonical       = $this->validator->canonicalRequest($request, $signedHeaders, $bodyHash);
        $credentialScope = "{$date}/us-east-1/iam/aws4_request";
        $stringToSign    = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonical);
        $signingKey      = $this->validator->signingKey($secretKey, $date, 'us-east-1', 'iam');
        $signature       = bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true));

        $authHeader = 'AWS4-HMAC-SHA256 Credential=' . self::ACCESS_KEY . "/{$date}/us-east-1/iam/aws4_request,"
                    . 'SignedHeaders=' . implode(';', $signedHeaders) . ','
                    . "Signature={$signature}";

        return [$request, SigV4Parser::fromHeader($authHeader)];
    }

    private function makeRequest(
        string $method  = 'GET',
        array  $headers = [],
        mixed  $body    = '',
    ): S3Request {
        return new S3Request($method, null, null, [], $headers, $body, '/', 'localhost');
    }
}
