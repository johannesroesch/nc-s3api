# Changelog

All notable changes to `nc-s3api` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).  
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

## [0.1.0] — 2026-04-16

### Added

**Core S3 API (Tier 1)**
- `ListBuckets`, `CreateBucket`, `DeleteBucket`, `HeadBucket`, `GetBucketLocation`
- `GetObject` (with `Range` support), `PutObject`, `DeleteObject`, `HeadObject`, `CopyObject`
- `DeleteObjects` (batch delete)
- `ListObjects` (v1) and `ListObjects` (v2) with prefix / delimiter / pagination
- Multipart Upload: `CreateMultipartUpload`, `UploadPart`, `CompleteMultipartUpload`,
  `AbortMultipartUpload`, `ListMultipartUploads`, `ListParts`
- AWS Signature Version 4 authentication (Authorization header)
- Presigned URLs (GET / PUT / DELETE via query-parameter signature)

**Extended S3 API (Tier 2)**
- Versioning: `GetBucketVersioning`, `PutBucketVersioning`, `ListObjectVersions`
- Tagging: `GetObjectTagging`, `PutObjectTagging`, `DeleteObjectTagging`,
  `GetBucketTagging`, `PutBucketTagging`, `DeleteBucketTagging`
- ACL: `GetBucketAcl`, `PutBucketAcl`, `GetObjectAcl`, `PutObjectAcl`
- CORS: `GetBucketCors`, `PutBucketCors`, `DeleteBucketCors`
- Encryption: `GetBucketEncryption`, `PutBucketEncryption`, `DeleteBucketEncryption` (SSE-S3 / AES256)

**Dual-mode credential mapping**
- Mode A: per-user credentials stored in `s3api_credentials` table (user configures via personal settings)
- Mode B: admin-managed key-pairs mapped to any Nextcloud user (configured via admin settings)

**Settings UI**
- Admin panel: create / delete key-pairs for Mode B service accounts
- Personal panel: create / delete personal S3 credentials (Mode A); secret shown once

**Infrastructure**
- `docker-compose.yml` for local Nextcloud 28 + MariaDB 10.11 test environment
- `tests/smoke/run.sh`: aws-cli end-to-end smoke tests (25+ operations)
- GitHub Actions CI: PHPUnit on PHP 8.1 / 8.2 / 8.3 + syntax lint

### Notes
- Versioning stores on/off state correctly; retrieving previous file versions is not yet supported
  (see `docs/unsupported-features.md`)
- ACL stores canned ACL names; translating grants to Nextcloud share links is not yet implemented
- SSE-KMS and SSE-C requests are accepted but treated as AES256 / ignored respectively

[Unreleased]: https://github.com/johannesroesch/nc-s3api/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/johannesroesch/nc-s3api/releases/tag/v0.1.0
