<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Xml;

use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Xml\XmlReader;
use PHPUnit\Framework\TestCase;

class XmlReaderTest extends TestCase {
    private XmlReader $reader;

    protected function setUp(): void {
        $this->reader = new XmlReader();
    }

    // -------------------------------------------------------------------------
    // completeMultipartUpload
    // -------------------------------------------------------------------------

    public function testCompleteMultipartUploadParsesOnePart(): void {
        $xml = <<<'XML'
            <CompleteMultipartUpload>
                <Part><PartNumber>1</PartNumber><ETag>"abc123"</ETag></Part>
            </CompleteMultipartUpload>
            XML;
        $parts = $this->reader->completeMultipartUpload($xml);
        $this->assertCount(1, $parts);
        $this->assertSame(1, $parts[0]['part_number']);
        $this->assertSame('"abc123"', $parts[0]['etag']);
    }

    public function testCompleteMultipartUploadParsesMultipleParts(): void {
        $xml = <<<'XML'
            <CompleteMultipartUpload>
                <Part><PartNumber>1</PartNumber><ETag>"p1"</ETag></Part>
                <Part><PartNumber>2</PartNumber><ETag>"p2"</ETag></Part>
                <Part><PartNumber>3</PartNumber><ETag>"p3"</ETag></Part>
            </CompleteMultipartUpload>
            XML;
        $parts = $this->reader->completeMultipartUpload($xml);
        $this->assertCount(3, $parts);
        $this->assertSame(3, $parts[2]['part_number']);
        $this->assertSame('"p3"', $parts[2]['etag']);
    }

    public function testCompleteMultipartUploadStripsWhitespaceFromEtag(): void {
        $xml = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>  "etag"  </ETag></Part></CompleteMultipartUpload>';
        $parts = $this->reader->completeMultipartUpload($xml);
        $this->assertSame('"etag"', $parts[0]['etag']);
    }

    public function testCompleteMultipartUploadEmptyBodyThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->completeMultipartUpload('');
    }

    public function testCompleteMultipartUploadNoPartsThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->completeMultipartUpload('<CompleteMultipartUpload></CompleteMultipartUpload>');
    }

    public function testCompleteMultipartUploadInvalidXmlThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->completeMultipartUpload('<not valid xml');
    }

    public function testCompleteMultipartUploadPartNumberZeroThrows(): void {
        $this->expectException(S3Exception::class);
        $xml = '<CompleteMultipartUpload><Part><PartNumber>0</PartNumber><ETag>"abc"</ETag></Part></CompleteMultipartUpload>';
        $this->reader->completeMultipartUpload($xml);
    }

    public function testCompleteMultipartUploadEmptyEtagThrows(): void {
        $this->expectException(S3Exception::class);
        $xml = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag></ETag></Part></CompleteMultipartUpload>';
        $this->reader->completeMultipartUpload($xml);
    }

    // -------------------------------------------------------------------------
    // tagging
    // -------------------------------------------------------------------------

    public function testTaggingParsesTagSet(): void {
        $xml = <<<'XML'
            <Tagging>
                <TagSet>
                    <Tag><Key>env</Key><Value>prod</Value></Tag>
                    <Tag><Key>team</Key><Value>ops</Value></Tag>
                </TagSet>
            </Tagging>
            XML;
        $tags = $this->reader->tagging($xml);
        $this->assertCount(2, $tags);
        $this->assertSame('env',  $tags[0]['key']);
        $this->assertSame('prod', $tags[0]['value']);
        $this->assertSame('team', $tags[1]['key']);
    }

    public function testTaggingEmptyTagSet(): void {
        $xml = '<Tagging><TagSet></TagSet></Tagging>';
        $this->assertSame([], $this->reader->tagging($xml));
    }

    public function testTaggingEmptyKeyThrows(): void {
        $this->expectException(S3Exception::class);
        $xml = '<Tagging><TagSet><Tag><Key></Key><Value>v</Value></Tag></TagSet></Tagging>';
        $this->reader->tagging($xml);
    }

    public function testTaggingValueCanBeEmpty(): void {
        $xml = '<Tagging><TagSet><Tag><Key>k</Key><Value></Value></Tag></TagSet></Tagging>';
        $tags = $this->reader->tagging($xml);
        $this->assertSame('', $tags[0]['value']);
    }

    public function testTaggingInvalidXmlThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->tagging('not xml');
    }

    // -------------------------------------------------------------------------
    // corsConfiguration
    // -------------------------------------------------------------------------

    public function testCorsConfigurationParsesFullRule(): void {
        $xml = <<<'XML'
            <CORSConfiguration>
                <CORSRule>
                    <AllowedOrigin>https://app.example.com</AllowedOrigin>
                    <AllowedOrigin>https://other.example.com</AllowedOrigin>
                    <AllowedMethod>GET</AllowedMethod>
                    <AllowedMethod>PUT</AllowedMethod>
                    <AllowedHeader>Authorization</AllowedHeader>
                    <ExposeHeader>ETag</ExposeHeader>
                    <MaxAgeSeconds>3600</MaxAgeSeconds>
                </CORSRule>
            </CORSConfiguration>
            XML;
        $rules = $this->reader->corsConfiguration($xml);
        $this->assertCount(1, $rules);
        $rule = $rules[0];
        $this->assertSame(['https://app.example.com', 'https://other.example.com'], $rule['allowed_origins']);
        $this->assertSame(['GET', 'PUT'], $rule['allowed_methods']);
        $this->assertSame(['Authorization'], $rule['allowed_headers']);
        $this->assertSame(['ETag'], $rule['expose_headers']);
        $this->assertSame(3600, $rule['max_age_seconds']);
    }

    public function testCorsConfigurationMinimalRule(): void {
        $xml = <<<'XML'
            <CORSConfiguration>
                <CORSRule>
                    <AllowedOrigin>*</AllowedOrigin>
                    <AllowedMethod>GET</AllowedMethod>
                </CORSRule>
            </CORSConfiguration>
            XML;
        $rules = $this->reader->corsConfiguration($xml);
        $this->assertNull($rules[0]['max_age_seconds']);
        $this->assertSame([], $rules[0]['allowed_headers']);
        $this->assertSame([], $rules[0]['expose_headers']);
    }

    public function testCorsConfigurationMultipleRules(): void {
        $xml = <<<'XML'
            <CORSConfiguration>
                <CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod></CORSRule>
                <CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>PUT</AllowedMethod></CORSRule>
            </CORSConfiguration>
            XML;
        $this->assertCount(2, $this->reader->corsConfiguration($xml));
    }

    public function testCorsConfigurationEmptyBody(): void {
        $this->expectException(S3Exception::class);
        $this->reader->corsConfiguration('');
    }

    // -------------------------------------------------------------------------
    // versioningConfiguration
    // -------------------------------------------------------------------------

    public function testVersioningConfigurationEnabled(): void {
        $xml = '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>';
        $this->assertSame('Enabled', $this->reader->versioningConfiguration($xml));
    }

    public function testVersioningConfigurationSuspended(): void {
        $xml = '<VersioningConfiguration><Status>Suspended</Status></VersioningConfiguration>';
        $this->assertSame('Suspended', $this->reader->versioningConfiguration($xml));
    }

    public function testVersioningConfigurationNoStatus(): void {
        $xml = '<VersioningConfiguration></VersioningConfiguration>';
        $this->assertSame('', $this->reader->versioningConfiguration($xml));
    }

    public function testVersioningConfigurationInvalidXmlThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->versioningConfiguration('<broken');
    }

    // -------------------------------------------------------------------------
    // deleteObjects
    // -------------------------------------------------------------------------

    public function testDeleteObjectsParsesObjects(): void {
        $xml = <<<'XML'
            <Delete>
                <Object><Key>file-a.txt</Key></Object>
                <Object><Key>dir/file-b.txt</Key><VersionId>v1</VersionId></Object>
            </Delete>
            XML;
        $result = $this->reader->deleteObjects($xml);
        $this->assertFalse($result['quiet']);
        $this->assertCount(2, $result['objects']);
        $this->assertSame('file-a.txt', $result['objects'][0]['key']);
        $this->assertNull($result['objects'][0]['version_id']);
        $this->assertSame('dir/file-b.txt', $result['objects'][1]['key']);
        $this->assertSame('v1', $result['objects'][1]['version_id']);
    }

    public function testDeleteObjectsQuietTrue(): void {
        $xml = '<Delete><Quiet>true</Quiet><Object><Key>a.txt</Key></Object></Delete>';
        $result = $this->reader->deleteObjects($xml);
        $this->assertTrue($result['quiet']);
    }

    public function testDeleteObjectsQuietFalse(): void {
        $xml = '<Delete><Quiet>false</Quiet><Object><Key>a.txt</Key></Object></Delete>';
        $this->assertFalse($this->reader->deleteObjects($xml)['quiet']);
    }

    public function testDeleteObjectsDefaultQuietIsFalse(): void {
        $xml = '<Delete><Object><Key>a.txt</Key></Object></Delete>';
        $this->assertFalse($this->reader->deleteObjects($xml)['quiet']);
    }

    public function testDeleteObjectsEmptyListReturnsEmptyArray(): void {
        $xml = '<Delete></Delete>';
        $result = $this->reader->deleteObjects($xml);
        $this->assertSame([], $result['objects']);
    }

    public function testDeleteObjectsInvalidXmlThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->deleteObjects('<broken xml');
    }

    public function testDeleteObjectsEmptyBodyThrows(): void {
        $this->expectException(S3Exception::class);
        $this->reader->deleteObjects('');
    }
}
