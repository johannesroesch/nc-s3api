<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace OCA\NcS3Api\Handler;

use OCA\NcS3Api\Auth\AuthContext;
use OCA\NcS3Api\Exception\NoSuchKeyException;
use OCA\NcS3Api\Exception\S3Exception;
use OCA\NcS3Api\S3\S3ErrorCodes;
use OCA\NcS3Api\S3\S3Request;
use OCA\NcS3Api\S3\S3Response;
use OCA\NcS3Api\Storage\ObjectService;
use OCA\NcS3Api\Xml\XmlReader;
use OCA\NcS3Api\Xml\XmlWriter;

class ObjectHandler {
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly XmlWriter $xmlWriter,
		private readonly XmlReader $xmlReader,
	) {
	}

	// -------------------------------------------------------------------------
	// GetObject
	// -------------------------------------------------------------------------

	public function getObject(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$key = $request->key ?? '';

		$meta = $this->objectService->getObjectMeta($auth->userId, $bucket, $key);
		$rangeHeader = $request->getHeader('range');

		if ($rangeHeader !== '') {
			[$stream, $length, $contentRange] = $this->objectService->openRangeStream($auth->userId, $bucket, $key, $rangeHeader);
			return S3Response::stream($stream, 206, [
				'Content-Range' => $contentRange,
				'Content-Length' => (string)$length,
				'Content-Type' => $meta['content_type'],
				'ETag' => $meta['etag'],
				'Last-Modified' => $meta['last_modified'],
				'Accept-Ranges' => 'bytes',
			]);
		}

		$stream = $this->objectService->openReadStream($auth->userId, $bucket, $key);
		return S3Response::stream($stream, 200, [
			'Content-Length' => (string)$meta['size'],
			'Content-Type' => $meta['content_type'],
			'ETag' => $meta['etag'],
			'Last-Modified' => $meta['last_modified'],
			'Accept-Ranges' => 'bytes',
		]);
	}

	// -------------------------------------------------------------------------
	// HeadObject
	// -------------------------------------------------------------------------

	public function headObject(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$meta = $this->objectService->getObjectMeta($auth->userId, $request->bucket ?? '', $request->key ?? '');

		return new S3Response(statusCode: 200, headers: [
			'Content-Length' => (string)$meta['size'],
			'Content-Type' => $meta['content_type'],
			'ETag' => $meta['etag'],
			'Last-Modified' => $meta['last_modified'],
			'Accept-Ranges' => 'bytes',
		]);
	}

	// -------------------------------------------------------------------------
	// PutObject
	// -------------------------------------------------------------------------

	public function putObject(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';
		$key = $request->key ?? '';
		$contentType = $request->getHeader('content-type') ?: 'application/octet-stream';

		$etag = $this->objectService->putObject($auth->userId, $bucket, $key, $request->bodyStream, $contentType);

		return new S3Response(statusCode: 200, headers: ['ETag' => $etag]);
	}

	// -------------------------------------------------------------------------
	// DeleteObject
	// -------------------------------------------------------------------------

	public function deleteObject(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$this->objectService->deleteObject($auth->userId, $request->bucket ?? '', $request->key ?? '');
		return S3Response::noContent();
	}

	// -------------------------------------------------------------------------
	// DeleteObjects (POST /?delete)
	// -------------------------------------------------------------------------

	public function deleteObjects(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();
		$bucket = $request->bucket ?? '';

		$body = is_resource($request->bodyStream) ? stream_get_contents($request->bodyStream) : (string)$request->bodyStream;
		$parsed = $this->xmlReader->deleteObjects($body);

		$deleted = [];
		$errors = [];
		foreach ($parsed['objects'] as $obj) {
			try {
				$this->objectService->deleteObject($auth->userId, $bucket, $obj['key']);
				if (!$parsed['quiet']) {
					$deleted[] = ['key' => $obj['key']];
				}
			} catch (NoSuchKeyException) {
				// S3 treats missing key as a successful delete
				if (!$parsed['quiet']) {
					$deleted[] = ['key' => $obj['key']];
				}
			} catch (\Throwable $e) {
				$errors[] = [
					'key' => $obj['key'],
					'code' => S3ErrorCodes::INTERNAL_ERROR,
					'message' => $e->getMessage(),
				];
			}
		}

		$xml = $this->xmlWriter->deleteObjectsResult($deleted, $errors);
		return S3Response::ok($xml);
	}

	// -------------------------------------------------------------------------
	// CopyObject
	// -------------------------------------------------------------------------

	public function copyObject(S3Request $request, AuthContext $auth): S3Response {
		$auth->requireAuthenticated();

		// Source: x-amz-copy-source = /src-bucket/src-key
		$copySource = ltrim($request->getHeader('x-amz-copy-source'), '/');
		if ($copySource === '') {
			throw new S3Exception(S3ErrorCodes::INVALID_ARGUMENT, 'Missing x-amz-copy-source header');
		}

		[$srcBucket, $srcKey] = array_pad(explode('/', $copySource, 2), 2, '');
		if ($srcBucket === '' || $srcKey === '') {
			throw new S3Exception(S3ErrorCodes::INVALID_ARGUMENT, 'Invalid x-amz-copy-source value');
		}

		$result = $this->objectService->copyObject(
			$auth->userId,
			$srcBucket,
			$srcKey,
			$request->bucket ?? '',
			$request->key ?? '',
		);

		// Build CopyObjectResult XML
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			 . '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
			 . '<LastModified>' . $result['last_modified'] . '</LastModified>'
			 . '<ETag>' . $result['etag'] . '</ETag>'
			 . '</CopyObjectResult>';

		return S3Response::ok($xml);
	}
}
