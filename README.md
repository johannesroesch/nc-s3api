# nc-s3api — S3 API Gateway for Nextcloud

A Nextcloud app that exposes an AWS S3-compatible REST API.  
External applications that speak the S3 protocol can use your Nextcloud instance as a drop-in S3 replacement — without any changes to the client code.

## Supported clients

- AWS CLI v2 (`aws s3`, `aws s3api`)
- AWS SDK (PHP, Python/boto3, Java, Go, …)
- MinIO Client (`mc`)
- Rclone (S3 provider)
- s3fs-fuse
- Any S3-compatible client

## Authentication

The gateway implements **AWS Signature Version 4**.

| Credential | Value |
|---|---|
| Access Key ID | Nextcloud username |
| Secret Access Key | A Nextcloud [App Password](https://nextcloud.com/blog/app-passwords/) |

Alternatively, an admin can create dedicated key-pairs in the app settings
(Admin → S3 Gateway), which map to a chosen Nextcloud user.  This enables
service accounts without exposing a real user's app-password.

## Endpoint URL

```
https://<your-nextcloud>/index.php/apps/nc_s3api/s3/
```

## Quick start (aws cli)

```bash
# Configure a profile
aws configure --profile nextcloud
# AWS Access Key ID: your-nc-username
# AWS Secret Access Key: your-app-password
# Default region name: us-east-1   (any value works)
# Default output format: json

# Create a bucket (= a folder in your Nextcloud home)
aws s3 mb s3://my-bucket \
  --endpoint-url https://cloud.example.com/index.php/apps/nc_s3api/s3 \
  --profile nextcloud

# Upload a file
aws s3 cp ./myfile.txt s3://my-bucket/ \
  --endpoint-url https://cloud.example.com/index.php/apps/nc_s3api/s3 \
  --profile nextcloud

# List objects
aws s3 ls s3://my-bucket \
  --endpoint-url https://cloud.example.com/index.php/apps/nc_s3api/s3 \
  --profile nextcloud
```

## Bucket mapping

Buckets are stored as folders under `s3/<bucket>/` in the Nextcloud user's home directory.  
Object keys map directly to file paths inside that folder (slashes preserved).

```
S3 bucket     → /home/<user>/s3/<bucket>/
S3 key        → /home/<user>/s3/<bucket>/<key>
```

## Implemented S3 features

### Tier 1 — Core

| Feature | Operations |
|---|---|
| Bucket CRUD | ListBuckets, CreateBucket, DeleteBucket, HeadBucket, GetBucketLocation |
| Object CRUD | GetObject (Range), PutObject, DeleteObject, HeadObject, CopyObject, DeleteObjects |
| Listing | ListObjects (v1 + v2) with prefix / delimiter / pagination |
| Multipart Upload | Initiate, UploadPart, Complete, Abort, ListMultipartUploads, ListParts |
| Authentication | AWS Signature V4, Presigned URLs (GET / PUT / DELETE) |

### Tier 2 — Extended

| Feature | Operations |
|---|---|
| Versioning | GetBucketVersioning, PutBucketVersioning, ListObjectVersions |
| Tagging | Object & Bucket tags (CRUD) |
| ACL | GetBucketAcl, PutBucketAcl, GetObjectAcl, PutObjectAcl |
| CORS | GetBucketCors, PutBucketCors, DeleteBucketCors |
| Encryption | GetBucketEncryption, PutBucketEncryption, DeleteBucketEncryption (SSE-S3) |

### Not supported

See [`docs/unsupported-features.md`](docs/unsupported-features.md) for a full list
of S3 features that cannot be implemented due to Nextcloud limitations,
with explanations and alternative approaches.

## Installation

### From the Nextcloud App Store

Search for **S3 API Gateway** in the Nextcloud app store.

### Manual installation

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/nicowillis/nc-s3api
cd nc-s3api
# Enable the app
php /var/www/nextcloud/occ app:enable nc_s3api
```

## Development setup

Requirements: PHP 8.1+, Composer, a running Nextcloud 28+ instance.

```bash
git clone https://github.com/nicowillis/nc-s3api
cd nc-s3api
composer install

# Run unit tests (no Nextcloud needed)
vendor/bin/phpunit --testsuite unit
```

For integration / E2E tests, mount the app directory inside a Docker-based
Nextcloud and run the smoke test suite:

```bash
docker compose up -d
./tests/smoke/run.sh
```

## License

GPL-2.0-or-later
