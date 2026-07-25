#!/usr/bin/env bash
# Validate real S3 multipart semantics against MinIO: initiate / upload-part / list /
# ABORT (no dangling) / complete, plus presigned-URL expiry (403 after TTL).
# Uses aws-cli in a container on the spike network. Mirrors S3Driver's multipart methods.
set -uo pipefail
NET=sam_spike_sam_spike_net
EP=http://spike-minio:9000
SCRATCH="$(cd "$(dirname "$0")/.." && pwd)/scratch"
AWS(){ docker run --rm --network "$NET" -e AWS_ACCESS_KEY_ID=spikeadmin -e AWS_SECRET_ACCESS_KEY=spikeadmin123 \
       -e AWS_DEFAULT_REGION=us-east-1 -v "$SCRATCH":/data amazon/aws-cli --endpoint-url "$EP" "$@"; }

# make two 5MB parts (min S3 part size)
dd if=/dev/urandom of="$SCRATCH/part1.bin" bs=1M count=5 2>/dev/null
dd if=/dev/urandom of="$SCRATCH/part2.bin" bs=1M count=5 2>/dev/null
KEY="mp-test/object.bin"

echo "=== 1. initiate multipart ==="
UP=$(AWS s3api create-multipart-upload --bucket backups --key "$KEY" --query UploadId --output text)
echo "UploadId=${UP:0:20}.."
echo "=== 2. upload 2 parts ==="
E1=$(AWS s3api upload-part --bucket backups --key "$KEY" --upload-id "$UP" --part-number 1 --body /data/part1.bin --query ETag --output text)
E2=$(AWS s3api upload-part --bucket backups --key "$KEY" --upload-id "$UP" --part-number 2 --body /data/part2.bin --query ETag --output text)
echo "part1 etag=$E1 part2 etag=$E2"
echo "=== 3. list-multipart-uploads (expect 1 in-progress) ==="
AWS s3api list-multipart-uploads --bucket backups --query 'Uploads[].UploadId' --output text
echo "=== 4. ABORT + verify no dangling ==="
AWS s3api abort-multipart-upload --bucket backups --key "$KEY" --upload-id "$UP"
DANGLING=$(AWS s3api list-multipart-uploads --bucket backups --query 'length(Uploads)' --output text 2>/dev/null)
echo "dangling uploads after abort = ${DANGLING:-0}  (expect 0/None)"
echo "=== 5. fresh multipart -> complete ==="
UP2=$(AWS s3api create-multipart-upload --bucket backups --key "$KEY" --query UploadId --output text)
F1=$(AWS s3api upload-part --bucket backups --key "$KEY" --upload-id "$UP2" --part-number 1 --body /data/part1.bin --query ETag --output text)
F2=$(AWS s3api upload-part --bucket backups --key "$KEY" --upload-id "$UP2" --part-number 2 --body /data/part2.bin --query ETag --output text)
cat > "$SCRATCH/mp.json" <<J
{"Parts":[{"PartNumber":1,"ETag":$F1},{"PartNumber":2,"ETag":$F2}]}
J
AWS s3api complete-multipart-upload --bucket backups --key "$KEY" --upload-id "$UP2" --multipart-upload file:///data/mp.json --query Key --output text
SZ=$(AWS s3api head-object --bucket backups --key "$KEY" --query ContentLength --output text)
echo "completed object size = $SZ (expect 10485760)"
echo "=== 6. presigned-URL expiry ==="
URL=$(AWS s3 presign "s3://backups/$KEY" --expires-in 3)
sleep 5
CODE=$(docker run --rm --network "$NET" curlimages/curl:latest -s -o /dev/null -w '%{http_code}' "$URL" 2>/dev/null)
echo "GET after 5s on 3s-TTL presigned URL -> HTTP $CODE (expect 403)"
URL2=$(AWS s3 presign "s3://backups/$KEY" --expires-in 120)
CODE2=$(docker run --rm --network "$NET" curlimages/curl:latest -s -o /dev/null -w '%{http_code}' "$URL2" 2>/dev/null)
echo "GET on fresh 120s presigned URL -> HTTP $CODE2 (expect 200)"
rm -f "$SCRATCH"/part*.bin "$SCRATCH/mp.json"
