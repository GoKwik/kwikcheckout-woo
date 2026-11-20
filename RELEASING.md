# Releasing GoKwik Checkout

The plugin installs an update only when the update manifest names a package on
this repository's GitHub releases page and the package matches the manifest's
`sha256` checksum and Ed25519 `signature`. Until a public key is set in the
plugin, automatic updates are refused.

## One-time setup (release owner)

1. Generate a signing keypair on a trusted machine (PHP 7.2+ with sodium):

   ```bash
   php -r '$kp = sodium_crypto_sign_keypair();
     file_put_contents("gokwik-update-signing.secret", base64_encode(sodium_crypto_sign_secretkey($kp)));
     echo "Public key: ", base64_encode(sodium_crypto_sign_publickey($kp)), PHP_EOL;'
   ```

2. Store the contents of `gokwik-update-signing.secret` in the release owner's
   secret store (password manager or CI secret), then delete the local file.
   **Never commit the private key to this repository.**

3. Set `GOKWIK_UPDATER_PUBLIC_KEY` in `includes/class-gokwik-updater.php` to the
   printed public key and ship it in a release. Sites must install that release
   manually once, because earlier versions cannot verify signatures.

## Every release

1. Build the release zip (`gokwik-checkout.zip`) and note the version, e.g. `1.2.4`.

2. Compute the checksum and signature. The signature covers
   `<slug>:<version>:<sha256 hex>`:

   ```bash
   export GOKWIK_SIGNING_KEY="<base64 private key from the secret store>"
   php -r '$v = "1.2.4"; $f = "gokwik-checkout.zip";
     $h = hash_file("sha256", $f);
     $sig = sodium_crypto_sign_detached("gokwik-checkout:$v:$h", base64_decode(getenv("GOKWIK_SIGNING_KEY")));
     echo "sha256: $h", PHP_EOL, "signature: ", base64_encode($sig), PHP_EOL;'
   unset GOKWIK_SIGNING_KEY
   ```

3. Upload the zip as an asset on the GitHub release for that version. The
   manifest `download_url` must start with
   `https://github.com/GoKwik/kwikcheckout-woo/releases/download/`.

4. Update the manifest with `version`, `download_url`, `sha256` and `signature`,
   then publish it.

5. On a staging site, run **Dashboard > Updates** and confirm the update installs.
   A mismatch shows "GoKwik Checkout update refused" and an admin notice.

## Rotating the key

Ship a release signed with the old key that contains the new public key, then
sign later releases with the new key.

## Tests

```bash
php tests/test-updater-integrity.php
```
