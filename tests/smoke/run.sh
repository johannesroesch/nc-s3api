#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# tests/smoke/run.sh
# End-to-end smoke tests for nc-s3api using the AWS CLI v2.
#
# Prerequisites:
#   - docker compose up -d (from project root)
#   - aws CLI v2 installed on the host
#   - wait until the nextcloud container is healthy
#
# Usage:
#   ./tests/smoke/run.sh [endpoint-url]
#
# Default endpoint: http://localhost:8080/index.php/apps/nc_s3api/s3

set -euo pipefail

ENDPOINT="${1:-http://localhost:8080/index.php/apps/nc_s3api/s3}"
NC_USER="admin"
NC_SECRET="adminpassword"  # Must match docker-compose NEXTCLOUD_ADMIN_PASSWORD
REGION="us-east-1"
PROFILE="nc-s3api-smoke"
BUCKET="smoke-test-$$"
TMPFILE=$(mktemp)

RED='\033[0;31m'
GREEN='\033[0;32m'
NC_COLOR='\033[0m'

pass() { echo -e "${GREEN}PASS${NC_COLOR}  $*"; }
fail() { echo -e "${RED}FAIL${NC_COLOR}  $*"; exit 1; }

# -----------------------------------------------------------------------
# Setup: create a temporary aws profile
# -----------------------------------------------------------------------
echo "--- Setup ---"
aws configure set aws_access_key_id     "$NC_USER"   --profile "$PROFILE"
aws configure set aws_secret_access_key "$NC_SECRET" --profile "$PROFILE"
aws configure set region                "$REGION"    --profile "$PROFILE"
aws configure set output                json         --profile "$PROFILE"

S3="aws s3 --profile $PROFILE --endpoint-url $ENDPOINT"
S3API="aws s3api --profile $PROFILE --endpoint-url $ENDPOINT"

# -----------------------------------------------------------------------
# Helper: wait for the Nextcloud instance to be ready
# -----------------------------------------------------------------------
echo "--- Waiting for Nextcloud ($ENDPOINT) ---"
for i in $(seq 1 30); do
    if curl -sf "${ENDPOINT%/s3*}/status.php" > /dev/null 2>&1; then
        pass "Nextcloud is up"
        break
    fi
    echo "  Attempt $i/30 — sleeping 5s …"
    sleep 5
    if [ "$i" -eq 30 ]; then
        fail "Nextcloud did not come up in time"
    fi
done

# -----------------------------------------------------------------------
# Make sure the app is enabled
# -----------------------------------------------------------------------
echo "--- Enable app ---"
docker compose exec -T nextcloud php /var/www/html/occ app:enable nc_s3api 2>&1 | tail -1

# -----------------------------------------------------------------------
# Bucket operations
# -----------------------------------------------------------------------
echo "--- Bucket operations ---"

$S3 mb "s3://$BUCKET" && pass "CreateBucket" || fail "CreateBucket"

$S3API list-buckets | grep -q "\"Name\": \"$BUCKET\"" \
    && pass "ListBuckets" || fail "ListBuckets"

$S3API head-bucket --bucket "$BUCKET" 2>&1 | grep -qv "error" \
    && pass "HeadBucket" || fail "HeadBucket"

$S3API get-bucket-location --bucket "$BUCKET" | grep -q "LocationConstraint" \
    && pass "GetBucketLocation" || fail "GetBucketLocation"

# -----------------------------------------------------------------------
# Object operations
# -----------------------------------------------------------------------
echo "--- Object operations ---"

echo "Hello from nc-s3api smoke test" > "$TMPFILE"
$S3 cp "$TMPFILE" "s3://$BUCKET/hello.txt" \
    && pass "PutObject" || fail "PutObject"

$S3API list-objects --bucket "$BUCKET" | grep -q "hello.txt" \
    && pass "ListObjects" || fail "ListObjects"

$S3API list-objects-v2 --bucket "$BUCKET" | grep -q "hello.txt" \
    && pass "ListObjectsV2" || fail "ListObjectsV2"

$S3API head-object --bucket "$BUCKET" --key "hello.txt" | grep -q "ContentLength" \
    && pass "HeadObject" || fail "HeadObject"

DOWNLOADED=$(mktemp)
$S3 cp "s3://$BUCKET/hello.txt" "$DOWNLOADED" \
    && diff "$TMPFILE" "$DOWNLOADED" \
    && pass "GetObject (content match)" || fail "GetObject"

# Copy
$S3API copy-object \
    --bucket "$BUCKET" \
    --copy-source "$BUCKET/hello.txt" \
    --key "hello-copy.txt" \
    | grep -q "ETag" && pass "CopyObject" || fail "CopyObject"

# Range request
$S3API get-object \
    --bucket "$BUCKET" \
    --key "hello.txt" \
    --range "bytes=0-4" \
    /dev/null | grep -q "ContentRange" && pass "GetObject (Range)" || fail "GetObject Range"

# DeleteObject
$S3API delete-object --bucket "$BUCKET" --key "hello-copy.txt" \
    && pass "DeleteObject" || fail "DeleteObject"

# DeleteObjects (batch)
$S3API delete-objects \
    --bucket "$BUCKET" \
    --delete '{"Objects":[{"Key":"hello.txt"}],"Quiet":false}' \
    | grep -q "Deleted" && pass "DeleteObjects" || fail "DeleteObjects"

# -----------------------------------------------------------------------
# Multipart upload
# -----------------------------------------------------------------------
echo "--- Multipart upload ---"

# Generate a 10 MB test file (2 parts × 5 MB; AWS minimum part size is 5 MB)
MPFILE=$(mktemp)
dd if=/dev/urandom bs=1M count=10 of="$MPFILE" 2>/dev/null

UPLOAD_ID=$($S3API create-multipart-upload --bucket "$BUCKET" --key "multipart.bin" \
    | jq -r '.UploadId')
[ -n "$UPLOAD_ID" ] && pass "CreateMultipartUpload (UploadId=$UPLOAD_ID)" || fail "CreateMultipartUpload"

PART1=$(mktemp); PART2=$(mktemp)
dd if="$MPFILE" bs=1M count=5      of="$PART1" 2>/dev/null
dd if="$MPFILE" bs=1M count=5 skip=5 of="$PART2" 2>/dev/null

ETAG1=$($S3API upload-part --bucket "$BUCKET" --key "multipart.bin" \
    --part-number 1 --upload-id "$UPLOAD_ID" --body "$PART1" | jq -r '.ETag')
ETAG2=$($S3API upload-part --bucket "$BUCKET" --key "multipart.bin" \
    --part-number 2 --upload-id "$UPLOAD_ID" --body "$PART2" | jq -r '.ETag')
[ -n "$ETAG1" ] && [ -n "$ETAG2" ] && pass "UploadPart (×2)" || fail "UploadPart"

$S3API list-parts --bucket "$BUCKET" --key "multipart.bin" --upload-id "$UPLOAD_ID" \
    | grep -q "PartNumber" && pass "ListParts" || fail "ListParts"

$S3API complete-multipart-upload \
    --bucket "$BUCKET" \
    --key "multipart.bin" \
    --upload-id "$UPLOAD_ID" \
    --multipart-upload "{\"Parts\":[{\"PartNumber\":1,\"ETag\":$ETAG1},{\"PartNumber\":2,\"ETag\":$ETAG2}]}" \
    | grep -q "ETag" && pass "CompleteMultipartUpload" || fail "CompleteMultipartUpload"

# -----------------------------------------------------------------------
# Tagging
# -----------------------------------------------------------------------
echo "--- Tagging ---"

$S3API put-object-tagging \
    --bucket "$BUCKET" \
    --key "multipart.bin" \
    --tagging '{"TagSet":[{"Key":"env","Value":"smoke"}]}' \
    && pass "PutObjectTagging" || fail "PutObjectTagging"

$S3API get-object-tagging --bucket "$BUCKET" --key "multipart.bin" \
    | grep -q "smoke" && pass "GetObjectTagging" || fail "GetObjectTagging"

$S3API delete-object-tagging --bucket "$BUCKET" --key "multipart.bin" \
    && pass "DeleteObjectTagging" || fail "DeleteObjectTagging"

$S3API put-bucket-tagging \
    --bucket "$BUCKET" \
    --tagging '{"TagSet":[{"Key":"project","Value":"nc-s3api"}]}' \
    && pass "PutBucketTagging" || fail "PutBucketTagging"

$S3API get-bucket-tagging --bucket "$BUCKET" \
    | grep -q "nc-s3api" && pass "GetBucketTagging" || fail "GetBucketTagging"

# -----------------------------------------------------------------------
# Versioning
# -----------------------------------------------------------------------
echo "--- Versioning ---"

$S3API put-bucket-versioning \
    --bucket "$BUCKET" \
    --versioning-configuration Status=Enabled \
    && pass "PutBucketVersioning" || fail "PutBucketVersioning"

$S3API get-bucket-versioning --bucket "$BUCKET" \
    | grep -q "Enabled" && pass "GetBucketVersioning" || fail "GetBucketVersioning"

# -----------------------------------------------------------------------
# CORS
# -----------------------------------------------------------------------
echo "--- CORS ---"

$S3API put-bucket-cors \
    --bucket "$BUCKET" \
    --cors-configuration '{
        "CORSRules":[{
            "AllowedHeaders":["*"],
            "AllowedMethods":["GET","PUT"],
            "AllowedOrigins":["*"],
            "MaxAgeSeconds":3000
        }]}' \
    && pass "PutBucketCors" || fail "PutBucketCors"

$S3API get-bucket-cors --bucket "$BUCKET" \
    | grep -q "AllowedOrigins" && pass "GetBucketCors" || fail "GetBucketCors"

$S3API delete-bucket-cors --bucket "$BUCKET" \
    && pass "DeleteBucketCors" || fail "DeleteBucketCors"

# -----------------------------------------------------------------------
# Cleanup
# -----------------------------------------------------------------------
echo "--- Cleanup ---"

# Delete remaining objects
$S3 rm "s3://$BUCKET" --recursive 2>/dev/null || true

$S3 rb "s3://$BUCKET" && pass "DeleteBucket" || fail "DeleteBucket"

rm -f "$TMPFILE" "$DOWNLOADED" "$MPFILE" "$PART1" "$PART2"

echo ""
echo "=== All smoke tests passed ==="
