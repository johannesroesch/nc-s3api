# Code Signing for the Nextcloud App Store

The Nextcloud App Store requires every app release to include an
`appinfo/signature.json` signed with a key registered at
[apps.nextcloud.com](https://apps.nextcloud.com).

## One-time setup

### 1 — Generate a key-pair

```bash
# Private key (keep this secret, never commit it)
openssl genrsa -out nc_s3api.key 4096

# Self-signed certificate (valid 10 years)
openssl req -new -x509 -days 3650 -key nc_s3api.key -out nc_s3api.crt \
    -subj "/CN=nc_s3api/O=johannesroesch/C=DE"
```

### 2 — Register the certificate with Nextcloud

Go to **https://apps.nextcloud.com/account/certificates** and upload `nc_s3api.crt`.

### 3 — Store the private key safely

- **Never** commit `nc_s3api.key` to the repository.
- For CI/CD: store it as a GitHub Actions secret (`NC_SIGNING_KEY`) and write
  it to a temp file in the workflow.

---

## Signing a release

Use the helper script:

```bash
./scripts/sign-release.sh /path/to/nextcloud/occ
```

This will:
1. Run `occ integrity:sign-app` → writes `appinfo/signature.json`
2. Build the release tarball (without dev files)
3. Optionally GPG-sign the tarball

Commit `appinfo/signature.json` together with the release tag:

```bash
git add appinfo/signature.json
git commit -m "chore: add signature for v0.1.0"
git tag v0.1.0
git push --tags
```

Then upload the tarball at **https://apps.nextcloud.com/developer/apps/releases/new**.

---

## Automating signing in GitHub Actions

Add these secrets to the repository:
- `NC_SIGNING_KEY` — contents of `nc_s3api.key` (base64-encoded)
- `NC_SIGNING_CERT` — contents of `nc_s3api.crt`

Then add a release job to `.github/workflows/ci.yml`:

```yaml
  release:
    if: startsWith(github.ref, 'refs/tags/v')
    runs-on: ubuntu-latest
    needs: unit-tests
    steps:
      - uses: actions/checkout@v4
      - name: Install Nextcloud (for occ)
        run: |
          wget -q https://download.nextcloud.com/server/releases/latest.zip
          unzip -q latest.zip
      - name: Write signing credentials
        run: |
          echo "${{ secrets.NC_SIGNING_KEY }}"  | base64 -d > nc_s3api.key
          echo "${{ secrets.NC_SIGNING_CERT }}" | base64 -d > nc_s3api.crt
      - name: Sign and package
        run: ./scripts/sign-release.sh nextcloud/occ
      - name: Upload release asset
        uses: softprops/action-gh-release@v2
        with:
          files: "../nc_s3api-*.tar.gz"
```
