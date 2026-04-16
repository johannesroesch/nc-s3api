<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Auth;

use OCA\NcS3Api\Auth\SigV4Parser;
use OCA\NcS3Api\Exception\S3Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SigV4ParserTest extends TestCase {
    // -------------------------------------------------------------------------
    // fromHeader — happy path
    // -------------------------------------------------------------------------

    public function testFromHeaderParsesAllFields(): void {
        $header = 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request,'
                . 'SignedHeaders=content-type;host;x-amz-date,'
                . 'Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7';

        $p = SigV4Parser::fromHeader($header);

        $this->assertSame('AKIDEXAMPLE',  $p->accessKey);
        $this->assertSame('20150830',     $p->date);
        $this->assertSame('us-east-1',    $p->region);
        $this->assertSame('iam',          $p->service);
        $this->assertSame(['content-type', 'host', 'x-amz-date'], $p->signedHeaders);
        $this->assertSame('5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7', $p->signature);
    }

    public function testFromHeaderSingleSignedHeader(): void {
        $header = 'AWS4-HMAC-SHA256 Credential=AKID/20240101/eu-central-1/s3/aws4_request,'
                . 'SignedHeaders=host,'
                . 'Signature=abc';
        $p = SigV4Parser::fromHeader($header);
        $this->assertSame(['host'], $p->signedHeaders);
    }

    public function testFromHeaderStripsWhitespace(): void {
        // Extra spaces around commas and equals signs
        $header = 'AWS4-HMAC-SHA256 Credential= AKID/20240101/us-east-1/s3/aws4_request ,'
                . ' SignedHeaders= host ,'
                . ' Signature= deadbeef ';
        $p = SigV4Parser::fromHeader($header);
        $this->assertSame('AKID', $p->accessKey);
        $this->assertSame('deadbeef', $p->signature);
    }

    // -------------------------------------------------------------------------
    // fromHeader — error cases
    // -------------------------------------------------------------------------

    public function testFromHeaderWrongAlgorithmThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromHeader('AWS4-HMAC-SHA512 Credential=X/d/r/s/aws4_request,SignedHeaders=host,Signature=sig');
    }

    public function testFromHeaderMissingAlgorithmThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromHeader('Bearer some-token');
    }

    public function testFromHeaderShortCredentialThrows(): void {
        $this->expectException(S3Exception::class);
        // Only 3 credential parts instead of 5
        SigV4Parser::fromHeader('AWS4-HMAC-SHA256 Credential=AKID/20150830/us-east-1,SignedHeaders=host,Signature=sig');
    }

    public function testFromHeaderMissingSignatureThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromHeader('AWS4-HMAC-SHA256 Credential=AKID/20150830/us-east-1/s3/aws4_request,SignedHeaders=host,Signature=');
    }

    // -------------------------------------------------------------------------
    // fromQueryParams — happy path
    // -------------------------------------------------------------------------

    public function testFromQueryParamsParsesAllFields(): void {
        $q = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => 'AKIDEXAMPLE/20150830/us-east-1/s3/aws4_request',
            'X-Amz-Date'          => '20150830T120000Z',
            'X-Amz-Expires'       => '3600',
            'X-Amz-SignedHeaders'  => 'host',
            'X-Amz-Signature'     => 'abcdef',
        ];
        $p = SigV4Parser::fromQueryParams($q);

        $this->assertSame('AKIDEXAMPLE', $p->accessKey);
        $this->assertSame('20150830',    $p->date);
        $this->assertSame('us-east-1',   $p->region);
        $this->assertSame('s3',          $p->service);
        $this->assertSame(['host'],       $p->signedHeaders);
        $this->assertSame('abcdef',       $p->signature);
    }

    public function testFromQueryParamsMultipleSignedHeaders(): void {
        $q = [
            'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'   => 'AKID/20150830/us-east-1/s3/aws4_request',
            'X-Amz-Date'         => '20150830T120000Z',
            'X-Amz-Expires'      => '3600',
            'X-Amz-SignedHeaders' => 'host;x-amz-date',
            'X-Amz-Signature'    => 'sig',
        ];
        $p = SigV4Parser::fromQueryParams($q);
        $this->assertSame(['host', 'x-amz-date'], $p->signedHeaders);
    }

    // -------------------------------------------------------------------------
    // fromQueryParams — error cases
    // -------------------------------------------------------------------------

    public function testFromQueryParamsWrongAlgorithmThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromQueryParams([
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA512',
            'X-Amz-Credential' => 'AKID/20150830/us-east-1/s3/aws4_request',
            'X-Amz-SignedHeaders' => 'host',
            'X-Amz-Signature'  => 'sig',
        ]);
    }

    public function testFromQueryParamsShortCredentialThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromQueryParams([
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => 'AKID/20150830',
            'X-Amz-SignedHeaders' => 'host',
            'X-Amz-Signature'  => 'sig',
        ]);
    }

    public function testFromQueryParamsMissingSignatureThrows(): void {
        $this->expectException(S3Exception::class);
        SigV4Parser::fromQueryParams([
            'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'   => 'AKID/20150830/us-east-1/s3/aws4_request',
            'X-Amz-SignedHeaders' => 'host',
            'X-Amz-Signature'    => '',
        ]);
    }
}
