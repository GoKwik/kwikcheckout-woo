<?php
/**
 * Standalone tests for the update package allow-list and signature checks.
 *
 * Run: php tests/test-updater-integrity.php
 *
 * A throwaway Ed25519 keypair is generated at run time. No private key is
 * stored in the repository.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../includes/class-gokwik-updater.php';

use Gokwik_Checkout\Inc\GokwikUpdater;

$failures = 0;
$count = 0;

function check($name, $condition)
{
    global $failures, $count;
    $count++;
    if ($condition) {
        echo "ok   - {$name}\n";
    } else {
        $failures++;
        echo "FAIL - {$name}\n";
    }
}

// Allow-list.
$base = 'https://github.com/GoKwik/kwikcheckout-woo/releases/download/';
check('accepts release asset URL', GokwikUpdater::is_allowed_package_url($base . 'v1.2.4/gokwik-checkout.zip'));
check('rejects bare prefix', !GokwikUpdater::is_allowed_package_url($base));
check('rejects http', !GokwikUpdater::is_allowed_package_url('http://github.com/GoKwik/kwikcheckout-woo/releases/download/v1/a.zip'));
check('rejects other host', !GokwikUpdater::is_allowed_package_url('https://example.com/GoKwik/kwikcheckout-woo/releases/download/v1/a.zip'));
check('rejects lookalike host', !GokwikUpdater::is_allowed_package_url('https://github.com.example.com/GoKwik/kwikcheckout-woo/releases/download/v1/a.zip'));
check('rejects userinfo', !GokwikUpdater::is_allowed_package_url('https://github.com@example.com/GoKwik/kwikcheckout-woo/releases/download/v1/a.zip'));
check('rejects other repo', !GokwikUpdater::is_allowed_package_url('https://github.com/GoKwik/other-repo/releases/download/v1/a.zip'));
check('rejects path traversal', !GokwikUpdater::is_allowed_package_url($base . '../../../other/releases/download/v1/a.zip'));
check('rejects encoded path', !GokwikUpdater::is_allowed_package_url($base . '%2e%2e/a.zip'));
check('rejects query string', !GokwikUpdater::is_allowed_package_url($base . 'v1/a.zip?x=1'));
check('rejects port', !GokwikUpdater::is_allowed_package_url('https://github.com:8443/GoKwik/kwikcheckout-woo/releases/download/v1/a.zip'));
check('rejects backslash', !GokwikUpdater::is_allowed_package_url($base . 'v1\\a.zip'));
check('rejects non-string', !GokwikUpdater::is_allowed_package_url(null));

// Signing and verification with a throwaway keypair.
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = base64_encode(sodium_crypto_sign_publickey($keypair));
$other_public = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

$zip = tempnam(sys_get_temp_dir(), 'gkzip');
file_put_contents($zip, random_bytes(4096));
$sha = hash_file('sha256', $zip);
$sig = base64_encode(sodium_crypto_sign_detached(GokwikUpdater::build_signed_message('gokwik-checkout', '1.2.4', $sha), $secret));
sodium_memzero($secret);

check('valid package verifies', true === GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $sha, $sig, $public));
check('uppercase sha256 accepted', true === GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', strtoupper($sha), $sig, $public));
check('empty public key fails closed', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $sha, $sig, ''));
check('wrong public key rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $sha, $sig, $other_public));
check('different version rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '9.9.9', $sha, $sig, $public));
check('wrong sha256 rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', str_repeat('0', 64), $sig, $public));
check('malformed signature rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $sha, 'not-base64!!', $public));
check('missing file rejected', true !== GokwikUpdater::verify_package($zip . '.missing', 'gokwik-checkout', '1.2.4', $sha, $sig, $public));

file_put_contents($zip, 'x', FILE_APPEND);
check('modified package rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $sha, $sig, $public));
$tampered_sha = hash_file('sha256', $zip);
check('modified package with updated sha256 rejected', true !== GokwikUpdater::verify_package($zip, 'gokwik-checkout', '1.2.4', $tampered_sha, $sig, $public));
unlink($zip);

// Manifest validation.
$manifest = (object) [
    'version' => '1.2.4',
    'download_url' => $base . 'v1.2.4/gokwik-checkout.zip',
    'sha256' => $sha,
    'signature' => $sig,
];
check('complete manifest accepted', true === GokwikUpdater::validate_manifest($manifest, $public));
check('manifest refused when key empty', true !== GokwikUpdater::validate_manifest($manifest, ''));
check('shipped key placeholder fails closed', '' !== GokwikUpdater::GOKWIK_UPDATER_PUBLIC_KEY || true !== GokwikUpdater::validate_manifest($manifest, GokwikUpdater::GOKWIK_UPDATER_PUBLIC_KEY));
$m = clone $manifest; $m->download_url = 'https://example.com/a.zip';
check('manifest with disallowed URL rejected', true !== GokwikUpdater::validate_manifest($m, $public));
$m = clone $manifest; unset($m->sha256);
check('manifest without sha256 rejected', true !== GokwikUpdater::validate_manifest($m, $public));
$m = clone $manifest; $m->sha256 = 'abc';
check('manifest with bad sha256 rejected', true !== GokwikUpdater::validate_manifest($m, $public));
$m = clone $manifest; $m->signature = '';
check('manifest without signature rejected', true !== GokwikUpdater::validate_manifest($m, $public));
check('non-object manifest rejected', true !== GokwikUpdater::validate_manifest(false, $public));

echo "\n" . ($count - $failures) . "/{$count} passed\n";
exit($failures ? 1 : 0);
