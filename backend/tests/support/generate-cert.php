<?php
/**
 * Generates the self-signed certificate used by the mock AI server.
 *
 *   php tests/support/generate-cert.php /tmp/haryanvi-mock-ai
 *
 * Uses PHP's openssl extension, which the project already requires, so no
 * `openssl` command-line binary is needed. The certificate is for
 * 127.0.0.1/localhost and is only ever trusted by the test harness via
 * CURL_CA_BUNDLE — it is never installed anywhere system-wide.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "The openssl PHP extension is required.\n");
    exit(1);
}

$directory = $argv[1] ?? sys_get_temp_dir() . '/haryanvi-mock-ai';

if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, 'Could not create ' . $directory . "\n");
    exit(1);
}

$certPath = $directory . '/mock-ai.crt';
$keyPath  = $directory . '/mock-ai.key';

if (is_file($certPath) && is_file($keyPath)) {
    echo $certPath . "\n";
    exit(0);
}

// subjectAltName is mandatory: modern TLS clients ignore the legacy CN.
$configPath = $directory . '/openssl.cnf';
file_put_contents($configPath, <<<CNF
[req]
distinguished_name = dn
x509_extensions    = ext
prompt             = no

[dn]
CN = 127.0.0.1
O  = Haryanvi AI Test Fixture

[ext]
subjectAltName   = IP:127.0.0.1, DNS:localhost
basicConstraints = critical, CA:TRUE
keyUsage         = critical, digitalSignature, keyEncipherment, keyCertSign
CNF);

$privateKey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'config'           => $configPath,
]);

if ($privateKey === false) {
    fwrite(STDERR, "Key generation failed: " . openssl_error_string() . "\n");
    exit(1);
}

$csr = openssl_csr_new(
    ['commonName' => '127.0.0.1', 'organizationName' => 'Haryanvi AI Test Fixture'],
    $privateKey,
    ['config' => $configPath, 'digest_alg' => 'sha256']
);

if ($csr === false) {
    fwrite(STDERR, "CSR generation failed: " . openssl_error_string() . "\n");
    exit(1);
}

$certificate = openssl_csr_sign($csr, null, $privateKey, 30, [
    'config'     => $configPath,
    'digest_alg' => 'sha256',
    'x509_extensions' => 'ext',
]);

if ($certificate === false) {
    fwrite(STDERR, "Certificate signing failed: " . openssl_error_string() . "\n");
    exit(1);
}

openssl_x509_export_to_file($certificate, $certPath);
openssl_pkey_export_to_file($privateKey, $keyPath, null, ['config' => $configPath]);

chmod($keyPath, 0600);
chmod($certPath, 0644);
unlink($configPath);

echo $certPath . "\n";
