<?php
declare(strict_types=1);

namespace app\classes;

require_once __DIR__ . '/TinyMashFilesystem.php';

final class TinyMashInstallerAccessService {
    private string $root;
    private string $runtime;
    private array $context;

    public function __construct(string $root, array $server) {
        $this->root = rtrim($root, '/');
        $this->assertSafePath($this->root);
        if (realpath($this->root) !== $this->root) {
            throw new \RuntimeException('Installer root is unavailable.');
        }
        $this->runtime = $this->root . '/data/runtime/installer';
        $this->context = self::requestContext($server);
        $this->context['installation'] = hash('sha256', $this->root);
    }

    public function context(): array {
        return $this->context;
    }

    public static function requestContext(array $server): array {
        $secure = in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true);
        $scheme = $secure ? 'https' : 'http';
        $authority = (string) ($server['HTTP_HOST'] ?? '');
        if (preg_match('/^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+)(?::([0-9]{1,5}))?$/D', $authority, $match) !== 1) {
            throw new \RuntimeException('Installer origin is unavailable.');
        }
        $host = strtolower($match[1]);
        $port = isset($match[2]) ? (int) $match[2] : ($secure ? 443 : 80);
        if ($port < 1 || $port > 65535 || str_contains($host, '..') || str_ends_with($host, '.')) {
            throw new \RuntimeException('Installer origin is unavailable.');
        }
        $literal = trim($host, '[]');
        if (str_starts_with($host, '[') && filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException('Installer origin is unavailable.');
        }
        $loopback = in_array($literal, ['127.0.0.1', '::1', 'localhost'], true);
        if (!$secure && !$loopback) {
            throw new \RuntimeException('Browser setup requires HTTPS. Use host CLI setup.');
        }
        $script = (string) ($server['SCRIPT_NAME'] ?? '');
        if (!str_ends_with($script, '/index.php')) {
            throw new \RuntimeException('Installer mount is unavailable.');
        }
        $mount = substr($script, 0, -10);
        if ($mount !== '' && (preg_match('#^(?:/[a-zA-Z0-9._~-]+)+$#D', $mount) !== 1
            || in_array('.', explode('/', $mount), true) || in_array('..', explode('/', $mount), true))) {
            throw new \RuntimeException('Installer mount is unavailable.');
        }
        $peer = (string) ($server['REMOTE_ADDR'] ?? '');
        if (filter_var($peer, FILTER_VALIDATE_IP) === false
            || (!$secure && (!in_array($peer, ['127.0.0.1', '::1'], true)
                || (isset($server['SERVER_ADDR']) && !in_array($server['SERVER_ADDR'], ['127.0.0.1', '::1'], true))))) {
            throw new \RuntimeException('Installer peer is unavailable.');
        }
        $origin = $scheme . '://' . $host . ($port === ($secure ? 443 : 80) ? '' : ':' . $port);
        return compact('secure', 'host', 'port', 'mount', 'origin', 'peer');
    }

    /** @return array{raw: string, value: string, fingerprint: string} */
    public function readToken(): array {
        $file = $this->readHostFile('.tinymash-install-token', 258);
        $value = preg_replace('/(?:\r\n|\n|\r)\z/', '', $file['raw'], 1);
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') < 1
            || mb_strlen($value, 'UTF-8') > 64 || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $value) === 1 || mb_trim($value) === '') {
            throw new \RuntimeException('Installer token is unavailable.');
        }
        return $file + ['value' => $value];
    }

    public function readOrigin(): array {
        $file = $this->readHostFile('.tinymash-install-origin', 1024);
        $value = preg_replace('#/$#D', '', trim($file['raw'], " \t\r\n"));
        if ($value !== $this->context['origin']) {
            throw new \RuntimeException('The host origin does not match this request.');
        }
        return $file + ['value' => $value];
    }

    private function readHostFile(string $name, int $limit): array {
        if (!in_array($name, ['.tinymash-install-token', '.tinymash-install-origin'], true)) {
            throw new \InvalidArgumentException('Unknown installer host file.');
        }
        $filename = $this->root . '/' . $name;
        $handle = $this->openSafeFile($filename, false);
        try {
            $stat = fstat($handle);
            $raw = stream_get_contents($handle, $limit + 1);
            if (!is_array($stat) || !is_string($raw) || strlen($raw) !== $stat['size'] || strlen($raw) > $limit) {
                throw new \RuntimeException('Installer host file is unavailable.');
            }
            $this->assertFileIdentity($filename, $handle);
            rewind($handle);
            if (stream_get_contents($handle, $limit + 1) !== $raw) {
                throw new \RuntimeException('Installer host file changed.');
            }
            $fingerprint = hash('sha256', implode(':', [$stat['dev'], $stat['ino'], $stat['ctime'], $stat['mtime'], $stat['size'], hash('sha256', $raw)]));
            return compact('raw', 'fingerprint');
        } finally {
            fclose($handle);
        }
    }

    public function binding(array $token): string {
        return hash('sha256', $this->context['installation'] . '|' . $this->context['origin'] . '|' . $this->context['mount'] . '|' . $token['fingerprint']);
    }

    public function openSession(): void {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new \RuntimeException('Installer session is unavailable.');
        }
        $this->ensureRuntime();
        $directory = $this->runtime . '/sessions';
        $this->ensureDirectory($directory);
        $token = $this->readToken();
        $name = 'tm_install_' . substr($this->context['installation'], 0, 16);
        @session_name($name);
        @session_save_path($directory);
        @ini_set('session.save_handler', 'files');
        if (session_name() !== $name || session_save_path() !== $directory || ini_get('session.save_handler') !== 'files') {
            throw new \RuntimeException('Installer session is unavailable.');
        }
        $options = [
            'save_handler' => 'files', 'use_strict_mode' => 1, 'use_only_cookies' => 1, 'use_trans_sid' => 0,
            'cookie_lifetime' => 0, 'cookie_path' => $this->context['mount'] . '/install',
            'cookie_domain' => '', 'cookie_secure' => $this->context['secure'], 'cookie_httponly' => true,
            'cookie_samesite' => 'Lax', 'gc_maxlifetime' => 1800, 'cache_limiter' => 'nocache',
        ];
        foreach ($options as $key => $value) {
            @ini_set('session.' . $key, is_bool($value) ? (string) (int) $value : (string) $value);
        }
        $cookie = session_get_cookie_params();
        if ((int) ini_get('session.use_strict_mode') !== 1 || (int) ini_get('session.use_only_cookies') !== 1
            || (int) ini_get('session.use_trans_sid') !== 0 || !$cookie['httponly'] || $cookie['domain'] !== ''
            || $cookie['path'] !== $options['cookie_path'] || ($this->context['secure'] && !$cookie['secure'])
            || !in_array(strtolower($cookie['samesite']), ['lax', 'strict'], true)) {
            throw new \RuntimeException('Installer session is unavailable.');
        }
        if (!session_start()) {
            throw new \RuntimeException('Installer session is unavailable.');
        }
        $binding = $this->binding($token);
        if (($_SESSION['binding'] ?? null) !== $binding || !$this->sessionIsCurrent()) {
            $_SESSION = ['binding' => $binding, 'created' => time(), 'seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
            if (!session_regenerate_id(true)) {
                throw new \RuntimeException('Installer session is unavailable.');
            }
        }
        $_SESSION['seen'] = time();
    }

    public function finish(array $token, ?array $origin = null): array {
        $errors = [];
        $files = ['.tinymash-install-token' => $token];
        if ($origin !== null) {
            $files['.tinymash-install-origin'] = $origin;
        }
        foreach ($files as $name => $expected) {
            try {
                if ($this->readHostFile($name, 1024)['fingerprint'] !== $expected['fingerprint'] || !@unlink($this->root . '/' . $name)) {
                    $errors[] = $name;
                }
            } catch (\Throwable) {
                $errors[] = $name;
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (!session_destroy()) {
                $errors[] = 'data/runtime/installer/sessions/';
            }
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        return $errors;
    }

    private function sessionIsCurrent(): bool {
        $now = time();
        return is_int($_SESSION['created'] ?? null) && is_int($_SESSION['seen'] ?? null)
            && $_SESSION['created'] <= $_SESSION['seen'] && $_SESSION['seen'] <= $now
            && $_SESSION['created'] > $now - 1800 && $_SESSION['seen'] > $now - 900
            && is_string($_SESSION['csrf'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf']) === 1;
    }

    public function csrf(): string {
        if (session_status() !== PHP_SESSION_ACTIVE || !$this->sessionIsCurrent()) {
            throw new \RuntimeException('Installer session expired.');
        }
        return $_SESSION['csrf'];
    }

    public function assertPost(array $server, array $post): void {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($post['csrf'] ?? null)
            || !hash_equals($this->csrf(), $post['csrf'])) {
            throw new \RuntimeException('Installer request was refused.', 403);
        }
        if (isset($server['HTTP_SEC_FETCH_SITE']) && !in_array($server['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true)) {
            throw new \RuntimeException('Installer request was refused.', 403);
        }
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
            if (!array_key_exists($header, $server)) {
                continue;
            }
            $url = (string) $server[$header];
            // No-referrer makes native form POSTs use an opaque origin; CSRF is still mandatory.
            if ($header === 'HTTP_ORIGIN' && $url === 'null') {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                || !isset($parts['scheme'], $parts['host']) || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
                throw new \RuntimeException('Installer request was refused.', 403);
            }
            $scheme = strtolower($parts['scheme']);
            $origin = $scheme . '://' . strtolower($parts['host']);
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            if ($port !== ($scheme === 'https' ? 443 : 80)) {
                $origin .= ':' . $port;
            }
            if ($origin !== $this->context['origin'] || ($header === 'HTTP_ORIGIN' && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])))) {
                throw new \RuntimeException('Installer request was refused.', 403);
            }
        }
    }

    public function authorize(array $server, array $post): bool {
        $this->assertPost($server, $post);
        $token = $this->readToken();
        if (($_SESSION['binding'] ?? null) !== $this->binding($token)) {
            throw new \RuntimeException('Installer token changed.', 403);
        }
        $candidate = is_string($post['token'] ?? null) ? $post['token'] : '';
        $correct = strlen($candidate) <= 256 && hash_equals($token['value'], $candidate);
        $allowed = $this->withState('attempts', function (?array $state) use ($token, $correct): array {
            $now = time();
            if ($state !== null) {
                $this->validateAttempts($state);
            }
            if ($state === null || $state['fingerprint'] !== $token['fingerprint']) {
                $state = ['fingerprint' => $token['fingerprint'], 'global' => [], 'peers' => []];
            }
            $state['global'] = array_values(array_filter($state['global'], static fn(int $stamp): bool => $stamp > $now - 900));
            foreach ($state['peers'] as $peer => $stamps) {
                $stamps = array_values(array_filter($stamps, static fn(int $stamp): bool => $stamp > $now - 900));
                if ($stamps === []) {
                    unset($state['peers'][$peer]);
                } else {
                    $state['peers'][$peer] = $stamps;
                }
            }
            $peer = hash('sha256', $this->context['peer']);
            if (count($state['global']) >= 30 || count($state['peers'][$peer] ?? []) >= 5) {
                throw new \RuntimeException('Installer attempts are limited.', 429);
            }
            if (!$correct) {
                if (!isset($state['peers'][$peer]) && count($state['peers']) >= 128) {
                    throw new \RuntimeException('Installer attempt state is full.');
                }
                $state['global'][] = $now;
                $state['peers'][$peer][] = $now;
            }
            return [$state, $correct];
        });
        if ($allowed) {
            if (!session_regenerate_id(true)) {
                throw new \RuntimeException('Installer session is unavailable.');
            }
            $_SESSION = ['binding' => $this->binding($token), 'created' => time(), 'seen' => time(), 'csrf' => bin2hex(random_bytes(32)), 'authorized' => true];
        }
        return $allowed;
    }

    public function requireAuthorization(): array {
        $token = $this->readToken();
        if (session_status() !== PHP_SESSION_ACTIVE || !$this->sessionIsCurrent()
            || ($_SESSION['authorized'] ?? false) !== true || ($_SESSION['binding'] ?? null) !== $this->binding($token)) {
            throw new \RuntimeException('Installer authorization expired.', 403);
        }
        return $token;
    }

    private function validateAttempts(array $state): void {
        if (array_keys($state) !== ['fingerprint', 'global', 'peers'] || !is_string($state['fingerprint'])
            || preg_match('/^[a-f0-9]{64}$/D', $state['fingerprint']) !== 1 || !is_array($state['global'])
            || !array_is_list($state['global']) || count($state['global']) > 30 || !is_array($state['peers']) || count($state['peers']) > 128) {
            throw new \RuntimeException('Installer attempt state is unavailable.');
        }
        foreach (['global' => $state['global'], ...$state['peers']] as $peer => $stamps) {
            if (($peer !== 'global' && preg_match('/^[a-f0-9]{64}$/D', (string) $peer) !== 1)
                || !is_array($stamps) || !array_is_list($stamps) || count($stamps) > ($peer === 'global' ? 30 : 5)) {
                throw new \RuntimeException('Installer attempt state is unavailable.');
            }
            foreach ($stamps as $stamp) {
                if (!is_int($stamp) || $stamp < 0 || $stamp > time()) {
                    throw new \RuntimeException('Installer attempt state is unavailable.');
                }
            }
        }
    }

    public function readProbeState(): ?array {
        $filename = $this->runtime . '/probe.json';
        $this->assertSafePath($filename);
        if (!file_exists($filename)) {
            return null;
        }
        $lock = $this->lockRuntime('probe');
        try {
            return $this->readStateFile($filename);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readStateFile(string $filename): ?array {
        $this->assertSafePath($filename);
        if (!file_exists($filename)) {
            return null;
        }
        $handle = $this->openSafeFile($filename, false);
        try {
            $raw = stream_get_contents($handle, 65537);
            if (!is_string($raw) || strlen($raw) > 65536) {
                throw new \RuntimeException('Installer state is unavailable.');
            }
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \RuntimeException('Installer state is unavailable.');
            }
            $this->assertFileIdentity($filename, $handle);
            return $state;
        } finally {
            fclose($handle);
        }
    }

    public function withState(string $name, callable $change): mixed {
        if (!in_array($name, ['attempts', 'probe'], true)) {
            throw new \InvalidArgumentException('Unknown installer state.');
        }
        $lock = $this->lockRuntime($name);
        $filename = $this->runtime . '/' . $name . '.json';
        try {
            $state = $this->readStateFile($filename);
            [$next, $result] = $change($state);
            $json = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > 65536) {
                throw new \RuntimeException('Installer state is too large.');
            }
            $this->assertFileIdentity($this->runtime . '/' . $name . '.lock', $lock);
            if ($this->readStateFile($filename) !== $state) {
                throw new \RuntimeException('Installer state changed.');
            }
            $mask = umask(0077);
            try {
                if (!TinyMashFilesystem::writeFileAtomically($filename, $json)) {
                    throw new \RuntimeException('Installer state cannot be saved.');
                }
            } finally {
                umask($mask);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    public function lockRuntime(string $name) {
        if (!in_array($name, ['attempts', 'probe', 'probe-run'], true)) {
            throw new \InvalidArgumentException('Unknown installer lock.');
        }
        $this->ensureRuntime();
        $handle = $this->openSafeFile($this->runtime . '/' . $name . '.lock', true);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Installer is busy.');
        }
        return $handle;
    }

    private function ensureRuntime(): void {
        foreach ([$this->root . '/data', $this->root . '/data/runtime', $this->runtime] as $directory) {
            $this->ensureDirectory($directory);
        }
    }

    private function ensureDirectory(string $directory): void {
        $this->assertSafePath($directory);
        if (!is_dir($directory) && !@mkdir($directory, 0700)) {
            throw new \RuntimeException('Installer runtime is unavailable.');
        }
        $this->assertSafePath($directory);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Installer runtime is unavailable.');
        }
        if (str_starts_with($directory, $this->runtime) && (fileperms($directory) & 0077) !== 0) {
            throw new \RuntimeException('Installer runtime must be private.');
        }
    }

    public function assertSafePath(string $path): void {
        if ($path !== $this->root && !str_starts_with($path, $this->root . '/')) {
            throw new \RuntimeException('Installer path is outside the installation.');
        }
        $current = $this->root;
        clearstatcache(true, $current);
        if (is_link($current)) {
            throw new \RuntimeException('Installer path is unsafe.');
        }
        foreach (explode('/', substr($path, strlen($this->root))) as $part) {
            if ($part === '') {
                continue;
            }
            if ($part === '.' || $part === '..') {
                throw new \RuntimeException('Installer path is unsafe.');
            }
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new \RuntimeException('Installer path is unsafe.');
            }
        }
    }

    /** @return resource */
    private function openSafeFile(string $filename, bool $create) {
        $this->assertSafePath($filename);
        $stat = @lstat($filename);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1)) {
            throw new \RuntimeException('Installer file is unsafe.');
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($filename, $stat === false && $create ? 'x+b' : ($create ? 'r+b' : 'rb'));
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new \RuntimeException('Installer file is unavailable.');
        }
        try {
            $this->assertFileIdentity($filename, $handle);
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
        return $handle;
    }

    private function assertFileIdentity(string $filename, $handle): void {
        $this->assertSafePath($filename);
        $path = @lstat($filename);
        $opened = fstat($handle);
        if (!is_array($path) || !is_array($opened) || ($path['mode'] & 0170000) !== 0100000 || $path['nlink'] !== 1
            || $opened['nlink'] !== 1 || $path['dev'] !== $opened['dev'] || $path['ino'] !== $opened['ino']) {
            throw new \RuntimeException('Installer file changed.');
        }
    }
}
