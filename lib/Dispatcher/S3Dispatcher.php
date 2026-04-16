<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Dispatcher;

use OCA\NcS3Api\Auth\AuthContext;
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
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Central dispatcher: resolves the S3 operation and delegates to the
 * responsible handler.  Also converts all exceptions to S3-compatible
 * error responses.
 */
class S3Dispatcher {
	public function __construct(
		private readonly OperationResolver $resolver,
		private readonly BucketHandler $bucketHandler,
		private readonly ObjectHandler $objectHandler,
		private readonly ListingHandler $listingHandler,
		private readonly MultipartHandler $multipartHandler,
		private readonly VersioningHandler $versioningHandler,
		private readonly TaggingHandler $taggingHandler,
		private readonly AclHandler $aclHandler,
		private readonly CorsHandler $corsHandler,
		private readonly EncryptionHandler $encryptionHandler,
		private readonly XmlWriter $xmlWriter,
		private readonly LoggerInterface $logger,
	) {
	}

	public function dispatch(S3Request $request, AuthContext $auth): S3Response {
		try {
			$operation = $this->resolver->resolve($request);
			return $this->handle($operation, $request, $auth);
		} catch (S3Exception $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $e->getResource() ?? '', $e->getHttpStatus());
		} catch (NotPermittedException $e) {
			return $this->errorResponse(S3ErrorCodes::ACCESS_DENIED, 'Access Denied', '', 403);
		} catch (\Throwable $e) {
			// Log unexpected errors but don't leak details to the client
			$this->logger->error(
				'nc_s3api: Unhandled exception: ' . $e->getMessage(),
				['exception' => $e, 'app' => 'nc_s3api'],
			);
			return $this->errorResponse(S3ErrorCodes::INTERNAL_ERROR, 'An internal error occurred.', '', 500);
		}
	}

	/**
	 * @throws S3Exception
	 * @throws \Throwable
	 */
	private function handle(string $operation, S3Request $request, AuthContext $auth): S3Response {
		return match ($operation) {
			// Service
			OperationResolver::LIST_BUCKETS => $this->bucketHandler->listBuckets($request, $auth),

			// Bucket
			OperationResolver::CREATE_BUCKET => $this->bucketHandler->createBucket($request, $auth),
			OperationResolver::DELETE_BUCKET => $this->bucketHandler->deleteBucket($request, $auth),
			OperationResolver::HEAD_BUCKET => $this->bucketHandler->headBucket($request, $auth),
			OperationResolver::GET_BUCKET_LOCATION => $this->bucketHandler->getBucketLocation($request, $auth),

			// Listing
			OperationResolver::LIST_OBJECTS => $this->listingHandler->listObjects($request, $auth),
			OperationResolver::LIST_OBJECTS_V2 => $this->listingHandler->listObjectsV2($request, $auth),
			OperationResolver::LIST_OBJECT_VERSIONS => $this->versioningHandler->listObjectVersions($request, $auth),

			// Objects
			OperationResolver::GET_OBJECT => $this->objectHandler->getObject($request, $auth),
			OperationResolver::PUT_OBJECT => $this->objectHandler->putObject($request, $auth),
			OperationResolver::DELETE_OBJECT => $this->objectHandler->deleteObject($request, $auth),
			OperationResolver::DELETE_OBJECTS => $this->objectHandler->deleteObjects($request, $auth),
			OperationResolver::HEAD_OBJECT => $this->objectHandler->headObject($request, $auth),
			OperationResolver::COPY_OBJECT => $this->objectHandler->copyObject($request, $auth),
			OperationResolver::GET_OBJECT_ATTRIBUTES => $this->objectHandler->headObject($request, $auth), // maps to HeadObject

			// Multipart
			OperationResolver::INITIATE_MULTIPART_UPLOAD => $this->multipartHandler->initiateMultipartUpload($request, $auth),
			OperationResolver::UPLOAD_PART => $this->multipartHandler->uploadPart($request, $auth),
			OperationResolver::COMPLETE_MULTIPART_UPLOAD => $this->multipartHandler->completeMultipartUpload($request, $auth),
			OperationResolver::ABORT_MULTIPART_UPLOAD => $this->multipartHandler->abortMultipartUpload($request, $auth),
			OperationResolver::LIST_MULTIPART_UPLOADS => $this->multipartHandler->listMultipartUploads($request, $auth),
			OperationResolver::LIST_PARTS => $this->multipartHandler->listParts($request, $auth),

			// Versioning
			OperationResolver::GET_BUCKET_VERSIONING => $this->versioningHandler->getBucketVersioning($request, $auth),
			OperationResolver::PUT_BUCKET_VERSIONING => $this->versioningHandler->putBucketVersioning($request, $auth),

			// Tagging
			OperationResolver::GET_OBJECT_TAGGING => $this->taggingHandler->getObjectTagging($request, $auth),
			OperationResolver::PUT_OBJECT_TAGGING => $this->taggingHandler->putObjectTagging($request, $auth),
			OperationResolver::DELETE_OBJECT_TAGGING => $this->taggingHandler->deleteObjectTagging($request, $auth),
			OperationResolver::GET_BUCKET_TAGGING => $this->taggingHandler->getBucketTagging($request, $auth),
			OperationResolver::PUT_BUCKET_TAGGING => $this->taggingHandler->putBucketTagging($request, $auth),
			OperationResolver::DELETE_BUCKET_TAGGING => $this->taggingHandler->deleteBucketTagging($request, $auth),

			// ACL
			OperationResolver::GET_BUCKET_ACL => $this->aclHandler->getBucketAcl($request, $auth),
			OperationResolver::PUT_BUCKET_ACL => $this->aclHandler->putBucketAcl($request, $auth),
			OperationResolver::GET_OBJECT_ACL => $this->aclHandler->getObjectAcl($request, $auth),
			OperationResolver::PUT_OBJECT_ACL => $this->aclHandler->putObjectAcl($request, $auth),

			// CORS
			OperationResolver::GET_BUCKET_CORS => $this->corsHandler->getBucketCors($request, $auth),
			OperationResolver::PUT_BUCKET_CORS => $this->corsHandler->putBucketCors($request, $auth),
			OperationResolver::DELETE_BUCKET_CORS => $this->corsHandler->deleteBucketCors($request, $auth),

			// Encryption
			OperationResolver::GET_BUCKET_ENCRYPTION => $this->encryptionHandler->getBucketEncryption($request, $auth),
			OperationResolver::PUT_BUCKET_ENCRYPTION => $this->encryptionHandler->putBucketEncryption($request, $auth),
			OperationResolver::DELETE_BUCKET_ENCRYPTION => $this->encryptionHandler->deleteBucketEncryption($request, $auth),

			// Unsupported / stub
			OperationResolver::RESTORE_OBJECT => throw new S3Exception(
				S3ErrorCodes::INVALID_REQUEST,
				'RestoreObject is not supported by this gateway.',
			),

			default => throw new S3Exception(
				S3ErrorCodes::INVALID_REQUEST,
				"Unknown S3 operation: $operation",
			),
		};
	}

	private function errorResponse(string $code, string $message, string $resource, int $status): S3Response {
		$requestId = bin2hex(random_bytes(8));
		$xml = $this->xmlWriter->error($code, $message, $resource, $requestId);
		return new S3Response(
			statusCode: $status,
			headers: ['x-amz-request-id' => $requestId],
			xmlBody: $xml,
		);
	}
}
