<?php
declare(strict_types=1);

namespace app\classes;

require_once __DIR__ . '/TinyMashInstallerAccessService.php';
require_once __DIR__ . '/TinyMashSetupService.php';

final class TinyMashInstallerProbeService {
    public function __construct(private string $root, private TinyMashInstallerAccessService $access, private array $server) {}

    public function hosting(): array {
        $context = $this->access->context();
        $origin = $this->access->readOrigin();
        $public = $this->root . '/public';
        foreach ([$public, (string) ($this->server['DOCUMENT_ROOT'] ?? ''), (string) ($this->server['SCRIPT_FILENAME'] ?? '')] as $path) {
            if ($path === '' || !str_starts_with($path, $this->root . '/')) {
                throw new \RuntimeException('Hosting mapping cannot be verified. Use host CLI setup.');
            }
            $this->access->assertSafePath($path);
        }
        if (realpath((string) $this->server['DOCUMENT_ROOT']) !== $public
            || realpath((string) $this->server['SCRIPT_FILENAME']) !== $public . '/index.php') {
            throw new \RuntimeException('Hosting mapping cannot be verified. Use host CLI setup.');
        }
        $address = (string) ($this->server['SERVER_ADDR'] ?? $this->server['LOCAL_ADDR'] ?? '');
        // The development server omits SERVER_ADDR; its listening name must be a literal loopback address.
        if ($address === '' && PHP_SAPI === 'cli-server' && in_array($context['host'], ['127.0.0.1', '[::1]'], true)) {
            $address = trim($context['host'], '[]');
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('Server address cannot be verified. Use host CLI setup.');
        }
        $literal = trim($context['host'], '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false && inet_pton($literal) !== inet_pton($address)) {
            throw new \RuntimeException('Server address does not match the origin.');
        }
        return ['address' => $address, 'mapping' => hash('sha256', $public . '|' . $this->server['SCRIPT_NAME'] . '|' . $address . '|' . $context['port'] . '|' . $origin['fingerprint'])];
    }

    public function run(): array {
        $token = $this->access->requireAuthorization();
        $hosting = $this->hosting();
        if (!extension_loaded('curl') || (new TinyMashSetupService($this->root))->inspectState()['state'] !== 'fresh') {
            throw new \RuntimeException('Browser setup checks are unavailable. Use host CLI setup.');
        }
        $lock = $this->access->lockRuntime('probe-run');
        $request = bin2hex(random_bytes(32));
        $response = bin2hex(random_bytes(32));
        $binding = $this->access->binding($token);
        $expires = time() + 60;
        try {
            unset($_SESSION['proof']);
            if (!session_write_close()) {
                throw new \RuntimeException('Installer session cannot be saved.');
            }
            $this->access->withState('probe', function (?array $state) use ($request, $response, $binding, $expires): array {
                $this->validateState($state);
                return [['request' => $request, 'response' => $response, 'binding' => $binding, 'expires' => $expires], null];
            });
            $deadline = microtime(true) + 15;
            $context = $this->access->context();
            $control = $this->fetch($context['mount'] . '/install/probe-response', $request, $hosting, $deadline);
            if ($control['status'] !== 200 || !hash_equals($response, $control['body'])) {
                throw new \RuntimeException('The installation could not verify its own response. Use host CLI setup.');
            }
            $paths = array_unique([$context['mount'] . '/.tinymash-install-token', '/.tinymash-install-token']);
            foreach ($paths as $path) {
                $result = $this->fetch($path, null, $hosting, $deadline);
                if (!in_array($result['status'], [403, 404], true) || $result['body'] === $token['raw'] || $result['body'] === $token['value']) {
                    throw new \RuntimeException('Token protection could not be verified. Use host CLI setup.');
                }
            }
            $this->access->openSession();
            $current = $this->access->requireAuthorization();
            if ($this->access->binding($current) !== $binding || $this->hosting() !== $hosting || time() >= $expires
                || (new TinyMashSetupService($this->root))->inspectState()['state'] !== 'fresh') {
                throw new \RuntimeException('Installer state changed during verification.');
            }
            $proof = ['binding' => $binding, 'mapping' => $hosting['mapping'], 'expires' => $expires];
            $_SESSION['proof'] = $proof;
            return $proof;
        } finally {
            try {
                $this->access->withState('probe', function (?array $state) use ($request): array {
                    $this->validateState($state);
                    return [$state !== null && ($state['request'] ?? null) === $request ? [] : ($state ?? []), null];
                });
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function assertProof(): void {
        $token = $this->access->requireAuthorization();
        $hosting = $this->hosting();
        $proof = $_SESSION['proof'] ?? null;
        if (!is_array($proof) || ($proof['binding'] ?? null) !== $this->access->binding($token)
            || ($proof['mapping'] ?? null) !== $hosting['mapping'] || !is_int($proof['expires'] ?? null)
            || $proof['expires'] <= time() || $proof['expires'] > time() + 60) {
            throw new \RuntimeException('Installer verification expired.');
        }
    }

    public function response(string $request): string {
        if (preg_match('/^[a-f0-9]{64}$/D', $request) !== 1) {
            throw new \RuntimeException('Probe access was not found.', 404);
        }
        $token = $this->access->readToken();
        $state = $this->access->readProbeState();
        $this->validateState($state);
        if ($state === null || $state === [] || $state['expires'] <= time() || $state['expires'] > time() + 60
            || $state['binding'] !== $this->access->binding($token) || !hash_equals($state['request'], $request)) {
            throw new \RuntimeException('Probe access was not found.', 404);
        }
        return $state['response'];
    }

    private function validateState(?array $state): void {
        if ($state === null || $state === []) {
            return;
        }
        if (array_keys($state) !== ['request', 'response', 'binding', 'expires'] || !is_int($state['expires'])) {
            throw new \RuntimeException('Installer probe state is unavailable.');
        }
        foreach (['request', 'response', 'binding'] as $key) {
            if (!is_string($state[$key]) || preg_match('/^[a-f0-9]{64}$/D', $state[$key]) !== 1) {
                throw new \RuntimeException('Installer probe state is unavailable.');
            }
        }
    }

    private function fetch(string $path, ?string $secret, array $hosting, float $deadline): array {
        $context = $this->access->context();
        $remaining = (int) floor(($deadline - microtime(true)) * 1000);
        if ($remaining <= 0 || $this->hosting() !== $hosting) {
            throw new \RuntimeException('Installer verification timed out.');
        }
        $body = '';
        $curl = curl_init($context['origin'] . $path);
        if ($curl === false) {
            throw new \RuntimeException('Installer transport is unavailable.');
        }
        $options = [
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(2000, $remaining), CURLOPT_TIMEOUT_MS => min(5000, $remaining),
            CURLOPT_HTTPHEADER => ['Accept: text/plain', 'Expect:'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (filter_var(trim($context['host'], '[]'), FILTER_VALIDATE_IP) === false) {
            $address = str_contains($hosting['address'], ':') ? '[' . $hosting['address'] . ']' : $hosting['address'];
            $options[CURLOPT_RESOLVE] = [$context['host'] . ':' . $context['port'] . ':' . $address];
        }
        if ($secret !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query(['probe' => $secret]);
        }
        try {
            if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
                throw new \RuntimeException('Installer transport could not verify hosting. Use host CLI setup.');
            }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally {
            curl_close($curl);
        }
    }
}
