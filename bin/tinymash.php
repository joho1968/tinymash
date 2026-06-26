#!/usr/bin/php8.4
<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80401) {
    fwrite(STDERR, "tinymash CLI requires PHP 8.4.1 or newer. You are running PHP " . PHP_VERSION . ".\n");
    exit(1);
}

if (!defined('TINYMASH_RUNNING')) {
    define('TINYMASH_RUNNING', true);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/globals.inc.php';
require_once __DIR__ . '/../app/classes/TinyMashConfig.php';
require_once __DIR__ . '/../app/classes/TinyMashConfigIO.php';
require_once __DIR__ . '/../app/classes/TinyMashSecurity.php';
require_once __DIR__ . '/../app/classes/TinyMashPluginCapabilityRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashPlugins.php';
require_once __DIR__ . '/../app/classes/TinyMashContentRepository.php';
require_once __DIR__ . '/../app/classes/TinyMashContentTargetPickerService.php';
require_once __DIR__ . '/../app/classes/TinyMashDraftRepository.php';
require_once __DIR__ . '/../app/classes/TinyMashMarkdownRenderer.php';
require_once __DIR__ . '/../app/classes/TinyMashDateFormatter.php';
require_once __DIR__ . '/../app/classes/TinyMashContentRenderer.php';
require_once __DIR__ . '/../app/classes/TinyMashShortcodeRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashMenuService.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaAttachmentMetadataStore.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaImportMapStore.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaService.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaImportBridge.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaUsageReporter.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaCapabilityRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaDerivativeRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashLockedJsonFile.php';
require_once __DIR__ . '/../app/classes/TinyMashImportLockService.php';
require_once __DIR__ . '/../app/classes/TinyMashPublicPageCache.php';
require_once __DIR__ . '/../app/classes/TinyMashHousekeepingService.php';
require_once __DIR__ . '/../app/classes/TinyMashThemeRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashThemeSupportRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashTheme.php';
require_once __DIR__ . '/../app/classes/TinyMashHelpCatalog.php';
require_once __DIR__ . '/../app/classes/TinyMashUserRepository.php';
require_once __DIR__ . '/../app/classes/TinyMashExportService.php';
require_once __DIR__ . '/../app/classes/TinyMashDeployService.php';
require_once __DIR__ . '/../app/classes/TinyMashMailer.php';
require_once __DIR__ . '/../app/classes/TinyMashNotificationService.php';
require_once __DIR__ . '/../app/classes/TinyMashPasswordResetService.php';
require_once __DIR__ . '/../app/classes/TinyMashComponentsReportService.php';

use app\classes\TinyMashConfig;
use app\classes\TinyMashConfigIO;
use app\classes\TinyMashSecurity;
use app\classes\TinyMashPluginCapabilityRegistry;
use app\classes\TinyMashPlugins;
use app\classes\TinyMashContentRepository;
use app\classes\TinyMashContentTargetPickerService;
use app\classes\TinyMashDraftRepository;
use app\classes\TinyMashMarkdownRenderer;
use app\classes\TinyMashDateFormatter;
use app\classes\TinyMashContentRenderer;
use app\classes\TinyMashShortcodeRegistry;
use app\classes\TinyMashMenuService;
use app\classes\TinyMashMediaAttachmentMetadataStore;
use app\classes\TinyMashMediaImportMapStore;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashMediaUsageReporter;
use app\classes\TinyMashMediaCapabilityRegistry;
use app\classes\TinyMashMediaDerivativeRegistry;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashHousekeepingService;
use app\classes\TinyMashThemeRegistry;
use app\classes\TinyMashThemeSupportRegistry;
use app\classes\TinyMashTheme;
use app\classes\TinyMashHelpCatalog;
use app\classes\TinyMashUserRepository;
use app\classes\TinyMashExportService;
use app\classes\TinyMashDeployService;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashPasswordResetService;
use app\classes\TinyMashComponentsReportService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

const TINYMASH_USERS_DIR = __DIR__ . '/../users';
const TINYMASH_CACHE_DIR = __DIR__ . '/../data/runtime/latte-cache';
const TINYMASH_CONTENT_DIR = __DIR__ . '/../data/content';
const TINYMASH_DRAFTS_DIR = __DIR__ . '/../data/drafts';
const TINYMASH_MEDIA_DIR = __DIR__ . '/../data/media';
const TINYMASH_APP_PLUGINS_DIR = __DIR__ . '/../app/plugins';
const TINYMASH_PLUGINS_DIR = __DIR__ . '/../plugins';
const TINYMASH_RUNTIME_DIR = __DIR__ . '/../data/runtime';
const TINYMASH_COMPONENTS_REPORT = TINYMASH_RUNTIME_DIR . '/components.json';
const TINYMASH_PUBLIC_PAGE_CACHE_DIR = TINYMASH_RUNTIME_DIR . '/public-page-cache';
const TINYMASH_DEPLOY_MANIFEST = __DIR__ . '/../app/config/deploy-manifest.php';

function fail(string $message, int $exit_code = 1): never {
    fwrite(STDERR, 'tinymash: ' . $message . PHP_EOL);
    exit($exit_code);
}

function printCliTitle(string $title): void {
    fwrite(STDOUT, $title . PHP_EOL);
    fwrite(STDOUT, str_repeat('=', strlen($title)) . PHP_EOL);
}

function printCliSection(string $title): void {
    fwrite(STDOUT, PHP_EOL . $title . PHP_EOL);
    fwrite(STDOUT, str_repeat('-', strlen($title)) . PHP_EOL);
}

function printCliRows(array $rows): void {
    if ($rows === []) {
        return;
    }

    $label_width = 0;
    foreach ($rows as $label => $value) {
        $label_width = max($label_width, strlen((string) $label));
    }

    foreach ($rows as $label => $value) {
        fwrite(STDOUT, '  ' . str_pad((string) $label, $label_width) . '  ' . (string) $value . PHP_EOL);
    }
}

function printCliTasks(array $tasks): void {
    if ($tasks === []) {
        fwrite(STDOUT, '  None' . PHP_EOL);
        return;
    }

    $status_width = 0;
    $label_width = 0;
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        $status_width = max($status_width, strlen((string) ($task['status'] ?? 'unknown')));
        $label_width = max($label_width, strlen((string) ($task['label'] ?? 'Task')));
    }

    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        $status = (string) ($task['status'] ?? 'unknown');
        $label = (string) ($task['label'] ?? 'Task');
        $message = trim((string) ($task['message'] ?? ''));
        fwrite(
            STDOUT,
            '  [' . str_pad($status, $status_width) . '] '
            . str_pad($label, $label_width)
            . ($message !== '' ? '  ' . $message : '')
            . PHP_EOL
        );
    }
}

function isEffectiveRootUser(): bool {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return true;
    }
    if (function_exists('posix_getegid') && posix_getegid() === 0) {
        return true;
    }

    return false;
}

function stdinIsInteractive(): bool {
    if (function_exists('stream_isatty')) {
        return stream_isatty(STDIN);
    }
    if (function_exists('posix_isatty')) {
        return posix_isatty(STDIN);
    }

    return false;
}

function stripGlobalCliOptions(array $arguments): array {
    $allow_root = false;
    $filtered_arguments = [];

    foreach ($arguments as $argument) {
        if ($argument === '--allow-root') {
            $allow_root = true;
            continue;
        }
        $filtered_arguments[] = $argument;
    }

    return [
        'arguments' => $filtered_arguments,
        'allow_root' => $allow_root,
    ];
}

function commandIsReadOnlyForRootGuard(array $arguments): bool {
    $command = (string) ($arguments[0] ?? '');
    if ($command === '' || in_array($command, ['help', '--help', '-h'], true)) {
        return true;
    }

    $action = (string) ($arguments[1] ?? '');
    if ($command === 'system') {
        return in_array($action, ['status', 'plugins'], true);
    }
    if ($command === 'maintenance') {
        return $action === 'status';
    }
    if ($command === 'housekeeping') {
        return $action === 'status';
    }
    if ($command === 'media') {
        if ($action === 'usage') {
            return true;
        }
        if ($action === 'cleanup') {
            return in_array('--report-unused', $arguments, true)
                || in_array('--dry-run', $arguments, true)
                || !in_array('--generate-missing-derivatives', $arguments, true);
        }
    }
    if ($command === 'benchmark') {
        return $action === 'public';
    }
    if ($command === 'audit') {
        return $action === 'remote-media';
    }
    if ($command === 'user') {
        return $action === 'list';
    }

    return false;
}

function guardRootUserForCliCommand(array $arguments, bool $allow_root): void {
    if (!isEffectiveRootUser()) {
        return;
    }

    $warning = 'tinymash: warning: running as root can leave runtime files owned by root; use the tinymash user when possible';
    if (commandIsReadOnlyForRootGuard($arguments)) {
        fwrite(STDERR, $warning . PHP_EOL);
        return;
    }

    if ($allow_root) {
        fwrite(STDERR, $warning . PHP_EOL);
        fwrite(STDERR, 'tinymash: --allow-root supplied; continuing' . PHP_EOL);
        return;
    }

    if (!stdinIsInteractive()) {
        fail('refusing to run a mutating command as root without confirmation; use the tinymash user or pass --allow-root');
    }

    fwrite(STDERR, $warning . PHP_EOL);
    fwrite(STDERR, 'Continue as root? [y/N] ');
    $answer = fgets(STDIN);
    if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
        fail('aborted', 2);
    }
}

function readUsers(): array {
    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
    return ['users' => $repository->getAllUsers()];
}

function writeUsers(array $data): void {
    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
    foreach (($data['users'] ?? []) as $user) {
        if (!is_array($user) || empty($user['username'])) {
            continue;
        }
        $repository->saveUser(
            [
                'original_username' => (string) ($user['username'] ?? ''),
                'username' => (string) ($user['username'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'role' => (string) ($user['role'] ?? 'creator'),
                'password' => '',
                'active' => !empty($user['active']),
                'content_active' => array_key_exists('content_active', $user) ? !empty($user['content_active']) : true,
            ]
        );
    }
}

function showHelp(): void {
    $help = <<<TEXT
tinymash
========

Usage
-----
  tinymash [--allow-root] <command> [options]

Global option
-------------
  --allow-root
      allow mutating commands to run as root without an interactive confirmation

Runtime
-------
  help
      show this help

  system status
      show a small runtime summary

  system plugins
      show plugin boot diagnostics

  maintenance status
      show current maintenance mode

  maintenance on
      enable maintenance mode in config

  maintenance off
      disable maintenance mode in config

Cache and housekeeping
----------------------
  cache clear
      remove compiled Latte cache files, the persistent content index cache, and cached public page responses

  cache warm [--base-url=<url>] [--userpass=<user:pass>] [--author=<slug>] [--entries] [--entry-limit=<n>]
      rebuild the persistent content index cache, prune expired cached public page responses, and proactively warm public listing pages

  housekeeping status
      show housekeeping policy and last-run state

  housekeeping run [--no-plugins]
      run core housekeeping now, and optionally active plugin housekeeping tasks

Media
-----
  media usage [--owner=<root-or-author>] [--unused-only] [--limit=<n>]
      report stored media usage from content, drafts, site/profile settings, and known media IDs in plugin settings

  media cleanup [--dry-run] [--generate-missing-derivatives] [--report-unused] [--owner=<root-or-author>] [--limit=<n>]
      report likely unused media or generate missing display/lightbox derivatives without rewriting originals

Inspection
----------
  benchmark public [--base-url=<url>] [--userpass=<user:pass>] [--login-user=<username>] [--login-pass=<password>] [--author=<slug>] [--entry=<slug>] [--repeat=<n>]
      fetch public URLs with curl and show timing summaries for root, author home, page 2, and an optional entry; can also benchmark the logged-in public path

  audit remote-media [--author=<slug>] [--include-unpublished]
      scan stored content for remote image URLs and report likely import misses versus intentional external embeds

  check-updates [--notify]
      run composer outdated/audit, write a cached components report, and optionally send notification e-mail

Transfer
--------
  deploy <target-directory>
      build a deployable runtime tree from the explicit include-only deploy manifest

  export site <target-directory> [--with-plugins]
      export config, users, content, drafts, and media into a portable directory

  export author <username> <target-directory> [--with-plugins]
      export one author profile plus owned content, drafts, and media

  import site <source-directory> [--replace-existing] [--with-plugins]
      import a site export bundle into the current runtime

  import author <source-directory> <new-password> [--replace-existing] [--with-plugins]
      import one author export bundle and assign a new login password

Users
-----
  user list
      list local tinymash users from users/*.json

  user set-password <username> <password> [role]
      create or update a local tinymash user
TEXT;

    fwrite(STDOUT, $help . PHP_EOL);

    $plugin_commands = getPluginCliCommands();
    if ($plugin_commands === []) {
        return;
    }

    fwrite(STDOUT, PHP_EOL . "Plugin commands" . PHP_EOL);
    fwrite(STDOUT, "---------------" . PHP_EOL);
    foreach ($plugin_commands as $plugin_command) {
        if (!is_array($plugin_command)) {
            continue;
        }

        $usage = trim((string) ($plugin_command['usage'] ?? ''));
        $summary = trim((string) ($plugin_command['summary'] ?? ''));
        if ($usage === '') {
            continue;
        }

        fwrite(STDOUT, '  ' . $usage . PHP_EOL);
        if ($summary !== '') {
            fwrite(STDOUT, '      ' . $summary . PHP_EOL);
        }
    }
}

function getPluginCliCommands(): array {
    if (!is_file(dirname(__DIR__) . '/app/config/tinymash.json')) {
        return [];
    }

    try {
        $runtime = buildCliRuntime(true);
    } catch (\Throwable) {
        return [];
    }

    $plugins = $runtime['plugins'] ?? null;
    if (!$plugins instanceof TinyMashPlugins) {
        return [];
    }

    return $plugins->getCliCommands();
}

function printSystemStatus(): void {
    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    $users = readUsers();
    $content_repository = new TinyMashContentRepository(
        TINYMASH_CONTENT_DIR,
        $config->getContentRevisionRetentionLimit()
    );
    $content_stats = $content_repository->getContentStats();
    $cache_files = glob(TINYMASH_CACHE_DIR . '/*');
    $cache_count = is_array($cache_files) ? count($cache_files) : 0;

    $active_users = 0;
    foreach ($users['users'] as $user) {
        if (is_array($user) && !empty($user['active'])) {
            $active_users++;
        }
    }

    $system_settings = $config->getSystemSettings();
    $base_url = rtrim((string) $config->configGetBaseURL(), '/');
    $public_url_check = 'skipped';
    if (empty($system_settings['site_public'])) {
        $public_url_check = 'skipped - site is not public';
    } elseif (!function_exists('curl_init')) {
        $public_url_check = 'skipped - curl extension unavailable';
    } elseif ($base_url === '') {
        $public_url_check = 'skipped - base URL unavailable';
    } else {
        $userpass = '';
        $username = trim((string) ($system_settings['public_cache_warm_basic_auth_username'] ?? ''));
        if ($username !== '') {
            $userpass = $username . ':' . (string) ($system_settings['public_cache_warm_basic_auth_password'] ?? '');
        }
        $allow_insecure_tls = !empty($system_settings['public_cache_warm_insecure_tls']);
        $public_url_result = fetchPublicUrlForWarm($base_url . '/', $userpass, $allow_insecure_tls);
        $http_code = (int) ($public_url_result['http_code'] ?? 0);
        $error = trim((string) ($public_url_result['error'] ?? ''));
        $public_url_check = $error !== ''
            ? ('failed - ' . $base_url . '/ -> ' . $error)
            : ('HTTP ' . $http_code . ' - ' . $base_url . '/');
    }

    printCliTitle('tinymash system status');
    printCliSection('Site');
    printCliRows([
        'Version' => APP_VERSION,
        'Base URL' => $config->configGetBaseURL() ?: '-',
        'Admin URL' => $config->configGetAdminURL() ?: '-',
        'Login URL' => $config->configGetLoginURL() ?: '-',
        'Maintenance' => $config->getMaintenance() ? 'on' : 'off',
    ]);
    printCliSection('Content');
    printCliRows([
        'Active users' => $active_users,
        'Entries' => $content_stats['entries'],
        'Posts' => $content_stats['posts'],
        'Pages' => $content_stats['pages'],
        'Authors' => $content_stats['authors'],
    ]);
    printCliSection('Cache');
    printCliRows([
        'Compiled files' => $cache_count,
        'Warm auth' => trim((string) ($system_settings['public_cache_warm_basic_auth_username'] ?? '')) !== '' ? 'configured' : 'off',
        'Insecure TLS' => !empty($system_settings['public_cache_warm_insecure_tls']) ? 'on' : 'off',
        'Public URL check' => $public_url_check,
    ]);
}

function printSystemPluginDiagnostics(): void {
    $runtime = buildCliRuntime(true);
    $plugins = $runtime['plugins'] ?? null;
    if (!$plugins instanceof TinyMashPlugins || !method_exists($plugins, 'getPluginDiagnostics')) {
        fail('plugin runtime is unavailable');
    }

    $diagnostics = $plugins->getPluginDiagnostics();
    $summary = is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : [];
    $registered_plugins = is_array($diagnostics['plugins'] ?? null) ? $diagnostics['plugins'] : [];

    printCliTitle('tinymash plugin diagnostics');
    printCliSection('Summary');
    printCliRows([
        'Installed' => (int) ($summary['total'] ?? 0),
        'Active' => (int) ($summary['active'] ?? 0),
        'Inactive' => (int) ($summary['inactive'] ?? 0),
        'Booted' => (int) ($summary['booted'] ?? 0),
        'Ready' => (int) ($summary['ready'] ?? 0),
        'Errors' => (int) ($summary['error'] ?? 0),
        'Warnings' => (int) ($summary['warnings'] ?? 0),
    ]);

    printCliSection('Plugins');
    if ($registered_plugins === []) {
        fwrite(STDOUT, '  None' . PHP_EOL);
        return;
    }

    foreach ($registered_plugins as $plugin) {
        if (!is_array($plugin)) {
            continue;
        }
        $key = (string) ($plugin['key'] ?? '');
        $name = (string) ($plugin['name'] ?? $key);
        $status = (string) ($plugin['boot_status_cli'] ?? $plugin['boot_status'] ?? 'unknown');
        $stage = (string) ($plugin['stage'] ?? 'early');
        $booted_stage = trim((string) ($plugin['booted_stage'] ?? ''));
        $message = trim((string) ($plugin['boot_error'] ?? ''));
        $warnings = is_array($plugin['manifest_warnings'] ?? null) ? $plugin['manifest_warnings'] : [];
        $line = '  ' . $status . '  ' . $key . '  ' . $name . '  stage=' . $stage;
        if ($booted_stage !== '') {
            $line .= ' booted=' . $booted_stage;
        }
        if ($message !== '') {
            $line .= '  error=' . $message;
        }
        if ($warnings !== []) {
            $line .= '  warnings=' . count($warnings);
        }
        fwrite(STDOUT, $line . PHP_EOL);
        foreach ($warnings as $warning) {
            fwrite(STDOUT, '    warning: ' . trim((string) $warning) . PHP_EOL);
        }
    }
}

function printMaintenanceStatus(): void {
    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    printCliTitle('tinymash maintenance status');
    printCliRows([ 'Status' => $config->getMaintenance() ? 'on' : 'off' ]);
}

function setMaintenance(bool $flag): void {
    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    $config->setMaintenance($flag);
    if (!$config->putConfig()) {
        fail('unable to write config file');
    }

    printCliTitle('tinymash maintenance');
    printCliRows([ 'Status' => $flag ? 'on' : 'off' ]);
}

function clearCache(): void {
    $cache_files = glob(TINYMASH_CACHE_DIR . '/*');
    if (!is_array($cache_files)) {
        fail('unable to read cache directory');
    }

    $removed = 0;
    foreach ($cache_files as $cache_file) {
        if (!is_file($cache_file)) {
            continue;
        }
        if (!unlink($cache_file)) {
            fail('unable to remove cache file "' . basename($cache_file) . '"');
        }
        $removed++;
    }

    $content_repository = new TinyMashContentRepository(TINYMASH_CONTENT_DIR);
    $content_cache_removed = $content_repository->clearPersistentCache();
    $media_metadata_store = new TinyMashMediaAttachmentMetadataStore(TINYMASH_MEDIA_DIR);
    $media_metadata_cache_removed = $media_metadata_store->clearPersistentCache();
    $public_page_cache = new TinyMashPublicPageCache(TINYMASH_PUBLIC_PAGE_CACHE_DIR);
    $public_page_cache_removed = $public_page_cache->clear();
    $rendered_content_cache_removed = clearDirectoryFiles(TINYMASH_RUNTIME_DIR . '/rendered-content');

    printCliTitle('tinymash cache clear');
    printCliRows([
        'Compiled files removed' => $removed,
        'Content indexes removed' => $content_cache_removed,
        'Media indexes removed' => $media_metadata_cache_removed,
        'Public pages removed' => $public_page_cache_removed,
        'Rendered entries removed' => $rendered_content_cache_removed,
    ]);
}

function clearDirectoryFiles(string $directory): int {
    if (!is_dir($directory) || !is_readable($directory)) {
        return 0;
    }

    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*');
    if (!is_array($files)) {
        return 0;
    }

    $removed = 0;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        if (@unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

function warmCache(array $arguments = []): void {
    $runtime = buildCliRuntime(false);
    $result = $runtime['content_repository']->warmPersistentCache();
    $media_result = $runtime['media_metadata_store']->warmPersistentCache();
    $public_page_cache = new TinyMashPublicPageCache(TINYMASH_PUBLIC_PAGE_CACHE_DIR);
    $public_page_cache_pruned = $public_page_cache->pruneExpired();
    $warm_options = parseWarmCacheOptions($arguments);
    $public_warm_result = warmPublicPageCache(
        $runtime,
        $warm_options['base_url'],
        $warm_options['userpass'],
        $warm_options['author'],
        $warm_options['include_entries'],
        $warm_options['entry_limit'],
        true
    );

    printCliTitle('tinymash cache warm');
    printCliSection('Content index');
    printCliRows([
        'Entries' => (int) ($result['entries'] ?? 0),
        'Published entries' => (int) ($result['published_entries'] ?? 0),
        'Authors' => (int) ($result['authors'] ?? 0),
        'Aggregated posts' => (int) ($result['aggregated_posts'] ?? 0),
        'File' => $result['cache_file'] ?? '-',
    ]);
    printCliSection('Media index');
    printCliRows([
        'Items' => (int) ($media_result['items'] ?? 0),
        'File' => $media_result['cache_file'] ?? '-',
    ]);
    printCliSection('Public pages');
    $public_page_rows = [
        'Expired removed' => $public_page_cache_pruned,
        'Warmed' => (int) ($public_warm_result['warmed'] ?? 0),
        'Failed' => (int) ($public_warm_result['failed'] ?? 0),
        'Skipped' => (string) ($public_warm_result['skipped_reason'] ?? 'none'),
    ];
    if (($public_warm_result['failure_example'] ?? '') !== '') {
        $public_page_rows['Failure example'] = (string) $public_warm_result['failure_example'];
    }
    printCliRows($public_page_rows);
}

function buildCliRuntime(bool $with_plugins = false): array {
    if (!defined('TINYMASH_RUNNING')) {
        define('TINYMASH_RUNNING', true);
    }

    $app = \Flight::app();
    $app->set('app.url', '');
    $app->set('admin.url', '/admin');
    $app->set('login.url', '/login');

    $router = $app->router();
    $config = new TinyMashConfig($app, $router);
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    $security = new TinyMashSecurity(
        $app,
        $router,
        $config,
        dirname(__DIR__) . '/data/runtime/login-attempts.json'
    );
    $content_repository = new TinyMashContentRepository(
        TINYMASH_CONTENT_DIR,
        $config->getContentRevisionRetentionLimit()
    );
    $content_target_picker = new TinyMashContentTargetPickerService($content_repository);
    $draft_repository = new TinyMashDraftRepository(TINYMASH_DRAFTS_DIR);
    $user_repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
    $markdown_renderer = new TinyMashMarkdownRenderer();
    $date_formatter = new TinyMashDateFormatter(
        $config->getLocaleDateFormat(),
        $config->getLocaleTimeFormat(),
        $config->getLocaleTimezone()
    );
    $media_capability_registry = new TinyMashMediaCapabilityRegistry();
    $media_derivative_registry = new TinyMashMediaDerivativeRegistry();
    $media_metadata_store = new TinyMashMediaAttachmentMetadataStore(TINYMASH_MEDIA_DIR);
    $media_import_map_store = new TinyMashMediaImportMapStore(TINYMASH_MEDIA_DIR . '/import-map.json');
    $media_service = new TinyMashMediaService(
        TINYMASH_MEDIA_DIR,
        $config,
        $media_capability_registry,
        $media_derivative_registry,
        $media_metadata_store
    );
    $media_import_bridge = new TinyMashMediaImportBridge(
        $media_service,
        $media_import_map_store
    );
    $media_usage_reporter = new TinyMashMediaUsageReporter(
        $content_repository,
        $draft_repository,
        $user_repository,
        $config,
        $media_service
    );
    $import_lock_service = new TinyMashImportLockService(
        TINYMASH_RUNTIME_DIR . '/import-locks.json'
    );
    $content_renderer = new TinyMashContentRenderer(
        $markdown_renderer,
        $date_formatter,
        $config,
        $user_repository,
        $media_service,
        TINYMASH_RUNTIME_DIR . '/rendered-content'
    );
    $shortcode_registry = new TinyMashShortcodeRegistry($config->getUnknownShortcodeMode());
    $help_catalog = new TinyMashHelpCatalog(
        dirname(__DIR__) . '/app/help',
        'en'
    );
    $notification_service = new TinyMashNotificationService(
        TINYMASH_RUNTIME_DIR . '/notifications.json'
    );
    $password_reset_service = new TinyMashPasswordResetService(
        TINYMASH_RUNTIME_DIR . '/password-resets.json',
        $user_repository
    );
    $export_service = new TinyMashExportService(
        $config,
        $user_repository,
        $content_repository,
        $draft_repository,
        $config->getConfigFilename(),
        TINYMASH_USERS_DIR,
        TINYMASH_CONTENT_DIR,
        TINYMASH_DRAFTS_DIR,
        TINYMASH_MEDIA_DIR
    );
    $theme_support_registry = new TinyMashThemeSupportRegistry();
    $theme_registry = new TinyMashThemeRegistry(
        [
            dirname(__DIR__) . '/app/themes/public',
            dirname(__DIR__) . '/themes/public',
        ],
        [
            dirname(__DIR__) . '/app/themes/admin',
            dirname(__DIR__) . '/themes/admin',
        ],
        dirname(__DIR__) . '/public'
    );
    $menu_service = new TinyMashMenuService($config, $content_repository);
    $theme = new TinyMashTheme($app, $content_repository, $config, $theme_registry, $theme_support_registry, $user_repository, $menu_service);

    $app->set('content.repository', $content_repository);
    $app->set('content.target_picker', $content_target_picker);
    $app->set('draft.repository', $draft_repository);
    $app->set('user.repository', $user_repository);
    $app->set('markdown.renderer', $markdown_renderer);
    $app->set('date.formatter', $date_formatter);
    $app->set('content.renderer', $content_renderer);
    $app->set('shortcode.registry', $shortcode_registry);
    $app->set('menu.service', $menu_service);
    $app->set('theme.support_registry', $theme_support_registry);
    $app->set('theme.registry', $theme_registry);
    $app->set('theme', $theme);
    $app->set('media.metadata_store', $media_metadata_store);
    $app->set('media.import_map_store', $media_import_map_store);
    $app->set('media.service', $media_service);
    $app->set('media.import_bridge', $media_import_bridge);
    $app->set('media.usage_reporter', $media_usage_reporter);
    $app->set('import.lock_service', $import_lock_service);
    $app->set('media.capability_registry', $media_capability_registry);
    $app->set('media.derivative_registry', $media_derivative_registry);
    $app->set('help.catalog', $help_catalog);
    $app->set('export.service', $export_service);
    $app->set('notification.service', $notification_service);
    $app->set('password_reset.service', $password_reset_service);
    $app->set('security', $security);

    $plugins = null;
    if ($with_plugins) {
        $capability_registry = new TinyMashPluginCapabilityRegistry();
        $plugins = new TinyMashPlugins($app, $router, $config, $security, $capability_registry);
        $plugins->discoverDirectories([ TINYMASH_APP_PLUGINS_DIR, TINYMASH_PLUGINS_DIR ]);
        $plugins->bootStage('before');
        $plugins->bootStage('early');
        $plugins->bootStage('after');
        $plugins->bootStage('late');
        $content_renderer->setPlugins($plugins);
        $app->set('plugins', $plugins);
        $app->set('plugin.capability_registry', $capability_registry);
    }

    return [
        'app' => $app,
        'router' => $router,
        'config' => $config,
        'security' => $security,
        'content_repository' => $content_repository,
        'content_target_picker' => $content_target_picker,
        'draft_repository' => $draft_repository,
        'user_repository' => $user_repository,
        'menu_service' => $menu_service,
        'theme' => $theme,
        'media_metadata_store' => $media_metadata_store,
        'media_service' => $media_service,
        'media_import_bridge' => $media_import_bridge,
        'media_usage_reporter' => $media_usage_reporter,
        'export_service' => $export_service,
        'notification_service' => $notification_service,
        'password_reset_service' => $password_reset_service,
        'plugins' => $plugins,
    ];
}

function printHousekeepingStatus(): void {
    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    printCliTitle('tinymash housekeeping status');
    printCliRows([
        'Last run UTC' => $config->getHousekeepingLastRunUtc() ?: '-',
        'Last trigger' => $config->getHousekeepingLastTrigger() ?: '-',
        'Last mode' => $config->getHousekeepingLastMode() ?: '-',
        'Stale draft retention' => $config->getHousekeepingDraftRetentionDays() . ' day(s)',
        'Web fallback' => $config->getHousekeepingWebFallbackMode(),
    ]);
}

function runHousekeeping(bool $run_plugins = true): void {
    $config_io = new TinyMashConfigIO();
    if (!$config_io->getConfig()) {
        fail('unable to read config file');
    }

    $runtime = buildCliRuntime($run_plugins);
    $service = new TinyMashHousekeepingService(
        $config_io,
        $runtime['draft_repository'],
        $runtime['media_service'] ?? null,
        $run_plugins ? $runtime['plugins'] : null,
        $runtime['notification_service'] ?? null,
        $runtime['password_reset_service'] ?? null,
        $runtime['theme'] ?? null,
        TINYMASH_RUNTIME_DIR . '/housekeeping-web.lock',
        $runtime['media_import_bridge'] ?? null,
        $runtime['content_repository'] ?? null
    );
    $result = $service->run(
        $run_plugins,
        [
            'run_type' => 'housekeeping',
            'trigger' => 'cli',
            'mode' => 'manual',
        ],
        true
    );

    printCliTitle('tinymash housekeeping run');
    printCliSection('Run');
    printCliRows([
        'Started UTC' => $result['started_at_utc'] ?? '-',
        'State saved' => !empty($result['last_run_saved']) ? 'yes' : 'no',
        'Stale draft retention' => (int) ($result['stale_draft_retention_days'] ?? 0) . ' day(s)',
    ]);

    printCliSection('Core tasks');
    printCliTasks($result['core_tasks'] ?? []);

    if (!empty($result['plugin_tasks'])) {
        printCliSection('Plugin tasks');
        printCliTasks($result['plugin_tasks']);
    }

    if (is_array($result['theme_task'] ?? null) && !empty($result['theme_task'])) {
        printCliSection('Theme task');
        printCliTasks([$result['theme_task']]);
    }

    $cache_result = $runtime['content_repository']->warmPersistentCache();
    $media_cache_result = $runtime['media_metadata_store']->warmPersistentCache();
    $public_page_cache = new TinyMashPublicPageCache(TINYMASH_PUBLIC_PAGE_CACHE_DIR);
    $public_page_cache_pruned = $public_page_cache->pruneExpired();
    $public_warm_result = warmPublicPageCache($runtime, '', '', '', false, 0, false);
    printCliSection('Cache tasks');
    $cache_tasks = [
        [
            'status' => 'warmed',
            'label' => 'Content index',
            'message' => (int) ($cache_result['published_entries'] ?? 0) . ' published entr' . ((int) ($cache_result['published_entries'] ?? 0) === 1 ? 'y' : 'ies'),
        ],
        [
            'status' => 'warmed',
            'label' => 'Media metadata',
            'message' => (int) ($media_cache_result['items'] ?? 0) . ' metadata entr' . ((int) ($media_cache_result['items'] ?? 0) === 1 ? 'y' : 'ies'),
        ],
        [
            'status' => 'pruned',
            'label' => 'Public page cache',
            'message' => $public_page_cache_pruned . ' expired entr' . ($public_page_cache_pruned === 1 ? 'y' : 'ies'),
        ],
    ];
    if (($public_warm_result['skipped_reason'] ?? '') !== '') {
        $cache_tasks[] = [
            'status' => 'skipped',
            'label' => 'Public page warm',
            'message' => (string) $public_warm_result['skipped_reason'],
        ];
    } else {
        $cache_tasks[] = [
            'status' => 'warmed',
            'label' => 'Public page warm',
            'message' => (int) ($public_warm_result['warmed'] ?? 0) . ' page(s), '
                . (int) ($public_warm_result['failed'] ?? 0) . ' failed',
        ];
        if (($public_warm_result['failure_example'] ?? '') !== '') {
            $cache_tasks[] = [
                'status' => 'warning',
                'label' => 'Warm failure example',
                'message' => (string) $public_warm_result['failure_example'],
            ];
        }
    }
    printCliTasks($cache_tasks);
}

function runMediaCleanup(array $arguments): void {
    if (in_array('--report-unused', $arguments, true)) {
        runMediaUsage($arguments, true, 'cleanup-report-unused');
        return;
    }

    $dry_run = !in_array('--generate-missing-derivatives', $arguments, true) || in_array('--dry-run', $arguments, true);
    $owner = '';
    $limit = PHP_INT_MAX;

    foreach ($arguments as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }
        if (str_starts_with($argument, '--owner=')) {
            $owner = strtolower(trim(substr($argument, 8)));
            continue;
        }
        if (str_starts_with($argument, '--limit=')) {
            $limit = max(1, (int) trim(substr($argument, 8)));
        }
    }

    if ($owner === 'all') {
        $owner = '';
    }
    if ($owner !== '' && !preg_match('/^[a-z0-9._-]+$/', $owner)) {
        fail('invalid owner; use root or an author slug');
    }

    $runtime = buildCliRuntime(false);
    $media_service = $runtime['media_service'] ?? null;
    if (!$media_service instanceof TinyMashMediaService) {
        fail('media service unavailable');
    }

    $result = $media_service->backfillMissingDisplayDerivatives(
        $limit,
        $owner !== '' ? [ $owner ] : [],
        $dry_run
    );

    printCliTitle('tinymash media cleanup');
    $cleanup_rows = [
        'Mode' => $dry_run ? 'dry-run' : 'generate missing derivatives',
        'Owner' => $owner !== '' ? $owner : 'all',
        'Checked' => (int) ($result['checked'] ?? 0),
        'Skipped' => (int) ($result['skipped'] ?? 0),
    ];
    if ($dry_run) {
        $cleanup_rows['Would generate'] = (int) ($result['would_generate'] ?? 0);
    } else {
        $cleanup_rows['Generated'] = (int) ($result['generated'] ?? 0);
    }
    printCliRows($cleanup_rows);
}

function runMediaUsage(array $arguments, bool $unused_only = false, string $mode = 'usage'): void {
    $options = parseMediaAuditOptions($arguments);
    if (in_array('--unused-only', $arguments, true)) {
        $unused_only = true;
    }

    $runtime = buildCliRuntime(false);
    $media_service = $runtime['media_service'] ?? null;
    if (!$media_service instanceof TinyMashMediaService) {
        fail('media service unavailable');
    }

    $owner = (string) $options['owner'];
    $media_records = $media_service->listAttachments($owner !== '' ? [ $owner ] : [], PHP_INT_MAX);
    $usage = buildMediaUsageReport($runtime, $media_records, $owner);
    $rows = [];
    foreach (($usage['records'] ?? []) as $record) {
        if (!is_array($record)) {
            continue;
        }
        if ($unused_only && (string) ($record['status'] ?? '') === 'used') {
            continue;
        }
        $rows[] = $record;
    }
    $output_limit = (int) $options['limit'];
    if ($output_limit > 0 && count($rows) > $output_limit) {
        $rows = array_slice($rows, 0, $output_limit);
    }

    printCliTitle('tinymash media usage');
    printCliRows([
        'Mode' => $mode,
        'Owner' => $owner !== '' ? $owner : 'all',
        'Checked' => (int) ($usage['summary']['checked'] ?? 0),
        'Used' => (int) ($usage['summary']['used'] ?? 0),
        'Unreferenced' => (int) ($usage['summary']['unreferenced'] ?? 0),
        'Direct marker only' => (int) ($usage['summary']['direct_marker_only'] ?? 0),
        'Missing references' => (int) ($usage['summary']['missing_references'] ?? 0),
        'Listed' => count($rows),
    ]);

    if ($rows !== []) {
        printCliSection('Media');
    }
    foreach ($rows as $record) {
        fwrite(
            STDOUT,
                '  [' . (string) ($record['status'] ?? 'unknown') . ']'
                . ' id=' . (string) ($record['media_id'] ?? '')
            . ' owner=' . (string) ($record['owner_username'] ?? '')
            . ' refs=' . (int) ($record['reference_count'] ?? 0)
            . ' markers=' . (string) ($record['direct_markers'] ?? '-')
            . ' file=' . formatCliValue((string) ($record['filename'] ?? ''))
            . ' categories=' . formatCliValue((string) ($record['reference_categories'] ?? '-'))
            . PHP_EOL
        );
    }

    if (!empty($usage['missing_references']) && is_array($usage['missing_references'])) {
        printCliSection('Missing references');
        foreach (array_slice($usage['missing_references'], 0, 25) as $missing_reference) {
            if (!is_array($missing_reference)) {
                continue;
            }
            fwrite(
                STDOUT,
                '  source=' . formatCliValue((string) ($missing_reference['source'] ?? ''))
                . ' value=' . formatCliValue((string) ($missing_reference['value'] ?? ''))
                . PHP_EOL
            );
        }
    }
}

function parseMediaAuditOptions(array $arguments): array {
    $owner = '';
    $limit = PHP_INT_MAX;

    foreach ($arguments as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }
        if (str_starts_with($argument, '--owner=')) {
            $owner = strtolower(trim(substr($argument, 8)));
            continue;
        }
        if (str_starts_with($argument, '--limit=')) {
            $limit = max(1, (int) trim(substr($argument, 8)));
        }
    }

    if ($owner === 'all') {
        $owner = '';
    }
    if ($owner !== '' && !preg_match('/^[a-z0-9._-]+$/', $owner)) {
        fail('invalid owner; use root or an author slug');
    }

    return [ 'owner' => $owner, 'limit' => $limit ];
}

function buildMediaUsageReport(array $runtime, array $media_records, string $owner_filter = ''): array {
    $reporter = $runtime['media_usage_reporter'] ?? null;
    if (!$reporter instanceof TinyMashMediaUsageReporter) {
        fail('media usage reporter unavailable');
    }

    return $reporter->buildReport($media_records, $owner_filter);
}

function formatCliValue(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    if (preg_match('/^[A-Za-z0-9_.,:\/@+=-]+$/', $value)) {
        return $value;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function parseWarmCacheOptions(array $arguments): array {
    $options = [
        'base_url' => '',
        'userpass' => '',
        'author' => '',
        'include_entries' => false,
        'entry_limit' => 0,
    ];

    foreach ($arguments as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }

        if (str_starts_with($argument, '--base-url=')) {
            $options['base_url'] = trim(substr($argument, 11));
            continue;
        }
        if (str_starts_with($argument, '--userpass=')) {
            $options['userpass'] = trim(substr($argument, 11));
            continue;
        }
        if (str_starts_with($argument, '--author=')) {
            $options['author'] = trim(substr($argument, 9));
            continue;
        }
        if (str_starts_with($argument, '--entry-limit=')) {
            $options['entry_limit'] = max(0, (int) trim(substr($argument, 14)));
            continue;
        }
        if ($argument === '--entries') {
            $options['include_entries'] = true;
        }
    }

    return $options;
}

function warmPublicPageCache(
    array $runtime,
    string $base_url = '',
    string $userpass = '',
    string $author_filter = '',
    bool $include_entries = false,
    int $entry_limit = 0,
    bool $include_pagination = true
): array {
    $config = $runtime['config'] ?? null;
    $content_repository = $runtime['content_repository'] ?? null;
    if (!$config instanceof TinyMashConfig || !$content_repository instanceof TinyMashContentRepository) {
        return ['warmed' => 0, 'failed' => 0, 'skipped_reason' => 'runtime unavailable'];
    }
    if (!$config->isSitePublic()) {
        return ['warmed' => 0, 'failed' => 0, 'skipped_reason' => 'site is not public'];
    }
    if (!function_exists('curl_init')) {
        return ['warmed' => 0, 'failed' => 0, 'skipped_reason' => 'curl extension unavailable'];
    }

    $resolved_base_url = rtrim($base_url !== '' ? $base_url : (string) $config->configGetBaseURL(), '/');
    if ($resolved_base_url === '') {
        return ['warmed' => 0, 'failed' => 0, 'skipped_reason' => 'base URL unavailable'];
    }
    $resolved_userpass = $userpass !== '' ? $userpass : (method_exists($config, 'getPublicPageCacheWarmUserpass') ? (string) $config->getPublicPageCacheWarmUserpass() : '');
    $allow_insecure_tls = method_exists($config, 'allowsInsecureTlsForPublicPageCacheWarm')
        ? (bool) $config->allowsInsecureTlsForPublicPageCacheWarm()
        : false;

    $paths = getPublicWarmPaths($content_repository, $author_filter, $include_entries, $entry_limit, $include_pagination);
    if ($paths === []) {
        return ['warmed' => 0, 'failed' => 0, 'skipped_reason' => 'no public paths to warm'];
    }

    $warmed = 0;
    $failed = 0;
    $failure_example = '';
    $failures = [];
    foreach ($paths as $path) {
        $full_url = $resolved_base_url . $path;
        $result = fetchPublicUrlForWarm($full_url, $resolved_userpass, $allow_insecure_tls);
        $http_code = (int) ($result['http_code'] ?? 0);
        if (($result['error'] ?? '') !== '' || $http_code < 200 || $http_code >= 400) {
            $failed++;
            $error_message = trim((string) ($result['error'] ?? ''));
            $failures[] = [
                'url' => $full_url,
                'host' => (string) parse_url($full_url, PHP_URL_HOST),
                'reason' => $error_message !== '' ? $error_message : ('HTTP ' . $http_code),
            ];
            continue;
        }
        $warmed++;
    }

    if ($failures !== []) {
        $unique_hosts = array_values(array_unique(array_map(static fn(array $failure): string => (string) ($failure['host'] ?? ''), $failures)));
        $unique_reasons = array_values(array_unique(array_map(static fn(array $failure): string => (string) ($failure['reason'] ?? ''), $failures)));
        if (count($unique_hosts) === 1 && count($unique_reasons) === 1 && str_contains(strtolower($unique_reasons[0]), 'certificate')) {
            $failure_example = 'https://' . $unique_hosts[0] . '/* -> ' . $unique_reasons[0] . ' (' . count($failures) . ' URL(s) failed)';
        } elseif (count($failures) <= 3) {
            $failure_example = implode(' | ', array_map(static fn(array $failure): string => $failure['url'] . ' -> ' . $failure['reason'], $failures));
        } else {
            $preview = array_slice($failures, 0, 3);
            $failure_example = implode(' | ', array_map(static fn(array $failure): string => $failure['url'] . ' -> ' . $failure['reason'], $preview))
                . ' | +' . (count($failures) - count($preview)) . ' more';
        }
    }

    return [
        'warmed' => $warmed,
        'failed' => $failed,
        'failure_example' => $failure_example,
        'skipped_reason' => '',
    ];
}

function getPublicWarmPaths(
    TinyMashContentRepository $content_repository,
    string $author_filter = '',
    bool $include_entries = false,
    int $entry_limit = 0,
    bool $include_pagination = true
): array {
    $paths = ['/'];
    if ($include_pagination) {
        $aggregated_pagination = $content_repository->getPublishedAggregatedPaginationMeta(10);
        for ($page = 2; $page <= (int) ($aggregated_pagination['total_pages'] ?? 1); $page++) {
            $paths[] = '/?page=' . $page;
        }
    }

    $public_author_slugs = [];
    $normalized_author_filter = trim($author_filter);
    if ($normalized_author_filter !== '') {
        if ($content_repository->hasPublicAuthor($normalized_author_filter)) {
            $public_author_slugs[] = $normalized_author_filter;
        }
    } else {
        $public_author_slugs = $content_repository->getPublicAuthorSlugs();
    }

    foreach ($public_author_slugs as $author_slug) {
        $author_path = '/' . rawurlencode($author_slug);
        $paths[] = $author_path;
        if ($include_pagination) {
            $pagination = $content_repository->getAuthorHomePaginationMeta($author_slug, 10);
            $total_pages = (int) ($pagination['total_pages'] ?? 1);
            for ($page = 2; $page <= $total_pages; $page++) {
                $paths[] = $author_path . '?page=' . $page;
            }
        }
    }

    if ($include_entries) {
        foreach ($content_repository->getPublishedEntryWarmPaths($normalized_author_filter !== '' ? $normalized_author_filter : null, $entry_limit) as $entry_path) {
            $paths[] = $entry_path;
        }
    }

    return array_values(array_unique($paths));
}

function fetchPublicUrlForWarm(string $url, string $userpass = '', bool $allow_insecure_tls = false): array {
    $curl = curl_init($url);
    if ($curl === false) {
        return ['error' => 'curl_init failed', 'http_code' => 0];
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'tinymash-cache-warmer/1.0',
        ]
    );

    if ($userpass !== '') {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $userpass);
    }
    if ($allow_insecure_tls) {
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    }

    curl_exec($curl);
    $error = curl_error($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    return [
        'error' => $error,
        'http_code' => (int) ($info['http_code'] ?? 0),
    ];
}

function runPublicBenchmark(array $arguments): void {
    if (!function_exists('curl_init')) {
        fail('curl extension is required for benchmark public');
    }

    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file');
    }

    $options = parseBenchmarkOptions($arguments);
    $base_url = rtrim($options['base_url'] !== '' ? $options['base_url'] : (string) $config->configGetBaseURL(), '/');
    if ($base_url === '') {
        fail('base URL is required; pass --base-url=<url> or configure site.base_url');
    }
    $login_url = $config->configGetLoginURL();
    if (!is_string($login_url) || trim($login_url) === '') {
        $login_url = '/admin/login';
    }

    $targets = [
        'root' => '/',
    ];

    $author_slug = trim((string) ($options['author'] ?? ''));
    if ($author_slug !== '') {
        $targets['author_home'] = '/' . rawurlencode($author_slug);
        $targets['author_page_2'] = '/' . rawurlencode($author_slug) . '?page=2';
    }

    $entry_path = trim((string) ($options['entry'] ?? ''));
    if ($entry_path !== '') {
        if ($author_slug !== '' && !str_starts_with($entry_path, '/')) {
            $targets['entry'] = '/' . rawurlencode($author_slug) . '/' . ltrim($entry_path, '/');
        } else {
            $targets['entry'] = str_starts_with($entry_path, '/') ? $entry_path : '/' . $entry_path;
        }
    }

    printCliTitle('tinymash benchmark public');
    $benchmark_rows = [
        'Base URL' => $base_url,
        'Repeat' => $options['repeat'],
    ];
    if ($author_slug !== '') {
        $benchmark_rows['Author'] = $author_slug;
    }
    if ($entry_path !== '') {
        $benchmark_rows['Entry'] = $entry_path;
    }
    $cookie_file = '';
    if ($options['login_user'] !== '' || $options['login_pass'] !== '') {
        if ($options['login_user'] === '' || $options['login_pass'] === '') {
            fail('both --login-user and --login-pass are required for authenticated public benchmarking');
        }
        $normalized_login_url = str_starts_with((string) $login_url, '/') ? (string) $login_url : '/' . (string) $login_url;
        $cookie_file = loginForBenchmarkSession($base_url . $normalized_login_url, $options['userpass'], $options['login_user'], $options['login_pass']);
        $benchmark_rows['Logged in as'] = $options['login_user'];
    }
    printCliRows($benchmark_rows);

    try {
        foreach ($targets as $label => $path) {
            $samples = [];
            $http_code = 0;
            $downloaded_bytes = 0;
            $effective_url = '';
            $error_message = '';

            for ($index = 0; $index < $options['repeat']; $index++) {
                $result = benchmarkPublicUrl($base_url . $path, $options['userpass'], $cookie_file);
                $http_code = (int) ($result['http_code'] ?? 0);
                $downloaded_bytes = (int) ($result['size_download'] ?? 0);
                $effective_url = (string) ($result['effective_url'] ?? '');
                $error_message = trim((string) ($result['error'] ?? ''));

                if ($error_message !== '') {
                    break;
                }

                $samples[] = (float) ($result['time_total_ms'] ?? 0.0);
            }

            printCliSection($label);
            $target_rows = [
                'Path' => $path,
                'HTTP code' => $http_code,
            ];
            if ($effective_url !== '') {
                $target_rows['Effective URL'] = $effective_url;
            }
            if ($error_message !== '') {
                $target_rows['Error'] = $error_message;
                printCliRows($target_rows);
                continue;
            }

            $sample_count = count($samples);
            $average = $sample_count > 0 ? array_sum($samples) / $sample_count : 0.0;
            $minimum = $sample_count > 0 ? min($samples) : 0.0;
            $maximum = $sample_count > 0 ? max($samples) : 0.0;

            $target_rows['Average ms'] = number_format($average, 2, '.', '');
            $target_rows['Minimum ms'] = number_format($minimum, 2, '.', '');
            $target_rows['Maximum ms'] = number_format($maximum, 2, '.', '');
            $target_rows['Bytes'] = $downloaded_bytes;
            printCliRows($target_rows);
        }
    } finally {
        if ($cookie_file !== '' && is_file($cookie_file)) {
            @unlink($cookie_file);
        }
    }
}

function runRemoteMediaAudit(array $arguments): void {
    $options = parseRemoteMediaAuditOptions($arguments);
    $content_repository = new TinyMashContentRepository(TINYMASH_CONTENT_DIR);
    $entries = $content_repository->getEntriesForAudit(null, $options['author'] !== '' ? $options['author'] : null, true, !$options['include_unpublished']);

    $findings = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entry_findings = collectRemoteMediaFindingsForEntry($entry);
        foreach ($entry_findings as $finding) {
            $findings[] = $finding;
        }
    }

    printCliTitle('tinymash audit remote-media');
    printCliRows([
        'Scope' => $options['author'] !== '' ? 'author=' . $options['author'] : 'all',
        'Published only' => $options['include_unpublished'] ? 'no' : 'yes',
        'Entries scanned' => count($entries),
        'Remote image findings' => count($findings),
    ]);

    if ($findings === []) {
        fwrite(STDOUT, PHP_EOL . "No remote image URLs found." . PHP_EOL);
        return;
    }

    $classification_counts = [];
    foreach ($findings as $finding) {
        $classification = (string) ($finding['classification'] ?? 'unknown');
        $classification_counts[$classification] = ($classification_counts[$classification] ?? 0) + 1;
    }
    ksort($classification_counts, SORT_STRING);

    printCliSection('Summary');
    printCliRows($classification_counts);

    foreach ($findings as $finding) {
        printCliSection((string) $finding['classification']);
        printCliRows([
            'Path' => (string) $finding['path'],
            'Type' => (string) $finding['type'],
            'Location' => (string) $finding['location'],
            'Title' => (string) $finding['title'],
            'URL' => (string) $finding['url'],
        ]);
    }
}

function parseBenchmarkOptions(array $arguments): array {
    $options = [
        'base_url' => '',
        'userpass' => '',
        'login_user' => '',
        'login_pass' => '',
        'author' => '',
        'entry' => '',
        'repeat' => 3,
    ];

    foreach ($arguments as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }

        if (str_starts_with($argument, '--base-url=')) {
            $options['base_url'] = trim(substr($argument, 11));
            continue;
        }
        if (str_starts_with($argument, '--userpass=')) {
            $options['userpass'] = trim(substr($argument, 11));
            continue;
        }
        if (str_starts_with($argument, '--login-user=')) {
            $options['login_user'] = trim(substr($argument, 13));
            continue;
        }
        if (str_starts_with($argument, '--login-pass=')) {
            $options['login_pass'] = trim(substr($argument, 13));
            continue;
        }
        if (str_starts_with($argument, '--author=')) {
            $options['author'] = trim(substr($argument, 9));
            continue;
        }
        if (str_starts_with($argument, '--entry=')) {
            $options['entry'] = trim(substr($argument, 8));
            continue;
        }
        if (str_starts_with($argument, '--repeat=')) {
            $options['repeat'] = max(1, min(10, (int) trim(substr($argument, 9))));
        }
    }

    return $options;
}

function parseRemoteMediaAuditOptions(array $arguments): array {
    $options = [
        'author' => '',
        'include_unpublished' => false,
    ];

    foreach ($arguments as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }

        if (str_starts_with($argument, '--author=')) {
            $options['author'] = trim(substr($argument, 9));
            continue;
        }

        if ($argument === '--include-unpublished') {
            $options['include_unpublished'] = true;
        }
    }

    return $options;
}

function collectRemoteMediaFindingsForEntry(array $entry): array {
    $findings = [];
    $path = trim((string) ($entry['path'] ?? ''));
    $title = trim((string) ($entry['title'] ?? ''));
    $type = trim((string) ($entry['type'] ?? ''));

    foreach (findRemoteImageUrlsInText((string) ($entry['content'] ?? '')) as $url) {
        $findings[] = buildRemoteMediaFinding($entry, $path, $title, $type, 'content', $url);
    }

    foreach (findRemoteImageUrlsInText((string) ($entry['summary'] ?? '')) as $url) {
        $findings[] = buildRemoteMediaFinding($entry, $path, $title, $type, 'summary', $url);
    }

    $featured_image_url = trim((string) (($entry['featured_image']['url'] ?? '')));
    if (isRemoteMediaReference($featured_image_url)) {
        $findings[] = buildRemoteMediaFinding($entry, $path, $title, $type, 'featured_image', $featured_image_url);
    }

    $seo_social_image_url = trim((string) (($entry['seo_social_image']['url'] ?? '')));
    if (isRemoteMediaReference($seo_social_image_url)) {
        $findings[] = buildRemoteMediaFinding($entry, $path, $title, $type, 'seo_social_image', $seo_social_image_url);
    }

    return deduplicateRemoteMediaFindings($findings);
}

function findRemoteImageUrlsInText(string $markdown): array {
    $urls = [];
    if ($markdown === '') {
        return $urls;
    }

    if (preg_match_all('/!\[[^\]]*\]\(((?:https?:\/\/|\/?wp-content\/uploads\/|\/?uploads\/)[^)\s]+)(?:\s+"[^"]*")?\)/i', $markdown, $matches)) {
        foreach (($matches[1] ?? []) as $url) {
            if (is_string($url) && isRemoteMediaReference($url)) {
                $urls[] = trim($url);
            }
        }
    }

    if (preg_match_all('/<img\b[^>]*\bsrc=["\']((?:https?:\/\/|\/?wp-content\/uploads\/|\/?uploads\/)[^"\']+)["\']/i', $markdown, $matches)) {
        foreach (($matches[1] ?? []) as $url) {
            if (is_string($url) && isRemoteMediaReference($url)) {
                $urls[] = trim($url);
            }
        }
    }

    return array_values(array_unique($urls));
}

function buildRemoteMediaFinding(array $entry, string $path, string $title, string $type, string $location, string $url): array {
    return [
        'entry_id' => trim((string) ($entry['id'] ?? '')),
        'path' => $path,
        'title' => $title,
        'type' => $type,
        'location' => $location,
        'url' => $url,
        'classification' => classifyRemoteMediaUrl($url),
    ];
}

function deduplicateRemoteMediaFindings(array $findings): array {
    $deduplicated = [];
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $key = implode('|', [
            trim((string) ($finding['entry_id'] ?? '')),
            trim((string) ($finding['location'] ?? '')),
            trim((string) ($finding['url'] ?? '')),
        ]);
        if ($key === '||') {
            continue;
        }
        $deduplicated[$key] = $finding;
    }

    return array_values($deduplicated);
}

function classifyRemoteMediaUrl(string $url): string {
    if (!isRemoteMediaReference($url)) {
        return 'local';
    }

    $normalized_url = trim($url);
    $host = strtolower((string) parse_url($normalized_url, PHP_URL_HOST));
    $path = strtolower((string) parse_url($normalized_url, PHP_URL_PATH));
    if ($path === '' && preg_match('#^/?(?:wp-content/uploads|uploads)/#i', $normalized_url) === 1) {
        $path = strtolower('/' . ltrim($normalized_url, '/'));
    }

    if ($host === 'blogger.googleusercontent.com' || str_contains($host, 'googleusercontent.com')) {
        return 'likely_blogger_import_miss';
    }

    if (str_contains($path, '/wp-content/uploads/') || str_starts_with($path, '/uploads/')) {
        return 'likely_wordpress_import_miss';
    }

    return 'external_embed_or_reference';
}

function isRemoteUrl(string $url): bool {
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function isRemoteMediaReference(string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    if (isRemoteUrl($url)) {
        return true;
    }

    return preg_match('#^/?(?:wp-content/uploads|uploads)/#i', $url) === 1;
}

function benchmarkPublicUrl(string $url, string $userpass = '', string $cookie_file = ''): array {
    $curl = curl_init($url);
    if ($curl === false) {
        return [
            'error' => 'curl_init failed',
        ];
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'tinymash-benchmark/1.0',
            CURLOPT_HTTPHEADER => [
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
        ]
    );

    if ($userpass !== '') {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $userpass);
    }
    if ($cookie_file !== '') {
        curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_file);
    }

    $body = curl_exec($curl);
    $error = curl_error($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    return [
        'error' => $error,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'size_download' => (int) ($info['size_download'] ?? (is_string($body) ? strlen($body) : 0)),
        'effective_url' => (string) ($info['url'] ?? $url),
        'time_total_ms' => round(((float) ($info['total_time'] ?? 0.0)) * 1000, 2),
    ];
}

function loginForBenchmarkSession(string $login_url, string $userpass, string $username, string $password): string {
    $cookie_file = tempnam(sys_get_temp_dir(), 'tm-bench-login-');
    if (!is_string($cookie_file) || $cookie_file === '') {
        fail('unable to create temporary cookie file for benchmark login');
    }

    $csrf_token = fetchBenchmarkLoginCsrfToken($login_url, $userpass, $cookie_file);
    if ($csrf_token === '') {
        @unlink($cookie_file);
        fail('unable to fetch benchmark login CSRF token');
    }

    $curl = curl_init($login_url);
    if ($curl === false) {
        @unlink($cookie_file);
        fail('curl_init failed for benchmark login');
    }

    $post_fields = http_build_query(
        [
            'email' => '',
            'tinymash_csrf' => $csrf_token,
            'tinymash-admin_username' => $username,
            'tinymash-admin_password' => $password,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'tinymash-benchmark/1.0',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_COOKIEJAR => $cookie_file,
        ]
    );

    if ($userpass !== '') {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $userpass);
    }

    curl_exec($curl);
    $error = trim((string) curl_error($curl));
    $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($error !== '') {
        @unlink($cookie_file);
        fail('benchmark login failed: ' . $error);
    }
    if (!in_array($http_code, [302, 303], true)) {
        @unlink($cookie_file);
        fail('benchmark login failed with HTTP ' . $http_code);
    }

    $cookie_data = @file_get_contents($cookie_file);
    if (!is_string($cookie_data) || !str_contains($cookie_data, 'TINYMASH')) {
        @unlink($cookie_file);
        fail('benchmark login did not establish a tinymash session');
    }

    return $cookie_file;
}

function fetchBenchmarkLoginCsrfToken(string $login_url, string $userpass, string $cookie_file): string {
    $curl = curl_init($login_url);
    if ($curl === false) {
        return '';
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'tinymash-benchmark/1.0',
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_COOKIEJAR => $cookie_file,
        ]
    );

    if ($userpass !== '') {
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $userpass);
    }

    $body = curl_exec($curl);
    curl_close($curl);

    if (!is_string($body) || $body === '') {
        return '';
    }

    if (!preg_match('/name="tinymash_csrf"\s+value="([^"]+)"/', $body, $matches)) {
        return '';
    }

    return trim(html_entity_decode((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function buildComponentsReportService(): TinyMashComponentsReportService {
    return new TinyMashComponentsReportService(
        dirname(__DIR__),
        TINYMASH_COMPONENTS_REPORT
    );
}

function runCheckUpdates(bool $notify = false): void {
    $service = buildComponentsReportService();
    $report = $service->runCheck();
    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        fail('unable to read config file for notifications');
    }

    $notification_service = new TinyMashNotificationService(TINYMASH_RUNTIME_DIR . '/notifications.json');

    printCliTitle('tinymash check-updates');
    printCliRows([
        'Checked UTC' => (string) ($report['checked_at_utc'] ?? '-'),
        'Status' => (string) ($report['status'] ?? 'error'),
        'Composer' => (string) ($report['composer_version'] ?? 'Unavailable'),
        'Security updates' => (int) ($report['summary']['security_updates'] ?? 0),
        'Safe updates' => (int) ($report['summary']['safe_updates'] ?? 0),
        'Version updates' => (int) ($report['summary']['version_updates'] ?? 0),
        'Report file' => $service->getReportFilename(),
    ]);

    printCliSection('Checks');
    foreach (['outdated', 'audit'] as $check_key) {
        $check = is_array($report['checks'][$check_key] ?? null) ? $report['checks'][$check_key] : [];
        printCliRows([
            ucfirst($check_key) => (!empty($check['ok']) ? 'ok' : 'error')
                . ' (exit=' . (int) ($check['exit_code'] ?? 1) . ')',
        ]);
        if (!empty($check['stderr'])) {
            printCliRows([ ucfirst($check_key) . ' message' => trim((string) $check['stderr']) ]);
        }
    }

    $security_updates = (int) ($report['summary']['security_updates'] ?? 0);
    $safe_updates = (int) ($report['summary']['safe_updates'] ?? 0);
    $version_updates = (int) ($report['summary']['version_updates'] ?? 0);
    $notification_summary = $service->buildUpdateNotificationSummary($report);
    if (!empty($notification_summary['has_updates'])) {
        $dedupe_parts = [];
        foreach (['security_updates', 'safe_updates', 'version_updates'] as $group_key) {
            foreach ((array) ($report['packages'][$group_key] ?? []) as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $dedupe_parts[] = trim((string) ($package['name'] ?? '')) . ':' . trim((string) ($package['latest'] ?? ''));
            }
        }
        sort($dedupe_parts, SORT_STRING);
        try {
            $notification_service->createNotification(
                'component_updates',
                (string) ($notification_summary['title'] ?? 'Component updates available'),
                (string) ($notification_summary['message'] ?? ''),
                [
                    'severity' => (string) ($notification_summary['severity'] ?? 'info'),
                    'dedupe_key' => 'component-updates:' . hash('sha256', implode('|', $dedupe_parts)),
                    'dedupe_window_seconds' => 43200,
                    'context' => [
                        'checked_at_utc' => (string) ($report['checked_at_utc'] ?? ''),
                        'security_updates' => $security_updates,
                        'safe_updates' => $safe_updates,
                        'version_updates' => $version_updates,
                        'primary_class' => (string) ($notification_summary['primary_class'] ?? ''),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tinymash: warning: unable to queue component update notification: ' . $e->getMessage() . PHP_EOL);
        }
    }

    if (!$notify && empty(($config->getSystemSettings()['notification_email_events']['component_updates'] ?? false))) {
        return;
    }

    $mailer = new TinyMashMailer();
    $base_url = rtrim((string) $config->configGetBaseURL(), '/');
    $admin_url = (string) $config->configGetAdminURL();
    $components_url = '';
    if ($admin_url !== '' && preg_match('#^https?://#i', $admin_url) === 1) {
        $components_url = rtrim($admin_url, '/') . '/system/components';
    } elseif ($base_url !== '') {
        $components_url = $base_url . (str_starts_with($admin_url, '/') ? $admin_url : ('/' . $admin_url)) . '/system/components';
    }
    $notification = $service->sendNotificationIfNeeded(
        $report,
        $mailer,
        $config->getSystemSettings(),
        (string) ($config->getSystemSettings()['site_name'] ?? 'tinymash'),
        $base_url,
        $components_url
    );
    printCliSection('Notification');
    printCliRows([
        'Status' => (string) ($notification['status'] ?? 'skipped'),
        'Message' => (string) ($notification['message'] ?? ''),
    ]);
}

function runDeploy(string $target_directory): void {
    $runtime = buildCliRuntime(false);
    $config = $runtime['config'] ?? null;
    if (!$config instanceof TinyMashConfig) {
        fail('unable to initialize deploy runtime');
    }

    $deploy_service = new TinyMashDeployService(
        dirname(__DIR__),
        $config,
        TINYMASH_DEPLOY_MANIFEST
    );
    $manifest = $deploy_service->deploy($target_directory);

    printCliTitle('tinymash deploy');
    $deploy_rows = [
        'Target' => $target_directory,
        'Public theme' => (string) ($manifest['site']['public_theme'] ?? '-'),
        'Active plugins' => count((array) ($manifest['includes']['active_plugins'] ?? [])),
        'Copied files' => (int) ($manifest['counts']['copied_files'] ?? 0),
        'Copied directories' => (int) ($manifest['counts']['copied_directories'] ?? 0),
        'Manifest' => rtrim($target_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'deploy-manifest.json',
    ];

    $active_plugins = (array) ($manifest['includes']['active_plugins'] ?? []);
    if ($active_plugins !== []) {
        $deploy_rows['Plugins'] = implode(', ', $active_plugins);
    }
    printCliRows($deploy_rows);

    foreach ((array) ($manifest['warnings'] ?? []) as $warning) {
        $warning = trim((string) $warning);
        if ($warning === '') {
            continue;
        }
        fwrite(STDOUT, '  Warning: ' . $warning . PHP_EOL);
    }
}

function runExportSite(string $target_directory, bool $with_plugins = false): void {
    $runtime = buildCliRuntime($with_plugins);
    $export_service = $runtime['export_service'] ?? null;
    if (!$export_service instanceof TinyMashExportService) {
        fail('unable to initialize export service');
    }

    $manifest = $export_service->exportSite(
        $target_directory,
        $runtime['plugins'] instanceof TinyMashPlugins ? $runtime['plugins'] : null,
        $with_plugins
    );

    printCliTitle('tinymash export site');
    printCliRows([
        'Target' => $target_directory,
        'Entries' => (int) ($manifest['counts']['entries'] ?? 0),
        'Users' => (int) ($manifest['counts']['users'] ?? 0),
    ]);
}

function runExportAuthor(string $username, string $target_directory, bool $with_plugins = false): void {
    $runtime = buildCliRuntime($with_plugins);
    $export_service = $runtime['export_service'] ?? null;
    if (!$export_service instanceof TinyMashExportService) {
        fail('unable to initialize export service');
    }

    $manifest = $export_service->exportAuthor(
        $username,
        $target_directory,
        $runtime['plugins'] instanceof TinyMashPlugins ? $runtime['plugins'] : null,
        $with_plugins
    );

    printCliTitle('tinymash export author');
    printCliRows([
        'Author' => $username,
        'Target' => $target_directory,
        'Entries' => (int) ($manifest['counts']['entries'] ?? 0),
        'Drafts' => (int) ($manifest['counts']['drafts'] ?? 0),
    ]);
}

function runImportSite(string $source_directory, bool $replace_existing = false, bool $with_plugins = false): void {
    $runtime = buildCliRuntime($with_plugins);
    $export_service = $runtime['export_service'] ?? null;
    if (!$export_service instanceof TinyMashExportService) {
        fail('unable to initialize export service');
    }

    $result = $export_service->importSite(
        $source_directory,
        $replace_existing,
        $runtime['plugins'] instanceof TinyMashPlugins ? $runtime['plugins'] : null,
        $with_plugins
    );

    printCliTitle('tinymash import site');
    printCliRows([
        'Source' => $source_directory,
        'Users' => (int) ($result['copied']['users'] ?? 0),
        'Content files' => (int) ($result['copied']['content'] ?? 0),
    ]);
}

function runImportAuthor(string $source_directory, string $new_password, bool $replace_existing = false, bool $with_plugins = false): void {
    $runtime = buildCliRuntime($with_plugins);
    $export_service = $runtime['export_service'] ?? null;
    if (!$export_service instanceof TinyMashExportService) {
        fail('unable to initialize export service');
    }

    $result = $export_service->importAuthor(
        $source_directory,
        $new_password,
        $replace_existing,
        $runtime['plugins'] instanceof TinyMashPlugins ? $runtime['plugins'] : null,
        $with_plugins
    );

    printCliTitle('tinymash import author');
    printCliRows([
        'Source' => $source_directory,
        'Author' => (string) ($result['author_username'] ?? '-'),
        'Content files' => (int) ($result['copied']['content'] ?? 0),
    ]);
}

function listUsers(): void {
    $data = readUsers();
    $rows = [];

    foreach ($data['users'] as $user) {
        if (!is_array($user) || empty($user['username'])) {
            continue;
        }

        $role = !empty($user['role']) ? (string) $user['role'] : '-';
        $active = !empty($user['active']) ? 'active' : 'inactive';
        $rows[] = [
            'username' => (string) $user['username'],
            'role' => $role,
            'status' => $active,
        ];
    }

    printCliTitle('tinymash user list');
    if ($rows === []) {
        fwrite(STDOUT, PHP_EOL . 'No users found.' . PHP_EOL);
        return;
    }

    $username_width = max(strlen('Username'), ...array_map(static fn(array $row): int => strlen($row['username']), $rows));
    $role_width = max(strlen('Role'), ...array_map(static fn(array $row): int => strlen($row['role']), $rows));
    fwrite(STDOUT, PHP_EOL . '  ' . str_pad('Username', $username_width) . '  ' . str_pad('Role', $role_width) . '  Status' . PHP_EOL);
    fwrite(STDOUT, '  ' . str_repeat('-', $username_width) . '  ' . str_repeat('-', $role_width) . '  ------' . PHP_EOL);
    foreach ($rows as $row) {
        fwrite(STDOUT, '  ' . str_pad($row['username'], $username_width) . '  ' . str_pad($row['role'], $role_width) . '  ' . $row['status'] . PHP_EOL);
    }
}

function setUserPassword(string $username, string $password, string $role = 'superadmin'): void {
    $username = trim($username);
    if ($username === '') {
        fail('username must not be empty');
    }
    if ($password === '') {
        fail('password must not be empty');
    }

    $role = strtolower(trim($role));
    if ($role === 'admin') {
        $role = 'superadmin';
    }
    if (!in_array($role, ['superadmin', 'creator'], true)) {
        fail('role must be superadmin or creator');
    }

    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
    $existing = $repository->getUserByUsername($username);
    $repository->saveUser(
        [
            'original_username' => is_array($existing) ? $username : '',
            'username' => $username,
            'email' => is_array($existing) ? (string) ($existing['email'] ?? '') : '',
            'role' => $role,
            'password' => $password,
            'confirm_password' => $password,
            'active' => true,
            'content_active' => is_array($existing) ? !empty($existing['content_active']) : true,
        ]
    );
    printCliTitle('tinymash user set-password');
    printCliRows([
        'User' => $username,
        'Result' => is_array($existing) ? 'updated' : 'created',
    ]);
}

function runPluginCliCommand(string $command, array $arguments): bool {
    try {
        $runtime = buildCliRuntime(true);
        $plugins = $runtime['plugins'] ?? null;
        if (!$plugins instanceof TinyMashPlugins) {
            return false;
        }

        return $plugins->runCliCommand($command, $arguments);
    } catch (\Throwable $e) {
        fail($e->getMessage());
    }
}

function getTinymashRawCommandArguments(): array {
    $argv = $_SERVER['argv'] ?? [];
    if (!is_array($argv)) {
        return [];
    }

    array_shift($argv);
    $command_seen = false;
    $arguments = [];
    foreach ($argv as $argument) {
        if (!is_string($argument)) {
            continue;
        }
        if ($argument === '--allow-root') {
            continue;
        }
        if (!$command_seen) {
            if (str_starts_with($argument, '-')) {
                continue;
            }
            $command_seen = true;
            continue;
        }
        $arguments[] = $argument;
    }

    return $arguments;
}

function transformLegacyCliArgv(array $argv): array {
    $legacy_pairs = [
        'system' => [ 'status' => 'system:status', 'plugins' => 'system:plugins' ],
        'maintenance' => [ 'status' => 'maintenance:status', 'on' => 'maintenance:on', 'off' => 'maintenance:off' ],
        'cache' => [ 'clear' => 'cache:clear', 'warm' => 'cache:warm' ],
        'housekeeping' => [ 'status' => 'housekeeping:status', 'run' => 'housekeeping:run' ],
        'media' => [ 'usage' => 'media:usage', 'cleanup' => 'media:cleanup' ],
        'benchmark' => [ 'public' => 'benchmark:public' ],
        'audit' => [ 'remote-media' => 'audit:remote-media' ],
        'export' => [ 'site' => 'export:site', 'author' => 'export:author' ],
        'import' => [ 'site' => 'import:site', 'author' => 'import:author' ],
        'user' => [ 'list' => 'user:list', 'set-password' => 'user:set-password' ],
    ];

    $command_index = null;
    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index] ?? '';
        if (!is_string($argument) || $argument === '' || str_starts_with($argument, '-')) {
            continue;
        }
        $command_index = $index;
        break;
    }

    if ($command_index === null) {
        return $argv;
    }

    $command = (string) ($argv[$command_index] ?? '');
    $subcommand = (string) ($argv[$command_index + 1] ?? '');
    if (isset($legacy_pairs[$command][$subcommand])) {
        $argv[$command_index] = $legacy_pairs[$command][$subcommand];
        array_splice($argv, $command_index + 1, 1);
    }

    return $argv;
}

function readSetupJsonFile(string $filename): array {
    if (!is_file($filename) || !is_readable($filename)) {
        return [];
    }

    $json = @file_get_contents($filename);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function buildSetupDefaultConfig(): array {
    return [
        'site' => [
            'name' => 'tinymash',
            'slogan' => '',
            'contact' => '',
            'base_url' => '',
            'login_url' => '/admin/login',
            'admin_url' => '/admin',
            'security_min_password' => 8,
            'default_language' => 'en',
            'allow_registrations' => false,
            'is_public' => true,
            'maintenance' => false,
            'admin_email' => '',
            'system_notifications_email' => '',
            'week_starts_on' => 'monday',
            'allow_username_change' => false,
            'allow_email_change' => true,
            'login_message' => '',
            'head_tags' => [],
            'images' => [
                'banner' => [],
                'favicon_png' => [],
                'favicon_ico' => [],
                'og' => [],
                'background' => [],
            ],
            'background_render_mode' => 'scaled',
            'allow_secret_links' => true,
            'secret_link_default_expiry_days' => 60,
            'filesystem_umask' => '0007',
            'rendered_content_cache_enabled' => true,
            'discourage_search_indexing' => false,
            'allow_admin_password_resets' => false,
            'allow_author_password_resets' => true,
            'password_reset_throttle_window_minutes' => 60,
            'password_reset_max_ip_requests' => 5,
            'password_reset_max_identifier_requests' => 3,
        ],
        'locale' => [
            'time' => 'H:i (T)',
            'date' => 'd-M-Y',
            'timezone' => 'UTC',
        ],
        'content' => [
            'require_moderation' => false,
            'revision_retention_limit' => 20,
        ],
        'editor' => [
            'autosave_enabled' => true,
            'autosave_interval_seconds' => 120,
            'classic_smileys_enabled' => true,
        ],
        'media' => [
            'content_images_mode' => 'authenticated',
            'image_driver' => 'auto',
            'image_max_width' => 1920,
            'image_max_height' => 1920,
            'generate_thumbnails' => true,
            'thumbnail_max_width' => 320,
            'thumbnail_max_height' => 320,
            'image_max_upload_mb' => 10,
            'allowed_image_mimes' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/avif',
            ],
        ],
        'smtp' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => '',
            'from_name' => '',
            'reply_to_email' => '',
        ],
        'themes' => [
            'public' => [
                'current' => 'baseline',
                'settings' => [],
            ],
        ],
        'plugins' => [
            'states' => [],
            'settings' => [],
        ],
        'housekeeping' => [
            'stale_draft_retention_days' => 7,
            'last_run_utc' => '',
            'last_trigger' => '',
            'last_mode' => '',
            'web_fallback_mode' => 'auto',
        ],
        'notifications' => [
            'retention_days' => 30,
            'email_events' => [
                'moderation_required' => false,
                'profile_email_changed' => false,
                'user_lockout' => true,
                'component_updates' => true,
            ],
        ],
        'menus' => [
            'locations' => [
                'primary' => [],
                'footer' => [],
            ],
        ],
    ];
}

function loadSetupConfigArray(string $config_filename): array {
    $existing = readSetupJsonFile($config_filename);
    if ($existing !== []) {
        return $existing;
    }

    $example = readSetupJsonFile($config_filename . '.example');
    if ($example !== []) {
        return $example;
    }

    return buildSetupDefaultConfig();
}

function writeSetupConfigArray(string $config_filename, array $config): void {
    $directory = dirname($config_filename);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new \RuntimeException('Unable to create config directory.');
    }

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new \RuntimeException('Unable to encode config JSON.');
    }

    if (file_put_contents($config_filename, $json . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException('Unable to write config file.');
    }
}

function ensureSetupDirectory(string $directory): bool {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    return is_writable($directory);
}

function getSetupExistingSuperadmin(string $username = ''): ?array {
    if (!is_dir(TINYMASH_USERS_DIR)) {
        return null;
    }

    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
    if ($username !== '') {
        return $repository->getUserByUsername($username);
    }

    foreach ($repository->getAllUsers() as $user) {
        if (is_array($user) && (string) ($user['role'] ?? '') === 'superadmin') {
            return $user;
        }
    }

    return null;
}

function normalizeSetupBaseUrl(string $base_url): string {
    $base_url = rtrim(trim($base_url), '/');
    if ($base_url === '' || !preg_match('#^https?://#i', $base_url) || filter_var($base_url, FILTER_VALIDATE_URL) === false) {
        throw new \InvalidArgumentException('Enter a valid HTTP or HTTPS base URL.');
    }
    $parts = parse_url($base_url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = trim((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !isSetupValidUrlHost($host)) {
        throw new \InvalidArgumentException('Enter a valid HTTP or HTTPS base URL with a valid host name.');
    }

    return $base_url;
}

function isSetupValidUrlHost(string $host): bool {
    $host = trim($host, '[]');
    if ($host === '' || str_ends_with($host, '.') || str_contains($host, '_')) {
        return false;
    }

    if (strtolower($host) === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    if (strlen($host) > 253 || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        return false;
    }

    foreach (explode('.', $host) as $label) {
        if ($label === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label)) {
            return false;
        }
    }

    return true;
}

function normalizeSetupLanguage(string $language): string {
    $language = TinyMashConfig::normalizeHtmlLanguageTag(trim($language));
    if ($language === '') {
        return 'en';
    }

    return $language;
}

function normalizeSetupTimezone(string $timezone): string {
    $timezone = trim($timezone);
    if ($timezone === '') {
        return 'UTC';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new \InvalidArgumentException('Enter a valid PHP timezone such as UTC or Europe/Stockholm.');
    }

    return $timezone;
}

function validateSetupPassword(string $password): string {
    if ($password === '') {
        throw new \InvalidArgumentException('Superadmin password must not be empty.');
    }
    if (mb_strlen($password) < 8) {
        throw new \InvalidArgumentException('Superadmin password must be at least 8 characters.');
    }

    return $password;
}

function normalizeSetupYesNoAnswer(?string $value, bool $default): bool {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return $default;
    }
    if (in_array($value, ['y', 'yes'], true)) {
        return true;
    }
    if (in_array($value, ['n', 'no'], true)) {
        return false;
    }

    throw new \InvalidArgumentException('Answer yes or no.');
}

function readSetupPasswordFromInput(InputInterface $input): string {
    $password_file = trim((string) ($input->getOption('password-file') ?? ''));
    $password_stdin = (bool) $input->getOption('password-stdin');
    if ($password_file !== '' && $password_stdin) {
        throw new \InvalidArgumentException('Use either --password-file or --password-stdin, not both.');
    }

    if ($password_file !== '') {
        if (!is_file($password_file) || !is_readable($password_file)) {
            throw new \InvalidArgumentException('Password file is not readable.');
        }
        $password = file_get_contents($password_file);
        return is_string($password) ? rtrim($password, "\r\n") : '';
    }

    if ($password_stdin) {
        $password = stream_get_contents(STDIN);
        return is_string($password) ? rtrim($password, "\r\n") : '';
    }

    return '';
}

class TinymashLegacyCommand extends Command {
    /** @var callable */
    protected $handler;
    protected bool $read_only;
    protected string $legacy_usage;

    public function __construct(string $name, string $description, callable $handler, bool $read_only = false, string $legacy_usage = '', array $aliases = []) {
        $this->handler = $handler;
        $this->read_only = $read_only;
        $this->legacy_usage = $legacy_usage !== '' ? $legacy_usage : $name;
        parent::__construct($name);
        $this->setDescription($description);
        if ($aliases !== []) {
            $this->setAliases($aliases);
        }
    }

    protected function configure(): void {
        $this->ignoreValidationErrors();
        $this->addArgument('tokens', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Command arguments and legacy options.');
        $this->setHelp('Usage: php8.4 bin/tinymash.php ' . $this->legacy_usage);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        guardRootUserForCliCommand(getTinymashRootGuardArguments($this->getName(), getTinymashRawCommandArguments(), $this->read_only), (bool) $input->getOption('allow-root'));
        call_user_func($this->handler, getTinymashRawCommandArguments(), $input, $output);
        return Command::SUCCESS;
    }
}

class TinymashPluginBridgeCommand extends Command {
    protected string $plugin_command;

    public function __construct(array $definition) {
        $this->plugin_command = (string) ($definition['command'] ?? '');
        parent::__construct($this->plugin_command);
        $this->setDescription((string) ($definition['summary'] ?? 'Run a plugin command.'));
        $usage = trim((string) ($definition['usage'] ?? ''));
        if ($usage !== '') {
            $this->setHelp('Usage: ' . $usage);
        }
    }

    protected function configure(): void {
        $this->ignoreValidationErrors();
        $this->addArgument('tokens', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Plugin command arguments.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        guardRootUserForCliCommand([$this->plugin_command], (bool) $input->getOption('allow-root'));
        if (!runPluginCliCommand($this->plugin_command, getTinymashRawCommandArguments())) {
            throw new \RuntimeException('Plugin command is unavailable.');
        }

        return Command::SUCCESS;
    }
}

class TinymashSetupCommand extends Command {
    public function __construct() {
        parent::__construct('setup');
    }

    protected function configure(): void {
        $this
            ->setDescription('Configure a deployed tinymash tree and create the first superadmin.')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Site title')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Default public language tag', 'en')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Default timezone', 'UTC')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Superadmin username', 'admin')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Superadmin/admin e-mail address')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the superadmin password from STDIN')
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Read the superadmin password from a file')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Mark the site public')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Keep the site private')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Allow non-interactive updates to an existing config')
            ->setHelp(
                "Interactive setup:\n"
                . "  php8.4 bin/tinymash.php setup\n\n"
                . "Automated setup:\n"
                . "  printf '%s' \"\$PASSWORD\" | php8.4 bin/tinymash.php setup --site-name=\"My Site\" --base-url=https://example.com --password-stdin --no-interaction\n\n"
                . "Existing configs are preserved. Reruns update only setup fields after confirmation, or with --update in non-interactive mode."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        guardRootUserForCliCommand(['setup'], (bool) $input->getOption('allow-root'));

        $config_filename = dirname(__DIR__) . '/app/config/tinymash.json';
        $config_exists = is_file($config_filename) && trim((string) @file_get_contents($config_filename)) !== '';
        $is_interactive = $input->isInteractive();

        $io->title('tinymash setup');
        if ($config_exists) {
            $io->warning('Existing configuration detected. Setup will preserve the current config and update only setup fields.');
            if (!$is_interactive && !(bool) $input->getOption('update')) {
                $io->error('Refusing to update an existing config non-interactively without --update.');
                return Command::FAILURE;
            }
            if ($is_interactive && !$io->confirm('Continue and update setup fields?', false)) {
                $io->note('Setup aborted. No files were changed.');
                return Command::SUCCESS;
            }
        }

        $preflight = $this->runPreflight();
        $io->section('Preflight');
        $io->table(['Check', 'Status'], $preflight['rows']);
        if (!$preflight['ok']) {
            $io->error('Fix the failed preflight checks and run setup again.');
            return Command::FAILURE;
        }

        try {
            $config = loadSetupConfigArray($config_filename);
            if (!isset($config['site']) || !is_array($config['site'])) {
                $config['site'] = [];
            }
            if (!isset($config['locale']) || !is_array($config['locale'])) {
                $config['locale'] = [];
            }

            $setup_values = $this->collectSetupValues($input, $io, $config);
            $password = $this->collectSetupPassword($input, $io, (string) $setup_values['admin_user']);

            $config['site']['name'] = $setup_values['site_name'];
            $config['site']['base_url'] = $setup_values['base_url'];
            $config['site']['default_language'] = $setup_values['language'];
            $config['site']['admin_email'] = $setup_values['admin_email'];
            $config['site']['is_public'] = $setup_values['is_public'];
            $config['locale']['timezone'] = $setup_values['timezone'];

            writeSetupConfigArray($config_filename, $config);
            $this->saveSetupSuperadmin((string) $setup_values['admin_user'], (string) $setup_values['admin_email'], $password);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $admin_url = rtrim((string) $setup_values['base_url'], '/') . '/admin/login';
        $io->success('tinymash setup complete.');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Config', 'app/config/tinymash.json'],
                ['Site', (string) $setup_values['site_name']],
                ['Base URL', (string) $setup_values['base_url']],
                ['Admin user', (string) $setup_values['admin_user']],
                ['Admin login', $admin_url],
            ]
        );
        $io->writeln('Next: log in, review System settings, then enable housekeeping from cron.');

        return Command::SUCCESS;
    }

    protected function runPreflight(): array {
        $checks = [
            'PHP 8.4.1+' => PHP_VERSION_ID >= 80401,
            'Extension mbstring' => extension_loaded('mbstring'),
            'Extension json' => extension_loaded('json'),
            'Extension dom' => extension_loaded('dom'),
            'Extension session' => extension_loaded('session'),
            'Extension openssl' => extension_loaded('openssl'),
            'Extension fileinfo' => extension_loaded('fileinfo'),
            'Directory data/' => ensureSetupDirectory(dirname(__DIR__) . '/data'),
            'Directory users/' => ensureSetupDirectory(TINYMASH_USERS_DIR),
            'Directory tmp/' => ensureSetupDirectory(dirname(__DIR__) . '/tmp'),
            'Directory data/runtime/' => ensureSetupDirectory(TINYMASH_RUNTIME_DIR),
            'Config directory' => is_writable(dirname(dirname(__DIR__) . '/app/config/tinymash.json')),
        ];

        $rows = [];
        $ok = true;
        foreach ($checks as $label => $passed) {
            $passed = (bool) $passed;
            $ok = $ok && $passed;
            $rows[] = [$label, $passed ? '<info>ok</info>' : '<error>failed</error>'];
        }

        return ['ok' => $ok, 'rows' => $rows];
    }

    protected function collectSetupValues(InputInterface $input, SymfonyStyle $io, array $config): array {
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $locale = is_array($config['locale'] ?? null) ? $config['locale'] : [];
        $existing_admin = getSetupExistingSuperadmin();

        $default_site_name = trim((string) ($site['name'] ?? 'tinymash'));
        $default_base_url = trim((string) ($site['base_url'] ?? ''));
        $default_language = normalizeSetupLanguage((string) ($site['default_language'] ?? $input->getOption('language') ?? 'en'));
        $default_timezone = normalizeSetupTimezone((string) ($locale['timezone'] ?? $input->getOption('timezone') ?? 'UTC'));
        $default_admin_user = trim((string) ($existing_admin['username'] ?? $input->getOption('admin-user') ?? 'admin'));
        $default_admin_email = trim((string) ($existing_admin['email'] ?? $site['admin_email'] ?? $input->getOption('admin-email') ?? ''));
        $default_public = array_key_exists('is_public', $site) ? (bool) $site['is_public'] : true;

        $site_name = $this->resolveStringValue($input, $io, 'site-name', 'Site name', $default_site_name, static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                throw new \InvalidArgumentException('Site name must not be empty.');
            }
            return $value;
        });
        $base_url = $this->resolveStringValue($input, $io, 'base-url', 'Base URL', $default_base_url, 'normalizeSetupBaseUrl');
        $language = $this->resolveStringValue($input, $io, 'language', 'Default language', $default_language, 'normalizeSetupLanguage');
        $timezone = $this->resolveStringValue($input, $io, 'timezone', 'Timezone', $default_timezone, 'normalizeSetupTimezone');
        $admin_user = $this->resolveStringValue($input, $io, 'admin-user', 'Superadmin username', $default_admin_user, static function (string $value): string {
            $value = strtolower(trim($value));
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $value)) {
                throw new \InvalidArgumentException('Use a lowercase username with letters, numbers, and underscores.');
            }
            return $value;
        });
        $admin_email = $this->resolveStringValue($input, $io, 'admin-email', 'Admin e-mail (optional)', $default_admin_email, static function (string $value): string {
            $value = trim($value);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Enter a valid e-mail address or leave it empty.');
            }
            return $value;
        });

        if ((bool) $input->getOption('public') && (bool) $input->getOption('private')) {
            throw new \InvalidArgumentException('Use either --public or --private, not both.');
        }
        if ((bool) $input->getOption('public')) {
            $is_public = true;
        } elseif ((bool) $input->getOption('private')) {
            $is_public = false;
        } elseif ($input->isInteractive()) {
            $is_public = $io->ask(
                'Make the site public now? (yes/no)',
                $default_public ? 'yes' : 'no',
                static function (?string $value) use ($default_public): bool {
                    return normalizeSetupYesNoAnswer($value, $default_public);
                }
            );
        } else {
            $is_public = $default_public;
        }

        return [
            'site_name' => $site_name,
            'base_url' => $base_url,
            'language' => $language,
            'timezone' => $timezone,
            'admin_user' => $admin_user,
            'admin_email' => $admin_email,
            'is_public' => $is_public,
        ];
    }

    protected function resolveStringValue(InputInterface $input, SymfonyStyle $io, string $option_name, string $question, string $default, callable $normalizer): string {
        $option_value = $input->getOption($option_name);
        if (is_string($option_value) && trim($option_value) !== '') {
            return $normalizer($option_value);
        }

        if (!$input->isInteractive()) {
            return $normalizer($default);
        }

        return $io->ask($question, $default, static function (?string $value) use ($normalizer): string {
            return $normalizer((string) $value);
        });
    }

    protected function collectSetupPassword(InputInterface $input, SymfonyStyle $io, string $admin_user): string {
        $existing_admin = getSetupExistingSuperadmin($admin_user);
        $password = readSetupPasswordFromInput($input);
        if ($password !== '') {
            return validateSetupPassword($password);
        }

        if (!$input->isInteractive()) {
            if (is_array($existing_admin)) {
                return '';
            }
            throw new \InvalidArgumentException('A superadmin password is required. Use interactive setup, --password-stdin, or --password-file.');
        }

        $question = is_array($existing_admin)
            ? 'Superadmin password (leave blank to keep current password)'
            : 'Superadmin password';
        $password = (string) $io->askHidden($question);
        if ($password === '' && is_array($existing_admin)) {
            return '';
        }
        validateSetupPassword($password);
        $confirmation = (string) $io->askHidden('Confirm superadmin password');
        if ($password !== $confirmation) {
            throw new \InvalidArgumentException('Superadmin passwords did not match.');
        }

        return $password;
    }

    protected function saveSetupSuperadmin(string $username, string $email, string $password): void {
        $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR);
        $existing = $repository->getUserByUsername($username);
        $repository->saveUser(
            [
                'original_username' => is_array($existing) ? $username : '',
                'username' => $username,
                'email' => $email,
                'role' => 'superadmin',
                'password' => $password,
                'confirm_password' => $password,
                'active' => true,
                'content_active' => is_array($existing) ? !empty($existing['content_active']) : true,
            ]
        );
    }
}

function getTinymashRootGuardArguments(string $command_name, array $arguments, bool $read_only): array {
    if ($read_only) {
        return ['help'];
    }

    return array_merge([$command_name], $arguments);
}

function buildTinymashConsoleApplication(): Application {
    $application = new Application('tinymash', APP_VERSION);
    $application->getDefinition()->addOption(new InputOption('allow-root', null, InputOption::VALUE_NONE, 'Allow mutating commands to run as root without confirmation.'));
    $application->addCommand(new TinymashSetupCommand());

    $commands = [
        new TinymashLegacyCommand('system:status', 'Show runtime status.', static fn() => printSystemStatus(), true, 'system:status', ['system-status']),
        new TinymashLegacyCommand('system:plugins', 'Show plugin diagnostics.', static fn() => printSystemPluginDiagnostics(), true, 'system:plugins'),
        new TinymashLegacyCommand('maintenance:status', 'Show maintenance mode.', static fn() => printMaintenanceStatus(), true, 'maintenance:status'),
        new TinymashLegacyCommand('maintenance:on', 'Enable maintenance mode.', static fn() => setMaintenance(true), false, 'maintenance:on'),
        new TinymashLegacyCommand('maintenance:off', 'Disable maintenance mode.', static fn() => setMaintenance(false), false, 'maintenance:off'),
        new TinymashLegacyCommand('cache:clear', 'Clear compiled and persistent runtime caches.', static fn() => clearCache(), false, 'cache:clear'),
        new TinymashLegacyCommand('cache:warm', 'Warm content, media, and public-page caches.', static fn(array $arguments) => warmCache($arguments), false, 'cache:warm [--base-url=<url>] [--userpass=<user:pass>] [--author=<slug>] [--entries] [--entry-limit=<n>]'),
        new TinymashLegacyCommand('housekeeping:status', 'Show housekeeping state.', static fn() => printHousekeepingStatus(), true, 'housekeeping:status'),
        new TinymashLegacyCommand('housekeeping:run', 'Run housekeeping tasks.', static fn(array $arguments) => runHousekeeping(!in_array('--no-plugins', $arguments, true)), false, 'housekeeping:run [--no-plugins]'),
        new TinymashLegacyCommand('media:usage', 'Report media usage.', static fn(array $arguments) => runMediaUsage($arguments), true, 'media:usage [--owner=<root-or-author>] [--unused-only] [--limit=<n>]'),
        new TinymashLegacyCommand('media:cleanup', 'Report unused media or generate missing derivatives.', static fn(array $arguments) => runMediaCleanup($arguments), false, 'media:cleanup [--dry-run] [--generate-missing-derivatives] [--report-unused] [--owner=<root-or-author>] [--limit=<n>]'),
        new TinymashLegacyCommand('benchmark:public', 'Benchmark public routes.', static fn(array $arguments) => runPublicBenchmark($arguments), true, 'benchmark:public [--base-url=<url>] [--userpass=<user:pass>] [--login-user=<username>] [--login-pass=<password>] [--author=<slug>] [--entry=<slug>] [--repeat=<n>]'),
        new TinymashLegacyCommand('audit:remote-media', 'Scan content for remote image references.', static fn(array $arguments) => runRemoteMediaAudit($arguments), true, 'audit:remote-media [--author=<slug>] [--include-unpublished]'),
        new TinymashLegacyCommand('check-updates', 'Check Composer package updates and advisories.', static fn(array $arguments) => runCheckUpdates(in_array('--notify', $arguments, true)), false, 'check-updates [--notify]'),
        new TinymashLegacyCommand('deploy', 'Build a deployable runtime tree.', static function(array $arguments): void {
            $target_directory = (string) ($arguments[0] ?? '');
            if ($target_directory === '') {
                fail('usage: tinymash deploy <target-directory>');
            }
            runDeploy($target_directory);
        }, false, 'deploy <target-directory>'),
        new TinymashLegacyCommand('export:site', 'Export a site bundle.', static function(array $arguments): void {
            $target_directory = (string) ($arguments[0] ?? '');
            if ($target_directory === '') {
                fail('usage: tinymash export:site <target-directory> [--with-plugins]');
            }
            runExportSite($target_directory, in_array('--with-plugins', $arguments, true));
        }, false, 'export:site <target-directory> [--with-plugins]'),
        new TinymashLegacyCommand('export:author', 'Export one author bundle.', static function(array $arguments): void {
            $username = (string) ($arguments[0] ?? '');
            $target_directory = (string) ($arguments[1] ?? '');
            if ($username === '' || $target_directory === '') {
                fail('usage: tinymash export:author <username> <target-directory> [--with-plugins]');
            }
            runExportAuthor($username, $target_directory, in_array('--with-plugins', $arguments, true));
        }, false, 'export:author <username> <target-directory> [--with-plugins]'),
        new TinymashLegacyCommand('import:site', 'Import a site export bundle.', static function(array $arguments): void {
            $source_directory = (string) ($arguments[0] ?? '');
            if ($source_directory === '') {
                fail('usage: tinymash import:site <source-directory> [--replace-existing] [--with-plugins]');
            }
            runImportSite($source_directory, in_array('--replace-existing', $arguments, true), in_array('--with-plugins', $arguments, true));
        }, false, 'import:site <source-directory> [--replace-existing] [--with-plugins]'),
        new TinymashLegacyCommand('import:author', 'Import one author export bundle.', static function(array $arguments): void {
            $source_directory = (string) ($arguments[0] ?? '');
            $new_password = (string) ($arguments[1] ?? '');
            if ($source_directory === '' || $new_password === '') {
                fail('usage: tinymash import:author <source-directory> <new-password> [--replace-existing] [--with-plugins]');
            }
            runImportAuthor($source_directory, $new_password, in_array('--replace-existing', $arguments, true), in_array('--with-plugins', $arguments, true));
        }, false, 'import:author <source-directory> <new-password> [--replace-existing] [--with-plugins]'),
        new TinymashLegacyCommand('user:list', 'List local users.', static fn() => listUsers(), true, 'user:list'),
        new TinymashLegacyCommand('user:set-password', 'Create or update a local user password.', static function(array $arguments): void {
            $username = (string) ($arguments[0] ?? '');
            $password = (string) ($arguments[1] ?? '');
            $role = (string) ($arguments[2] ?? 'superadmin');
            if ($username === '' || $password === '') {
                fail('usage: tinymash user:set-password <username> <password> [role]');
            }
            setUserPassword($username, $password, $role);
        }, false, 'user:set-password <username> <password> [role]'),
    ];

    foreach ($commands as $command) {
        $application->addCommand($command);
    }

    foreach (getPluginCliCommands() as $plugin_command) {
        if (is_array($plugin_command) && !empty($plugin_command['command'])) {
            $application->addCommand(new TinymashPluginBridgeCommand($plugin_command));
        }
    }

    return $application;
}

if (PHP_SAPI !== 'cli') {
    fail('this command must be run from the CLI');
}

$_SERVER['argv'] = transformLegacyCliArgv($_SERVER['argv'] ?? []);
$application = buildTinymashConsoleApplication();
$application->run(new ArgvInput($_SERVER['argv']));
