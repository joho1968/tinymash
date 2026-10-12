<?php
declare(strict_types=1);

namespace app\classes;

require_once __DIR__ . '/TinyMashInstallerProbeService.php';

final class TinyMashInstallerEntryPoint {
    public static function handle(string $root): never {
        self::headers();
        $access = new TinyMashInstallerAccessService($root, $_SERVER);
        $context = $access->context();
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = explode('?', $uri, 2)[0];
        $setup = new TinyMashSetupService($root);
        foreach (['.tinymash-install-token', '.tinymash-install-origin'] as $name) {
            if (in_array($path, [$context['mount'] . '/' . $name, '/' . $name], true)) {
                self::reply(404, 'Not found.');
            }
        }
        if ($uri === $context['mount'] . '/install/probe-response') {
            try {
                if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($_POST['probe'] ?? null)
                    || $setup->inspectState()['state'] !== 'fresh') {
                    self::reply(404, 'Not found.');
                }
                $probe = new TinyMashInstallerProbeService($root, $access, $_SERVER);
                self::reply(200, $probe->response($_POST['probe']));
            } catch (\Throwable) {
                self::reply(404, 'Not found.');
            }
        }
        $view = self::viewDefaults($context['mount']);
        if ($setup->inspectState()['state'] !== 'fresh') {
            self::render($root, $view, 503);
        }
        try {
            $access->readToken();
        } catch (\Throwable) {
            self::render($root, $view, 503);
        }
        if (!in_array($uri, [$context['mount'] . '/install', $context['mount'] . '/install/setup'], true)) {
            $view['setup_link'] = true;
            self::render($root, $view, 503);
        }
        $access->openSession();
        $view['csrf'] = $access->csrf();
        $view['state'] = 'token';
        $view['title'] = 'Set up tinymash';
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        if (!in_array($method, ['GET', 'POST'], true)) {
            self::reply(405, 'Method not allowed.');
        }
        if ($method === 'POST') {
            try {
                $access->assertPost($_SERVER, $_POST);
            } catch (\Throwable) {
                $view['message'] = 'The form expired or the request was refused. Enter the token again.';
                self::render($root, $view, 403);
            }
        }
        if ($uri === $context['mount'] . '/install') {
            if ($method === 'POST') {
                try {
                    if (!$access->authorize($_SERVER, $_POST)) {
                        $view['message'] = 'The setup token was not accepted.';
                        self::render($root, $view, 403);
                    }
                } catch (\Throwable $exception) {
                    $limited = $exception->getCode() === 429;
                    if ($limited) { header('Retry-After: 900'); }
                    $view['message'] = $limited ? 'Too many attempts. Try again in 15 minutes.' : 'Setup access is unavailable. Check the host setup files and permissions.';
                    self::render($root, $view, $limited ? 429 : 503);
                }
                self::preflight($root, $setup, $access, new TinyMashInstallerProbeService($root, $access, $_SERVER));
                header('Location: ' . $context['mount'] . '/install/setup', true, 303);
                exit;
            }
            self::render($root, $view);
        }
        try {
            $access->requireAuthorization();
        } catch (\Throwable) {
            $view['message'] = 'The setup session expired. Enter the token again.';
            self::render($root, $view, 403);
        }
        $view['state'] = 'setup';
        $view['title'] = 'Create your site';
        $view['values'] = $setup->getDefaults();
        $view['values']['base_url'] = $context['origin'] . $context['mount'];
        $view['checks'] = is_array($_SESSION['preflight_checks'] ?? null) ? $_SESSION['preflight_checks'] : [];
        $view['preflight_ok'] = $view['checks'] !== [] && !in_array(false, $view['checks'], true);
        if (!$view['preflight_ok']) {
            $view['message'] = self::hostingHelp($context['origin']);
        }
        $view['csrf'] = $access->csrf();
        if ($method === 'GET') {
            self::render($root, $view);
        }
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!in_array($action, ['check', 'create'], true)) {
            self::reply(400, 'Invalid request.');
        }
        $defaults = $view['values'];
        foreach (['site_name', 'base_url', 'language', 'timezone', 'admin_user', 'admin_email'] as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value) || strlen($value) > 4096) {
                $view['errors'][$field] = 'Enter a shorter value.';
                continue;
            }
            $view['values'][$field] = $value;
            try {
                $normalized = $setup->normalizeValues(array_replace($defaults, [$field => $value]));
                $view['values'][$field] = $normalized[$field];
            } catch (\InvalidArgumentException $exception) {
                $view['errors'][$field] = $exception->getMessage();
            }
        }
        if ($view['values']['base_url'] !== $defaults['base_url']) {
            $view['errors']['base_url'] = 'Use this installation address. CLI setup can configure a different address.';
            $view['values']['base_url'] = $defaults['base_url'];
        }
        if (!in_array($_POST['is_public'] ?? null, ['0', '1'], true)) {
            $view['errors']['is_public'] = 'Choose public or private visibility.';
        } else {
            $view['values']['is_public'] = $_POST['is_public'] === '1';
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if ($action === 'create') {
            try {
                if (strlen($password) > 4096) { throw new \InvalidArgumentException('Use a shorter password.'); }
                $setup->validatePassword($password);
            } catch (\InvalidArgumentException $exception) {
                $view['errors']['password'] = $exception->getMessage();
            }
            if (!is_string($_POST['password_confirm'] ?? null) || $password !== $_POST['password_confirm']) {
                $view['errors']['password_confirm'] = 'The passwords must match.';
            }
            if ($view['errors'] !== []) {
                self::render($root, $view, 422);
            }
        } else {
            $view['errors'] = [];
        }
        $probe = new TinyMashInstallerProbeService($root, $access, $_SERVER);
        $view['checks'] = self::preflight($root, $setup, $access, $probe);
        $view['preflight_ok'] = !in_array(false, $view['checks'], true);
        $view['csrf'] = $access->csrf();
        $view['message'] = $view['preflight_ok'] ? '' : self::hostingHelp($context['origin']);
        if ($action === 'check' || !$view['preflight_ok']) {
            self::render($root, $view, $view['preflight_ok'] ? 200 : 503);
        }
        $token = $access->requireAuthorization();
        $origin = $access->readOrigin();
        $committed = false;
        $started = false;
        $cleanup = [];
        try {
            $setup->save($view['values'], $password, false,
                function () use ($access, $probe, $token, &$started): void {
                    $probe->assertProof();
                    if ($access->requireAuthorization()['fingerprint'] !== $token['fingerprint']) {
                        throw new \RuntimeException('Installer authorization changed.');
                    }
                    $started = true;
                },
                function () use ($access, $token, $origin, &$committed, &$cleanup): void {
                    $committed = true;
                    $cleanup = $access->finish($token, $origin);
                }
            );
        } catch (\Throwable) {
            if ($committed || ($started && $setup->hasConfiguration())) {
                $view['state'] = 'complete';
                $view['title'] = 'Site created — check cleanup';
                $view['message'] = 'Setup could not verify every final step. Inspect app/config/tinymash.json and users/ on the host before signing in.';
                $view['cleanup'] = array_unique([...$cleanup, '.tinymash-install-token', '.tinymash-install-origin']);
                self::render($root, $view, 200);
            }
            if ($setup->hasConfiguration()) {
                self::reply(404, 'Not found.');
            }
            if ($setup->inspectState()['state'] !== 'fresh') {
                $view['state'] = 'unavailable';
                $view['title'] = 'Setup needs attention';
                $view['message'] = 'Setup stopped before completion. Preserve the existing files and inspect the installation on the host.';
                self::render($root, $view, 503);
            }
            $view['message'] = 'Setup could not finish. Check host permissions, then try again. Re-enter the passwords.';
            self::render($root, $view, 503);
        }
        if ($cleanup !== []) {
            $view['state'] = 'complete';
            $view['title'] = 'Site created — finish cleanup';
            $view['cleanup'] = $cleanup;
            self::render($root, $view);
        }
        header('Location: ' . $context['mount'] . '/admin/login', true, 303);
        exit;
    }

    private static function preflight(string $root, TinyMashSetupService $setup, TinyMashInstallerAccessService $access, TinyMashInstallerProbeService $probe): array {
        $checks = $setup->preflight()['checks'];
        $checks['Dependencies'] = is_file($root . '/vendor/autoload.php');
        $checks['Bundled styles'] = is_readable($root . '/public/ext/bs/css/bootstrap.min.css') && is_readable($root . '/public/css/tinymash.css');
        try {
            $checks += $setup->testWriteCapabilities();
        } catch (\Throwable) {
            $checks['Create, rename, and delete capability'] = false;
        }
        try {
            $probe->run();
            $checks['Private hosting and setup files'] = true;
        } catch (\Throwable) {
            $checks['Private hosting and setup files'] = false;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $access->openSession();
        }
        $access->requireAuthorization();
        $_SESSION['preflight_checks'] = $checks;
        return $checks;
    }

    private static function hostingHelp(string $origin): string {
        return 'Check host permissions and private-file protection. Set .tinymash-install-origin beside the token to ' . $origin . '. Use public as the web root. HTTPS self-requests must reach this installation.';
    }

    private static function viewDefaults(string $mount): array {
        return [
            'mount' => $mount, 'state' => 'unavailable', 'title' => 'Site unavailable', 'message' => '', 'setup_link' => false,
            'csrf' => '', 'values' => [], 'errors' => [], 'checks' => [], 'preflight_ok' => false, 'cleanup' => [],
            'fields' => [
                'site_name' => ['label' => 'Site name', 'type' => 'text', 'group' => 'site', 'max' => 255, 'autocomplete' => 'organization', 'help' => 'The name shown on the site.'],
                'base_url' => ['label' => 'Site address', 'type' => 'url', 'group' => 'site', 'max' => 2048, 'autocomplete' => 'url', 'help' => 'This installation address is verified during setup.'],
                'language' => ['label' => 'Language', 'type' => 'text', 'group' => 'site', 'max' => 35, 'autocomplete' => 'off', 'help' => 'A language tag, such as en or sv-SE.'],
                'timezone' => ['label' => 'Timezone', 'type' => 'text', 'group' => 'site', 'max' => 64, 'autocomplete' => 'off', 'help' => 'Used to display site dates and times.'],
                'admin_user' => ['label' => 'Username', 'type' => 'text', 'group' => 'admin', 'max' => 64, 'autocomplete' => 'username', 'help' => 'Lowercase letters, numbers, and underscores.'],
                'admin_email' => ['label' => 'E-mail (optional)', 'type' => 'email', 'group' => 'admin', 'max' => 254, 'autocomplete' => 'email', 'help' => 'Used for administrator contact.'],
                'password' => ['label' => 'Password', 'type' => 'password', 'group' => 'admin', 'max' => 1024, 'autocomplete' => 'new-password', 'help' => 'Use at least 8 characters.'],
                'password_confirm' => ['label' => 'Confirm password', 'type' => 'password', 'group' => 'admin', 'max' => 1024, 'autocomplete' => 'new-password', 'help' => 'Enter the same password again.'],
            ],
            'timezones' => timezone_identifiers_list(),
        ];
    }

    private static function render(string $root, array $view, int $status = 200): never {
        if (!is_readable($root . '/vendor/autoload.php') || !is_readable($root . '/app/views/installer/setup.latte')
            || !is_readable($root . '/app/views/installer/layout.latte')) {
            throw new \RuntimeException('Installer dependencies are unavailable.');
        }
        require_once $root . '/vendor/autoload.php';
        $view['nonce'] = base64_encode(random_bytes(24));
        $cookie = is_string($_COOKIE['tinymashScreen'] ?? null) ? $_COOKIE['tinymashScreen'] : 'auto';
        $view['screen_mode'] = $cookie === 'dark' ? 'dark' : 'light';
        $view['screen_auto'] = !in_array($cookie, ['light', 'dark'], true);
        $latte = new \Latte\Engine();
        $html = $latte->renderToString($root . '/app/views/installer/setup.latte', $view);
        self::headers();
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'nonce-" . $view['nonce'] . "'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        http_response_code($status);
        echo $html;
        exit;
    }

    public static function headers(): void {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    }

    private static function reply(int $status, string $body): never {
        http_response_code($status);
        echo $body;
        exit;
    }
}
