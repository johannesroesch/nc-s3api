# Unsupported S3 Features

This document lists S3 API features that are **not implemented** by `nc-s3api`,
together with the reason each feature cannot be supported given Nextcloud's
architecture.

Pull requests that implement any of these features (or reasonable approximations)
are welcome, provided they include tests and documentation updates.

---

## Not Implemented

### Bucket Replication

**S3 API:** `GetBucketReplication`, `PutBucketReplication`, `DeleteBucketReplication`

**Reason:** S3 replication copies objects across buckets in different AWS accounts
or regions.  Nextcloud has no built-in concept of cross-instance synchronisation
at the API level.  Replication would require coordinating two independent Nextcloud
instances with no shared API surface.

**Alternative:** Use `rclone sync` or a dedicated backup tool to replicate data
between two Nextcloud instances.

---

### S3 Website Hosting

**S3 API:** `GetBucketWebsite`, `PutBucketWebsite`, `DeleteBucketWebsite`

**Reason:** Nextcloud is a file-sync and collaboration platform, not a static
website host.  Serving HTML/CSS/JS files with custom index and error documents
from a virtual hostname is outside Nextcloud's scope.

---

### Object Lock / Legal Hold (WORM)

**S3 API:** `PutObjectRetention`, `GetObjectRetention`, `PutObjectLegalHold`,
`GetObjectLegalHold`, `GetObjectLockConfiguration`, `PutObjectLockConfiguration`

**Reason:** Nextcloud has no Write-Once-Read-Many (WORM) locking mechanism.
Files can always be deleted or overwritten by the owning user or an admin.
Implementing a hard lock would require modifications to Nextcloud core.

---

### Storage Classes / Intelligent Tiering

**S3 API header:** `x-amz-storage-class`

**S3 values:** `STANDARD`, `REDUCED_REDUNDANCY`, `STANDARD_IA`, `ONEZONE_IA`,
`INTELLIGENT_TIERING`, `GLACIER`, `DEEP_ARCHIVE`, `GLACIER_IR`, `EXPRESS_ONEZONE`

**Reason:** Nextcloud stores files on a single configured backend
(local filesystem, S3, SFTP, …).  It has no tiering framework to move objects
between storage classes automatically or on demand.

The `x-amz-storage-class` header is **accepted** in PUT requests (to avoid
client errors) but silently **ignored**.  All objects are stored in the
equivalent of `STANDARD`.

---

### Bucket Lifecycle Policies

**S3 API:** `GetBucketLifecycle`, `PutBucketLifecycle`, `DeleteBucketLifecycle`

**Reason:** Lifecycle policies require a background process that periodically
inspects objects and performs actions (delete, transition) based on age, tags,
or prefixes.  Nextcloud's background job system (`OCP\BackgroundJob`) could
in principle be used for this, but the implementation is complex and
the surface area for data loss is large.

**Future work:** A best-effort implementation (e.g. expiry rules only) could be
added as an optional feature.

---

### S3 Event Notifications

**S3 API:** `GetBucketNotification`, `PutBucketNotification`, `DeleteBucketNotification`

**S3 targets:** Amazon SNS, Amazon SQS, AWS Lambda

**Reason:** S3 event notifications are tightly coupled to AWS messaging
infrastructure (SNS, SQS, Lambda).  Nextcloud has no equivalent.

**Alternative:** Use Nextcloud's own webhook system (`OCP\Http\Client`) or
Activity App hooks to subscribe to file events.

---

### Request Payment

**S3 API:** `GetBucketRequestPayment`, `PutBucketRequestPayment`

**Reason:** This is an AWS billing feature that shifts S3 data transfer costs
from the bucket owner to the requester.  It has no equivalent outside AWS.

---

### SSE-KMS (AWS Key Management Service)

**S3 API header:** `x-amz-server-side-encryption: aws:kms`

**Reason:** SSE-KMS relies on AWS KMS to manage and rotate encryption keys.
Nextcloud does not integrate with AWS KMS.

The `PutBucketEncryption` / `GetBucketEncryption` API is implemented and stores
an `AES256` (SSE-S3) configuration.  Requests that specify `aws:kms` are
**accepted** (to avoid client errors) but treated as `AES256`.

Nextcloud's own encryption subsystem (when enabled by the admin) provides
at-rest encryption independently of this gateway.

---

### SSE-C (Customer-Provided Encryption Keys)

**S3 API headers:** `x-amz-server-side-encryption-customer-algorithm`,
`x-amz-server-side-encryption-customer-key`,
`x-amz-server-side-encryption-customer-key-MD5`

**Reason:** SSE-C requires the client to supply an AES-256 key with every
PUT and GET request.  The server must encrypt/decrypt transparently without
persisting the key.  Implementing this securely (key never logged, never written
to disk, constant-time comparisons, …) is feasible in PHP but complex and
high-risk.  It is deferred to a future release.

---

### Bucket Inventory Configurations

**S3 API:** `GetBucketInventoryConfiguration`,
`PutBucketInventoryConfiguration`,
`DeleteBucketInventoryConfiguration`,
`ListBucketInventoryConfigurations`

**Reason:** Inventory generates CSV or ORC reports listing all objects in a
bucket on a daily or weekly schedule.  This requires background processing
and an output-bucket target.  Out of scope for the initial implementation.

---

### Bucket Metrics & Analytics Configurations

**S3 API:** `GetBucketMetricsConfiguration`,
`PutBucketMetricsConfiguration`,
`DeleteBucketMetricsConfiguration`,
`ListBucketMetricsConfigurations`,
`GetBucketAnalyticsConfiguration`,
`PutBucketAnalyticsConfiguration`,
`DeleteBucketAnalyticsConfiguration`,
`ListBucketAnalyticsConfigurations`

**Reason:** These features export CloudWatch metrics or storage analytics.
Nextcloud has no equivalent metrics pipeline.  Prometheus / Grafana monitoring
of the Nextcloud server itself is the recommended alternative.

---

### UploadPartCopy

**S3 API:** `UploadPartCopy` (PUT with `x-amz-copy-source` + `?uploadId&partNumber`)

**Reason:** This feature creates a multipart upload part by copying a byte range
from an existing object.  It is a performance optimisation for large-file
server-side operations.  The regular `UploadPart` (uploading bytes from the
client) is fully implemented.  `UploadPartCopy` is deferred to a future release.

---

## Partially Implemented

### Object Versioning

**Status:** The versioning on/off state is stored and returned correctly.
`ListObjectVersions` returns the current version of each object with a null
version ID (the S3 representation of objects stored before versioning was
enabled).

**Not implemented:** Retrieving, deleting, or restoring previous versions of an
object.  Nextcloud stores file versions via the `files_versions` app but the
version IDs differ from S3 version IDs, and the mapping is non-trivial.
Full versioning support is planned for a future release.

### Object ACLs / Bucket ACLs

**Status:** `GetBucketAcl`, `GetObjectAcl` return the owner with `FULL_CONTROL`.
`PutBucketAcl` stores the canned ACL name in metadata.

**Not implemented:** Translating S3 ACL grants (canonical user IDs, `AllUsers`,
`AuthenticatedUsers`) to Nextcloud share links or group shares.  This requires
knowledge of the Nextcloud user directory that S3 clients do not provide.

---

*Last updated: 2025*
