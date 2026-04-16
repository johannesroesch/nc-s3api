<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Tests\Unit\Dispatcher;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Dispatcher\OperationResolver;
use OCA\NcS3Api\Dispatcher\S3Dispatcher;
use OCA\NcS3Api\Exception\AccessDeniedException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\Handler\AclHandler;
use OCA\NcS3Api\Handler\BucketHandler;
use OCA\NcS3Api\Handler\CorsHandler;
use OCA\NcS3Api\Handler\EncryptionHandler;
use OCA\NcS3Api\Handler\ListingHandler;
use OCA\NcS3Api\Handler\MultipartHandler;
use OCA\NcS3Api\Handler\ObjectHandler;
use OCA\NcS3Api\Handler\TaggingHandler;
use OCA\NcS3Api\Handler\VersioningHandler;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Xml\XmlWriter;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class S3DispatcherTest extends TestCase {
	private OperationResolver&MockObject $resolver;
	private BucketHandler&MockObject $bucketHandler;
	private ObjectHandler&MockObject $objectHandler;
	private ListingHandler&MockObject $listingHandler;
	private MultipartHandler&MockObject $multipartHandler;
	private VersioningHandler&MockObject $versioningHandler;
	private TaggingHandler&MockObject $taggingHandler;
	private AclHandler&MockObject $aclHandler;
	private CorsHandler&MockObject $corsHandler;
	private EncryptionHandler&MockObject $encryptionHandler;
	private LoggerInterface&MockObject $logger;
	private S3Dispatcher $dispatcher;
	private AuthContext $auth;

	protected function setUp(): void {
		$this->resolver          = $this->createMock(OperationResolver::class);
		$this->bucketHandler     = $this->createMock(BucketHandler::class);
		$this->objectHandler     = $this->createMock(ObjectHandler::class);
		$this->listingHandler    = $this->createMock(ListingHandler::class);
		$this->multipartHandler  = $this->createMock(MultipartHandler::class);
		$this->versioningHandler = $this->createMock(VersioningHandler::class);
		$this->taggingHandler    = $this->createMock(TaggingHandler::class);
		$this->aclHandler        = $this->createMock(AclHandler::class);
		$this->corsHandler       = $this->createMock(CorsHandler::class);
		$this->encryptionHandler = $this->createMock(EncryptionHandler::class);
		$this->logger            = $this->createMock(LoggerInterface::class);

		$this->dispatcher = new S3Dispatcher(
			$this->resolver,
			$this->bucketHandler,
			$this->objectHandler,
			$this->listingHandler,
			$this->multipartHandler,
			$this->versioningHandler,
			$this->taggingHandler,
			$this->aclHandler,
			$this->corsHandler,
			$this->encryptionHandler,
			new XmlWriter(),
			$this->logger,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->auth = AuthContext::authenticated($user, AuthContext::METHOD_SIGV4);
	}

	private function req(string $method = 'GET', ?string $bucket = 'b', ?string $key = null, array $query = []): S3Request {
		return new S3Request($method, $bucket, $key, $query, [], '', "/{$bucket}", 'localhost');
	}

	private function ok(): S3Response {
		return S3Response::ok('<xml/>');
	}

	// -------------------------------------------------------------------------
	// Routing to handlers
	// -------------------------------------------------------------------------

	public function testDispatchesListBuckets(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_BUCKETS);
		$this->bucketHandler->expects($this->once())->method('listBuckets')->willReturn($this->ok());

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);
		$this->assertSame(200, $response->statusCode);
	}

	public function testDispatchesCreateBucket(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::CREATE_BUCKET);
		$this->bucketHandler->expects($this->once())->method('createBucket')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('PUT', 'new-bucket'), $this->auth);
	}

	public function testDispatchesDeleteBucket(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_BUCKET);
		$this->bucketHandler->expects($this->once())->method('deleteBucket')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req('DELETE', 'b'), $this->auth);
	}

	public function testDispatchesHeadBucket(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::HEAD_BUCKET);
		$this->bucketHandler->expects($this->once())->method('headBucket')->willReturn(new S3Response(statusCode: 200));

		$this->dispatcher->dispatch($this->req('HEAD', 'b'), $this->auth);
	}

	public function testDispatchesGetBucketLocation(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_LOCATION);
		$this->bucketHandler->expects($this->once())->method('getBucketLocation')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesListObjects(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_OBJECTS);
		$this->listingHandler->expects($this->once())->method('listObjects')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesListObjectsV2(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_OBJECTS_V2);
		$this->listingHandler->expects($this->once())->method('listObjectsV2')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetObject(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_OBJECT);
		$this->objectHandler->expects($this->once())->method('getObject')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('GET', 'b', 'k'), $this->auth);
	}

	public function testDispatchesPutObject(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_OBJECT);
		$this->objectHandler->expects($this->once())->method('putObject')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('PUT', 'b', 'k'), $this->auth);
	}

	public function testDispatchesDeleteObject(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_OBJECT);
		$this->objectHandler->expects($this->once())->method('deleteObject')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req('DELETE', 'b', 'k'), $this->auth);
	}

	public function testDispatchesHeadObject(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::HEAD_OBJECT);
		$this->objectHandler->expects($this->once())->method('headObject')->willReturn(new S3Response(statusCode: 200));

		$this->dispatcher->dispatch($this->req('HEAD', 'b', 'k'), $this->auth);
	}

	public function testDispatchesCopyObject(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::COPY_OBJECT);
		$this->objectHandler->expects($this->once())->method('copyObject')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('PUT', 'b', 'k'), $this->auth);
	}

	public function testDispatchesDeleteObjects(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_OBJECTS);
		$this->objectHandler->expects($this->once())->method('deleteObjects')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('POST', 'b', null, ['delete' => '']), $this->auth);
	}

	public function testDispatchesGetObjectAttributes(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_OBJECT_ATTRIBUTES);
		// GET_OBJECT_ATTRIBUTES maps to headObject
		$this->objectHandler->expects($this->once())->method('headObject')->willReturn(new S3Response(statusCode: 200));

		$this->dispatcher->dispatch($this->req('GET', 'b', 'k'), $this->auth);
	}

	public function testDispatchesInitiateMultipartUpload(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::INITIATE_MULTIPART_UPLOAD);
		$this->multipartHandler->expects($this->once())->method('initiateMultipartUpload')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('POST', 'b', 'k', ['uploads' => '']), $this->auth);
	}

	public function testDispatchesUploadPart(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::UPLOAD_PART);
		$this->multipartHandler->expects($this->once())->method('uploadPart')
			->willReturn(new S3Response(statusCode: 200, headers: ['ETag' => '"abc"']));

		$this->dispatcher->dispatch($this->req('PUT', 'b', 'k', ['uploadId' => 'u', 'partNumber' => '1']), $this->auth);
	}

	public function testDispatchesCompleteMultipartUpload(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::COMPLETE_MULTIPART_UPLOAD);
		$this->multipartHandler->expects($this->once())->method('completeMultipartUpload')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('POST', 'b', 'k', ['uploadId' => 'u']), $this->auth);
	}

	public function testDispatchesAbortMultipartUpload(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::ABORT_MULTIPART_UPLOAD);
		$this->multipartHandler->expects($this->once())->method('abortMultipartUpload')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req('DELETE', 'b', 'k', ['uploadId' => 'u']), $this->auth);
	}

	public function testDispatchesListMultipartUploads(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_MULTIPART_UPLOADS);
		$this->multipartHandler->expects($this->once())->method('listMultipartUploads')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('GET', 'b', null, ['uploads' => '']), $this->auth);
	}

	public function testDispatchesListParts(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_PARTS);
		$this->multipartHandler->expects($this->once())->method('listParts')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req('GET', 'b', 'k', ['uploadId' => 'u']), $this->auth);
	}

	public function testDispatchesGetBucketVersioning(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_VERSIONING);
		$this->versioningHandler->expects($this->once())->method('getBucketVersioning')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutBucketVersioning(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_BUCKET_VERSIONING);
		$this->versioningHandler->expects($this->once())->method('putBucketVersioning')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesListObjectVersions(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::LIST_OBJECT_VERSIONS);
		$this->versioningHandler->expects($this->once())->method('listObjectVersions')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetObjectTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_OBJECT_TAGGING);
		$this->taggingHandler->expects($this->once())->method('getObjectTagging')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutObjectTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_OBJECT_TAGGING);
		$this->taggingHandler->expects($this->once())->method('putObjectTagging')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesDeleteObjectTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_OBJECT_TAGGING);
		$this->taggingHandler->expects($this->once())->method('deleteObjectTagging')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetBucketTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_TAGGING);
		$this->taggingHandler->expects($this->once())->method('getBucketTagging')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutBucketTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_BUCKET_TAGGING);
		$this->taggingHandler->expects($this->once())->method('putBucketTagging')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesDeleteBucketTagging(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_BUCKET_TAGGING);
		$this->taggingHandler->expects($this->once())->method('deleteBucketTagging')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetBucketAcl(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_ACL);
		$this->aclHandler->expects($this->once())->method('getBucketAcl')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutBucketAcl(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_BUCKET_ACL);
		$this->aclHandler->expects($this->once())->method('putBucketAcl')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetObjectAcl(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_OBJECT_ACL);
		$this->aclHandler->expects($this->once())->method('getObjectAcl')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutObjectAcl(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_OBJECT_ACL);
		$this->aclHandler->expects($this->once())->method('putObjectAcl')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetBucketCors(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_CORS);
		$this->corsHandler->expects($this->once())->method('getBucketCors')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutBucketCors(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_BUCKET_CORS);
		$this->corsHandler->expects($this->once())->method('putBucketCors')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesDeleteBucketCors(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_BUCKET_CORS);
		$this->corsHandler->expects($this->once())->method('deleteBucketCors')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesGetBucketEncryption(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_BUCKET_ENCRYPTION);
		$this->encryptionHandler->expects($this->once())->method('getBucketEncryption')->willReturn($this->ok());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesPutBucketEncryption(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::PUT_BUCKET_ENCRYPTION);
		$this->encryptionHandler->expects($this->once())->method('putBucketEncryption')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	public function testDispatchesDeleteBucketEncryption(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::DELETE_BUCKET_ENCRYPTION);
		$this->encryptionHandler->expects($this->once())->method('deleteBucketEncryption')->willReturn(S3Response::noContent());

		$this->dispatcher->dispatch($this->req(), $this->auth);
	}

	// -------------------------------------------------------------------------
	// Exception mapping
	// -------------------------------------------------------------------------

	public function testS3ExceptionBecomesXmlErrorResponse(): void {
		$this->resolver->method('resolve')
			->willThrowException(new S3Exception(S3ErrorCodes::NO_SUCH_BUCKET, 'Not found', '/b'));

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		$this->assertSame(404, $response->statusCode);
		$this->assertNotNull($response->xmlBody);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame(S3ErrorCodes::NO_SUCH_BUCKET, (string)$xml->Code);
	}

	public function testAccessDeniedExceptionBecomesS3ErrorResponse(): void {
		$this->resolver->method('resolve')
			->willThrowException(new AccessDeniedException('Not your bucket'));

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		// AccessDeniedException extends S3Exception, so it should return an error response
		$this->assertNotNull($response->xmlBody);
		$this->assertGreaterThanOrEqual(400, $response->statusCode);
	}

	public function testUnexpectedExceptionReturns500AndLogsError(): void {
		$this->resolver->method('resolve')
			->willThrowException(new \RuntimeException('Unexpected DB failure'));

		$this->logger->expects($this->once())->method('error');

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		$this->assertSame(500, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame(S3ErrorCodes::INTERNAL_ERROR, (string)$xml->Code);
	}

	public function testNotPermittedExceptionReturns403(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::GET_OBJECT);
		$this->objectHandler->method('getObject')
			->willThrowException(new \OCP\Files\NotPermittedException('no access'));

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		$this->assertSame(403, $response->statusCode);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame(S3ErrorCodes::ACCESS_DENIED, (string)$xml->Code);
	}

	// -------------------------------------------------------------------------
	// RestoreObject stub
	// -------------------------------------------------------------------------

	public function testRestoreObjectReturnsInvalidRequest(): void {
		$this->resolver->method('resolve')->willReturn(OperationResolver::RESTORE_OBJECT);

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		$this->assertNotNull($response->xmlBody);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame(S3ErrorCodes::INVALID_REQUEST, (string)$xml->Code);
	}

	// -------------------------------------------------------------------------
	// Unknown operation
	// -------------------------------------------------------------------------

	public function testUnknownOperationReturnsInvalidRequest(): void {
		$this->resolver->method('resolve')->willReturn('SomeUnknownOperation');

		$response = $this->dispatcher->dispatch($this->req(), $this->auth);

		$this->assertNotNull($response->xmlBody);
		$xml = simplexml_load_string($response->xmlBody);
		$this->assertSame(S3ErrorCodes::INVALID_REQUEST, (string)$xml->Code);
	}
}
