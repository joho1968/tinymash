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

function findEarlyCliCommand(array $arguments): string {
    foreach (array_slice($arguments, 1) as $argument) {
        if (!is_string($argument) || $argument === '' || str_starts_with($argument, '-')) {
            continue;
        }
        return $argument;
    }

    return '';
}

function printEarlyUpdateRecoveryHelp(): void {
    fwrite(
        STDOUT,
        "Description:\n"
        . "  Inspect or recover an interrupted in-app update.\n\n"
        . "Usage:\n"
        . "  update:recover [<action>] [--allow-root]\n\n"
        . "Arguments:\n"
        . "  action  status, resume, or rollback [default: status]\n\n"
        . "Options:\n"
        . "  --allow-root  Allow recovery to run as root\n"
        . "  -h, --help    Show this help\n"
    );
}

function readEarlyUpdateJson(string $filename, int $maximum_bytes): array {
    $size = @filesize($filename);
    if (is_link($filename) || !is_file($filename) || !is_readable($filename)
        || !is_int($size) || $size < 2 || $size > $maximum_bytes) {
        throw new \RuntimeException('The update recovery files are unavailable.');
    }
    try {
        $value = json_decode((string) file_get_contents($filename), true, 32, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        throw new \RuntimeException('The update recovery files are invalid.');
    }
    if (!is_array($value)) {
        throw new \RuntimeException('The update recovery files are invalid.');
    }

    return $value;
}

function assertEarlyUpdatePathOwner(string $path, int $owner, bool $private): void {
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }
    $stat = @lstat($path);
    $mode = is_array($stat) ? (int) ($stat['mode'] ?? 0) : 0;
    if (!is_array($stat) || (int) ($stat['uid'] ?? -1) !== $owner
        || ($mode & 0022) !== 0 || ($private && ($mode & 0077) !== 0)) {
        throw new \RuntimeException('The update recovery files are not privately owned.');
    }
}

function loadEarlyUpdateRecoveryService(string $installation_root): app\classes\TinyMashUpdateCliRecoveryService {
    $runtime_directory = $installation_root . '/data/runtime';
    $journal_filename = $runtime_directory . '/update-transaction.json';
    $installation_stat = @lstat($installation_root . '/app');
    $installation_owner = is_array($installation_stat) ? (int) ($installation_stat['uid'] ?? -1) : -1;
    if ($installation_owner < 0) {
        throw new \RuntimeException('The installation owner could not be verified.');
    }
    assertEarlyUpdatePathOwner($journal_filename, $installation_owner, true);
    $journal = readEarlyUpdateJson($journal_filename, 1048576);
    $transaction_id = (string) ($journal['transaction_id'] ?? '');
    $manifest_sha256 = (string) ($journal['steps']['worker_runtime']['manifest_sha256'] ?? '');
    if ((string) ($journal['format'] ?? '') !== 'tinymash-update-transaction'
        || preg_match('/^[a-f0-9]{32}$/D', $transaction_id) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $manifest_sha256) !== 1) {
        throw new \RuntimeException('No recoverable update transaction is available.');
    }

    $recovery_root = $runtime_directory . '/update-transactions';
    $transaction_directory = $recovery_root . '/transaction-' . $transaction_id;
    $worker_root = $transaction_directory . '/worker-runtime';
    assertEarlyUpdatePathOwner($recovery_root, $installation_owner, false);
    assertEarlyUpdatePathOwner($transaction_directory, $installation_owner, true);
    assertEarlyUpdatePathOwner($worker_root, $installation_owner, true);
    $real_worker_root = @realpath($worker_root);
    if (is_link($recovery_root) || is_link($transaction_directory) || is_link($worker_root)
        || !is_string($real_worker_root) || !hash_equals($worker_root, rtrim($real_worker_root, DIRECTORY_SEPARATOR))) {
        throw new \RuntimeException('The update recovery worker is unavailable.');
    }

    $manifest_filename = $worker_root . '/manifest.json';
    assertEarlyUpdatePathOwner($manifest_filename, $installation_owner, false);
    $actual_manifest_sha256 = @hash_file('sha256', $manifest_filename);
    if (!is_string($actual_manifest_sha256) || !hash_equals($manifest_sha256, $actual_manifest_sha256)) {
        throw new \RuntimeException('The update recovery worker is unavailable.');
    }
    $manifest = readEarlyUpdateJson($manifest_filename, 65536);
    $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
    $service_path = 'app/classes/TinyMashUpdateCliRecoveryService.php';
    if ((string) ($manifest['format'] ?? '') !== 'tinymash-update-worker-runtime'
        || (int) ($manifest['schema'] ?? 0) !== 1
        || !isset($files[$service_path])) {
        throw new \RuntimeException('This transaction does not include CLI recovery.');
    }
    foreach ($files as $relative_path => $metadata) {
        $relative_path = (string) $relative_path;
        $metadata = is_array($metadata) ? $metadata : [];
        $segments = explode('/', $relative_path);
        if ($relative_path === '' || str_starts_with($relative_path, '/')
            || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $relative_path) === 1) {
            throw new \RuntimeException('The update recovery worker is invalid.');
        }
        $filename = $worker_root . '/' . $relative_path;
        assertEarlyUpdatePathOwner($filename, $installation_owner, false);
        $real_filename = @realpath($filename);
        $expected_bytes = $metadata['bytes'] ?? null;
        $expected_sha256 = (string) ($metadata['sha256'] ?? '');
        if (is_link($filename) || !is_file($filename) || !is_readable($filename)
            || !is_string($real_filename) || !str_starts_with($real_filename, $real_worker_root . DIRECTORY_SEPARATOR)
            || !is_int($expected_bytes) || $expected_bytes < 1 || @filesize($filename) !== $expected_bytes
            || preg_match('/^[a-f0-9]{64}$/D', $expected_sha256) !== 1
            || !hash_equals($expected_sha256, (string) @hash_file('sha256', $filename))) {
            throw new \RuntimeException('The update recovery worker is invalid.');
        }
    }

    require $worker_root . '/' . $service_path;
    return new app\classes\TinyMashUpdateCliRecoveryService(
        $installation_root,
        $recovery_root,
        $journal_filename,
        $runtime_directory
    );
}

function printEarlyUpdateRecoveryStatus(array $status): void {
    $managed = is_array($status['steps']['managed_files'] ?? null) ? $status['steps']['managed_files'] : [];
    $rows = [
        'Installed version' => (string) ($status['installed_version'] ?? 'unknown'),
        'Target version' => (string) ($status['target_version'] ?? 'unknown'),
        'Phase' => (string) ($status['phase'] ?? 'unknown'),
    ];
    if ((int) ($managed['total'] ?? 0) > 0) {
        $rows['Application files'] = (int) ($managed['completed'] ?? 0) . ' of ' . (int) $managed['total'];
    }
    if ((string) ($status['error'] ?? '') !== '') {
        $rows['Error'] = (string) $status['error'];
    }
    $width = max(...array_map('strlen', array_keys($rows)));
    fwrite(STDOUT, "tinymash update recovery\n========================\n");
    foreach ($rows as $label => $value) {
        fwrite(STDOUT, '  ' . str_pad($label, $width) . '  ' . $value . PHP_EOL);
    }
}

function runEarlyUpdateRecovery(array $arguments): never {
    if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        printEarlyUpdateRecoveryHelp();
        exit(0);
    }

    $command_seen = false;
    $action = 'status';
    $action_seen = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if (!is_string($argument) || $argument === '') {
            continue;
        }
        if (!$command_seen && $argument === 'update:recover') {
            $command_seen = true;
            continue;
        }
        if (in_array($argument, ['--allow-root', '--no-interaction', '-n'], true)) {
            continue;
        }
        if (str_starts_with($argument, '-')) {
            fwrite(STDERR, 'tinymash: unknown recovery option ' . $argument . PHP_EOL);
            exit(2);
        }
        if ($action_seen) {
            fwrite(STDERR, "tinymash: update:recover accepts one action\n");
            exit(2);
        }
        $action = strtolower($argument);
        $action_seen = true;
    }
    if (!in_array($action, ['status', 'resume', 'rollback'], true)) {
        fwrite(STDERR, "tinymash: recovery action must be status, resume, or rollback\n");
        exit(2);
    }

    $is_root = (function_exists('posix_geteuid') && posix_geteuid() === 0)
        || (function_exists('posix_getegid') && posix_getegid() === 0);
    if ($is_root) {
        fwrite(STDERR, "tinymash: warning: use the tinymash user for update recovery\n");
        if ($action !== 'status' && !in_array('--allow-root', $arguments, true)) {
            fwrite(STDERR, "tinymash: refusing mutating recovery as root without --allow-root\n");
            exit(1);
        }
    }

    try {
        $service = loadEarlyUpdateRecoveryService(dirname(__DIR__));
        $status = $service->run($action);
        printEarlyUpdateRecoveryStatus($status);
        exit(0);
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'tinymash: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$early_cli_command = findEarlyCliCommand($argv ?? []);
if ($early_cli_command === 'update:recover') {
    runEarlyUpdateRecovery($argv ?? []);
}

require_once __DIR__ . '/../app/classes/TinyMashWriterGate.php';

$cli_setup_request = $early_cli_command === 'setup';
$cli_writer_lease = null;
if (!$cli_setup_request) {
    try {
        $cli_writer_lease = (new app\classes\TinyMashWriterGate(__DIR__ . '/../data/runtime'))->acquireShared();
    } catch (\Throwable) {
        fwrite(STDERR, "tinymash: a site update is in progress\n");
        exit(1);
    }
}

$cli_config_filename = __DIR__ . '/../app/config/tinymash.json';
$cli_has_config = file_exists($cli_config_filename) || is_link($cli_config_filename);
$cli_help_request = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--') {
        break;
    }
    if (in_array($argument, ['--help', '-h', '--version', '-V'], true)) {
        $cli_help_request = true;
        break;
    }
}
if (!$cli_has_config && !$cli_help_request
    && !in_array($early_cli_command, ['', 'help', 'list', 'completion', 'setup', 'deploy:audit', 'upgrade:prepare'], true)) {
    fwrite(STDERR, "tinymash: configuration is missing; run setup or inspect the unfinished installation first\n");
    exit(1);
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
require_once __DIR__ . '/../app/classes/TinyMashEmbedService.php';
require_once __DIR__ . '/../app/classes/TinyMashMenuService.php';
require_once __DIR__ . '/../app/classes/TinyMashFilesystem.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaAttachmentMetadataStore.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaImportMapStore.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaService.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaImportBridge.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaUsageReporter.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaCapabilityRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashMediaDerivativeRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashLockedJsonFile.php';
require_once __DIR__ . '/../app/classes/TinyMashImportLockService.php';
require_once __DIR__ . '/../app/classes/TinyMashImportPreviewWorkspaceService.php';
require_once __DIR__ . '/../app/classes/TinyMashPublicPageCache.php';
require_once __DIR__ . '/../app/classes/TinyMashHousekeepingService.php';
require_once __DIR__ . '/../app/classes/TinyMashThemeRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashThemeSupportRegistry.php';
require_once __DIR__ . '/../app/classes/TinyMashTheme.php';
require_once __DIR__ . '/../app/classes/TinyMashHelpCatalog.php';
require_once __DIR__ . '/../app/classes/TinyMashUserRepository.php';
require_once __DIR__ . '/../app/classes/TinyMashSetupService.php';
require_once __DIR__ . '/../app/classes/TinyMashExportService.php';
require_once __DIR__ . '/../app/classes/TinyMashStaticExportArtifactService.php';
require_once __DIR__ . '/../app/classes/TinyMashStaticExportService.php';
require_once __DIR__ . '/../app/classes/TinyMashDeployService.php';
require_once __DIR__ . '/../app/classes/TinyMashDeployAuditService.php';
require_once __DIR__ . '/../app/classes/TinyMashUpgradePrepareService.php';
require_once __DIR__ . '/../app/classes/TinyMashMailer.php';
require_once __DIR__ . '/../app/classes/TinyMashNotificationService.php';
require_once __DIR__ . '/../app/classes/TinyMashPasswordResetService.php';
require_once __DIR__ . '/../app/classes/TinyMashComponentsReportService.php';
require_once __DIR__ . '/../app/classes/TinyMashUpdateStageService.php';
require_once __DIR__ . '/../app/classes/TinyMashUpdateJournal.php';

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
use app\classes\TinyMashEmbedService;
use app\classes\TinyMashMenuService;
use app\classes\TinyMashMediaAttachmentMetadataStore;
use app\classes\TinyMashMediaImportMapStore;
use app\classes\TinyMashMediaService;
use app\classes\TinyMashMediaImportBridge;
use app\classes\TinyMashMediaUsageReporter;
use app\classes\TinyMashMediaCapabilityRegistry;
use app\classes\TinyMashMediaDerivativeRegistry;
use app\classes\TinyMashImportLockService;
use app\classes\TinyMashImportPreviewWorkspaceService;
use app\classes\TinyMashPublicPageCache;
use app\classes\TinyMashHousekeepingService;
use app\classes\TinyMashThemeRegistry;
use app\classes\TinyMashThemeSupportRegistry;
use app\classes\TinyMashTheme;
use app\classes\TinyMashHelpCatalog;
use app\classes\TinyMashUserRepository;
use app\classes\TinyMashExportService;
use app\classes\TinyMashStaticExportArtifactService;
use app\classes\TinyMashStaticExportService;
use app\classes\TinyMashDeployService;
use app\classes\TinyMashDeployAuditService;
use app\classes\TinyMashUpgradePrepareService;
use app\classes\TinyMashMailer;
use app\classes\TinyMashNotificationService;
use app\classes\TinyMashPasswordResetService;
use app\classes\TinyMashComponentsReportService;
use app\classes\TinyMashSetupService;
use app\classes\TinyMashUpdateJournal;
use app\classes\TinyMashUpdateStageService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
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

function guardRootUserForCliCommand(bool $read_only, bool $allow_root): void {
    if (!isEffectiveRootUser()) {
        return;
    }

    $warning = 'tinymash: warning: running as root can leave runtime files owned by root; use the tinymash user when possible';
    if ($read_only) {
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
    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR, getCliEditorDefaultMode());
    return ['users' => $repository->getAllUsers()];
}

function writeUsers(array $data): void {
    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR, getCliEditorDefaultMode());
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

function getCliEditorDefaultMode(): string {
    $config_filename = dirname(__DIR__) . '/app/config/tinymash.json';
    if (!is_file($config_filename)) {
        return 'simple';
    }

    $config = new TinyMashConfigIO();
    if (!$config->getConfig()) {
        return 'simple';
    }

    $settings = $config->getSystemSettings();
    $mode = strtolower(trim((string) ($settings['editor_default_mode'] ?? 'simple')));
    return in_array($mode, ['simple', 'focused', 'full'], true) ? $mode : 'simple';
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

function printSystemThemeDiagnostics(): void {
    $runtime = buildCliRuntime(false);
    $theme_registry = $runtime['theme_registry'] ?? null;
    if (!$theme_registry instanceof TinyMashThemeRegistry || !method_exists($theme_registry, 'getThemeDiagnostics')) {
        fail('theme registry is unavailable');
    }

    $diagnostics = $theme_registry->getThemeDiagnostics();
    $summary = is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : [];
    $themes = is_array($diagnostics['themes'] ?? null) ? $diagnostics['themes'] : [];

    printCliTitle('tinymash theme diagnostics');
    printCliSection('Summary');
    printCliRows([
        'Installed' => (int) ($summary['total'] ?? 0),
        'Public' => (int) ($summary['public'] ?? 0),
        'Admin' => (int) ($summary['admin'] ?? 0),
        'First-party' => (int) ($summary['first_party'] ?? 0),
        'Third-party' => (int) ($summary['third_party'] ?? 0),
        'Warnings' => (int) ($summary['warnings'] ?? 0),
        'First-party warnings' => (int) ($summary['first_party_warnings'] ?? 0),
    ]);

    printCliSection('Themes');
    if ($themes === []) {
        fwrite(STDOUT, '  None' . PHP_EOL);
        return;
    }

    foreach ($themes as $theme) {
        if (!is_array($theme)) {
            continue;
        }
        $key = (string) ($theme['key'] ?? '');
        $name = (string) ($theme['name'] ?? $key);
        $type = (string) ($theme['type'] ?? 'public');
        $source = !empty($theme['first_party']) ? 'first-party' : 'third-party';
        $version = trim((string) ($theme['version'] ?? ''));
        $warnings = is_array($theme['manifest_warnings'] ?? null) ? $theme['manifest_warnings'] : [];
        $line = '  ' . $type . '  ' . $key . '  ' . $name . '  source=' . $source;
        if ($version !== '') {
            $line .= ' version=' . $version;
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
    if (!$app->has('view')) {
        $app->register('view', \Latte\Engine::class, [], function($latte): void {
            $latte->setTempDirectory(TINYMASH_RUNTIME_DIR . '/latte-cache/');
            $latte->setLoader(new \Latte\Loaders\FileLoader(dirname(__DIR__) . '/app/views'));
        });
    }

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
    $user_repository = new TinyMashUserRepository(TINYMASH_USERS_DIR, $config->getEditorDefaultMode());
    $markdown_renderer = new TinyMashMarkdownRenderer();
    $mailer = new TinyMashMailer($markdown_renderer);
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
    $import_preview_workspace_service = TinyMashImportPreviewWorkspaceService::forProjectRoot( dirname(__DIR__) );
    $content_renderer = new TinyMashContentRenderer(
        $markdown_renderer,
        $date_formatter,
        $config,
        $user_repository,
        $media_service,
        TINYMASH_RUNTIME_DIR . '/rendered-content'
    );
    $shortcode_registry = new TinyMashShortcodeRegistry($config->getUnknownShortcodeMode());
    $embed_service = new TinyMashEmbedService();
    $embed_service->registerCoreShortcode($shortcode_registry);
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
    $static_export_artifact_service = new TinyMashStaticExportArtifactService(
        TINYMASH_RUNTIME_DIR . '/static-exports',
        21600
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
    $static_export_service = new TinyMashStaticExportService(
        $app,
        $config,
        $content_repository,
        $content_renderer,
        $theme,
        $user_repository,
        $media_service,
        $static_export_artifact_service,
        dirname(__DIR__) . '/public'
    );

    $app->set('content.repository', $content_repository);
    $app->set('content.target_picker', $content_target_picker);
    $app->set('draft.repository', $draft_repository);
    $app->set('user.repository', $user_repository);
    $app->set('markdown.renderer', $markdown_renderer);
    $app->set('mailer', $mailer);
    $app->set('date.formatter', $date_formatter);
    $app->set('content.renderer', $content_renderer);
    $app->set('shortcode.registry', $shortcode_registry);
    $app->set('embed.service', $embed_service);
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
    $app->set('import.preview_workspaces', $import_preview_workspace_service);
    $app->set('media.capability_registry', $media_capability_registry);
    $app->set('media.derivative_registry', $media_derivative_registry);
    $app->set('help.catalog', $help_catalog);
    $app->set('export.service', $export_service);
    $app->set('static_export.artifacts', $static_export_artifact_service);
    $app->set('static_export.service', $static_export_service);
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
        'theme_registry' => $theme_registry,
        'theme' => $theme,
        'content_renderer' => $content_renderer,
        'media_metadata_store' => $media_metadata_store,
        'media_service' => $media_service,
        'media_import_bridge' => $media_import_bridge,
        'import_preview_workspace_service' => $import_preview_workspace_service,
        'media_usage_reporter' => $media_usage_reporter,
        'export_service' => $export_service,
        'static_export_artifact_service' => $static_export_artifact_service,
        'static_export_service' => $static_export_service,
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
    $updateStage = new TinyMashUpdateStageService(
        tinymash_get_runtime_temp_subdirectory_path('tinymash-updates'),
        dirname(__DIR__)
    );
    $updateJournal = new TinyMashUpdateJournal(TINYMASH_RUNTIME_DIR . '/update-transaction.json');
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
        $runtime['content_repository'] ?? null,
        $runtime['import_preview_workspace_service'] ?? null,
        $updateStage,
        $updateJournal
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

function runScheduledPublishing(): void {
    $config_io = new TinyMashConfigIO();
    if (!$config_io->getConfig()) {
        fail('unable to read config file');
    }

    $runtime = buildCliRuntime(true);
    $service = new TinyMashHousekeepingService(
        $config_io,
        $runtime['draft_repository'],
        $runtime['media_service'] ?? null,
        $runtime['plugins'] ?? null,
        $runtime['notification_service'] ?? null,
        $runtime['password_reset_service'] ?? null,
        $runtime['theme'] ?? null,
        TINYMASH_RUNTIME_DIR . '/housekeeping-web.lock',
        $runtime['media_import_bridge'] ?? null,
        $runtime['content_repository'] ?? null,
        $runtime['import_preview_workspace_service'] ?? null
    );
    $result = $service->runScheduledPublications();

    printCliTitle('tinymash content publish-due');
    printCliRows([
        'Status' => (string) ($result['status'] ?? 'error'),
        'Scheduled' => (int) ($result['scheduled_count'] ?? 0),
        'Due' => (int) ($result['due_count'] ?? 0),
        'Activated' => (int) ($result['activated_count'] ?? 0),
        'Events completed' => (int) ($result['completed_event_count'] ?? 0),
        'Events pending' => (int) ($result['pending_event_count'] ?? 0),
        'Failures' => (int) ($result['failed_count'] ?? 0),
        'Message' => (string) ($result['message'] ?? ''),
    ]);

    if (($result['status'] ?? 'error') === 'error' || (int) ($result['failed_count'] ?? 0) > 0) {
        fail('scheduled publication requires attention');
    }
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

function runMediaOrientation(array $arguments): void {
    $apply = in_array('--apply', $arguments, true);
    $owner = '';
    $limit = PHP_INT_MAX;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--owner=')) {
            $owner = strtolower(trim(substr($argument, 8)));
        } elseif (str_starts_with($argument, '--limit=')) {
            $limit = max(1, (int) trim(substr($argument, 8)));
        }
    }
    if ($owner === 'all') {
        $owner = '';
    }
    if ($owner !== '' && preg_match('/^[a-z0-9_]+$/', $owner) !== 1) {
        fail('invalid owner; use root or an author username');
    }

    $runtime = buildCliRuntime(false);
    $media_service = $runtime['media_service'] ?? null;
    if (!$media_service instanceof TinyMashMediaService) {
        fail('media service unavailable');
    }
    $result = $media_service->normalizeExistingJpegOrientations($limit, $owner !== '' ? [$owner] : [], !$apply);
    printCliTitle('tinymash media orientation');
    printCliRows([
        'Mode' => $apply ? 'apply' : 'preview',
        'Owner' => $owner !== '' ? $owner : 'all',
        'Checked' => (int) $result['checked'],
        'Candidates' => (int) $result['candidates'],
        'Normalized' => (int) $result['normalized'],
        'Failed' => (int) $result['failed'],
    ]);
    foreach ($result['items'] as $item) {
        $filename = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) $item['filename']) ?? '';
        echo (string) $item['status'] . ': ' . (string) $item['owner'] . '/' . (string) $item['media_id']
            . ' (' . $filename . ', EXIF ' . (int) $item['orientation'] . ')' . PHP_EOL;
    }
    if ((int) $result['failed'] > 0) {
        fail('some JPEGs could not be normalized; inspect the originals and retry');
    }
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
            $cache_states = [];
            $http_code = 0;
            $downloaded_bytes = 0;
            $effective_url = '';
            $error_message = '';
            $server_timing = '';

            for ($index = 0; $index < $options['repeat']; $index++) {
                $result = benchmarkPublicUrl($base_url . $path, $options['userpass'], $cookie_file);
                $http_code = (int) ($result['http_code'] ?? 0);
                $downloaded_bytes = (int) ($result['size_download'] ?? 0);
                $effective_url = (string) ($result['effective_url'] ?? '');
                $error_message = trim((string) ($result['error'] ?? ''));
                $server_timing = trim((string) ($result['server_timing'] ?? ''));

                if ($error_message !== '') {
                    break;
                }

                $samples[] = (float) ($result['time_total_ms'] ?? 0.0);
                $cache_states[] = (string) ($result['cache_state'] ?? 'none');
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
            $cache_state_counts = array_count_values($cache_states);
            ksort($cache_state_counts, SORT_STRING);

            $target_rows['Average ms'] = number_format($average, 2, '.', '');
            $target_rows['P50 ms'] = number_format(calculateBenchmarkPercentile($samples, 50), 2, '.', '');
            $target_rows['P95 ms'] = number_format(calculateBenchmarkPercentile($samples, 95), 2, '.', '');
            $target_rows['Minimum ms'] = number_format($minimum, 2, '.', '');
            $target_rows['Maximum ms'] = number_format($maximum, 2, '.', '');
            $target_rows['Samples ms'] = implode(', ', array_map(static fn(float $sample): string => number_format($sample, 2, '.', ''), $samples));
            $target_rows['Cache states'] = $cache_state_counts === []
                ? 'none'
                : implode(', ', array_map(static fn(string $state, int $count): string => $state . '=' . $count, array_keys($cache_state_counts), array_values($cache_state_counts)));
            if ($server_timing !== '') {
                $target_rows['Server-Timing'] = $server_timing;
            }
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
    $options = parseContentAuditOptions($arguments);
    $content_repository = new TinyMashContentRepository(TINYMASH_CONTENT_DIR);
    $author = $options['author'];
    if ($author !== '' && $content_repository->normalizeAuthorSlug($author) !== strtolower($author)) {
        fail('invalid author username');
    }
    $entries = $content_repository->getEntriesForAudit(null, $author !== '' ? $author : null, $author === '', !$options['include_unpublished']);

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
        'Scope' => $author !== '' ? 'author=' . $author : 'all',
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

function runShortcodeAudit(array $arguments): void {
    $options = parseContentAuditOptions($arguments);
    $runtime = buildCliRuntime(true);
    $repository = $runtime['content_repository'];
    $registry = $runtime['app']->get('shortcode.registry');
    if (!$repository instanceof TinyMashContentRepository || !$registry instanceof TinyMashShortcodeRegistry) {
        fail('shortcode audit is unavailable');
    }

    $author = $options['author'];
    if ($author !== '' && $repository->normalizeAuthorSlug($author) !== strtolower($author)) {
        fail('invalid author username');
    }

    $entries = $repository->getEntriesForAudit(null, $author !== '' ? $author : null, $author === '', !$options['include_unpublished']);
    $counts = [];
    $paths = [];
    $by_entry = [];
    $affected_entries = [];
    $supported = 0;
    $unsupported = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry) || !in_array((string) ($entry['type'] ?? ''), ['post', 'page'], true)) {
            continue;
        }
        $translations = is_array($entry['translations'] ?? null) ? $entry['translations'] : [];
        if ($translations === []) {
            $translations = [(string) ($entry['language'] ?? 'default') => $entry];
        }
        foreach ($translations as $language => $translation) {
            if (!is_array($translation)) {
                continue;
            }
            foreach ($registry->scanShortcodeCandidates((string) ($translation['content'] ?? '')) as $candidate) {
                if (!empty($candidate['supported'])) {
                    $supported++;
                    continue;
                }
                $unsupported++;
                $name = (string) $candidate['name'];
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $path = '/' . (($entry['scope'] ?? 'root') === 'author' ? (string) ($entry['author_slug'] ?? '') . '/' : '') . ltrim((string) ($entry['path'] ?? ''), '/');
                if (count($translations) > 1) {
                    $path .= ' (' . (string) $language . ')';
                }
                $paths[$name][$path] = true;
                $by_entry[$path][$name] = ($by_entry[$path][$name] ?? 0) + 1;
                $affected_entries[(string) ($entry['id'] ?? $path)] = true;
            }
        }
    }

    printCliTitle('tinymash audit shortcodes');
    printCliRows([
        'Scope' => $author !== '' ? 'author=' . $author : 'all',
        'Published only' => $options['include_unpublished'] ? 'no' : 'yes',
        'Entries scanned' => count($entries),
        'Supported uses' => $supported,
        'Unsupported candidates' => $unsupported,
        'Unsupported names' => count($counts),
        'Affected entries' => count($affected_entries),
    ]);

    if ($counts === []) {
        fwrite(STDOUT, PHP_EOL . "No unsupported shortcode candidates found." . PHP_EOL);
        return;
    }

    ksort($counts, SORT_STRING);
    $shown = 0;
    foreach ($counts as $name => $count) {
        if ($shown++ >= 50) {
            break;
        }
        $entry_paths = array_keys($paths[$name] ?? []);
        sort($entry_paths, SORT_STRING);
        printCliSection($name);
        printCliRows([
            'Occurrences' => $count,
            'Entries' => count($entry_paths),
            'Paths' => implode(', ', array_slice($entry_paths, 0, 10)) . (count($entry_paths) > 10 ? ', ...' : ''),
        ]);
    }
    if (count($counts) > 50) {
        fwrite(STDOUT, PHP_EOL . 'Showing 50 of ' . count($counts) . " unsupported names.\n");
    }

    ksort($by_entry, SORT_STRING);
    printCliSection('Review by entry');
    $shown = 0;
    foreach ($by_entry as $path => $findings) {
        if ($shown++ >= 50) {
            break;
        }
        ksort($findings, SORT_STRING);
        $parts = [];
        foreach ($findings as $name => $count) {
            $parts[] = $name . ($count > 1 ? ' (' . $count . ')' : '');
        }
        printCliRows([$path => implode(', ', $parts)]);
    }
    if (count($by_entry) > 50) {
        fwrite(STDOUT, PHP_EOL . 'Showing 50 of ' . count($by_entry) . " affected entries.\n");
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

function parseContentAuditOptions(array $arguments): array {
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

    $response_headers = [];
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
            CURLOPT_HEADERFUNCTION => static function ($curl_handle, string $header_line) use (&$response_headers): int {
                $header_length = strlen($header_line);
                $trimmed_line = trim($header_line);
                if ($trimmed_line === '') {
                    return $header_length;
                }
                if (str_starts_with(strtoupper($trimmed_line), 'HTTP/')) {
                    $response_headers = [];
                    return $header_length;
                }

                $separator_position = strpos($trimmed_line, ':');
                if ($separator_position === false) {
                    return $header_length;
                }
                $name = strtolower(trim(substr($trimmed_line, 0, $separator_position)));
                $value = trim(substr($trimmed_line, $separator_position + 1));
                if ($name !== '') {
                    $response_headers[$name] = isset($response_headers[$name])
                        ? $response_headers[$name] . ', ' . $value
                        : $value;
                }
                return $header_length;
            },
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
    $server_timing = trim((string) ($response_headers['server-timing'] ?? ''));

    return [
        'error' => $error,
        'http_code' => (int) ($info['http_code'] ?? 0),
        'size_download' => (int) ($info['size_download'] ?? (is_string($body) ? strlen($body) : 0)),
        'effective_url' => (string) ($info['url'] ?? $url),
        'time_total_ms' => round(((float) ($info['total_time'] ?? 0.0)) * 1000, 2),
        'server_timing' => $server_timing,
        'cache_state' => classifyBenchmarkCacheState($server_timing),
    ];
}

function classifyBenchmarkCacheState(string $server_timing): string {
    if (preg_match('/(?:^|,\s*)cache_hit(?:;|,|$)/i', $server_timing) === 1) {
        return 'hit';
    }
    if (preg_match('/(?:^|,\s*)cache_write(?:;|,|$)/i', $server_timing) === 1) {
        return 'miss/write';
    }
    if (preg_match('/(?:^|,\s*)cache_miss(?:;|,|$)/i', $server_timing) === 1) {
        return 'miss';
    }
    return 'none';
}

function calculateBenchmarkPercentile(array $samples, int $percentile): float {
    $samples = array_values(array_filter($samples, 'is_numeric'));
    if ($samples === []) {
        return 0.0;
    }

    sort($samples, SORT_NUMERIC);
    $percentile = max(0, min(100, $percentile));
    $position = ($percentile / 100) * (count($samples) - 1);
    $lower_index = (int) floor($position);
    $upper_index = (int) ceil($position);
    if ($lower_index === $upper_index) {
        return (float) $samples[$lower_index];
    }

    $weight = $position - $lower_index;
    return ((float) $samples[$lower_index] * (1 - $weight)) + ((float) $samples[$upper_index] * $weight);
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

    $mailer = new TinyMashMailer(new TinyMashMarkdownRenderer());
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
        'Inventoried files' => (int) ($manifest['counts']['inventoried_files'] ?? 0),
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

function runDeployAudit(array $arguments): void {
    $target_directory = '';
    $manifest_filename = '';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--manifest=')) {
            $manifest_filename = trim(substr($argument, strlen('--manifest=')));
            continue;
        }
        if (str_starts_with($argument, '--')) {
            fail('unknown deploy:audit option: ' . $argument, 2);
        }
        if ($target_directory === '') {
            $target_directory = $argument;
            continue;
        }
        fail('only one deployed directory may be inspected', 2);
    }
    if ($target_directory === '') {
        fail('deployed directory is required', 2);
    }

    try {
        $report = (new TinyMashDeployAuditService($target_directory, $manifest_filename))->audit();
    } catch (\Throwable $exception) {
        fail($exception->getMessage());
    }

    printCliTitle('tinymash deploy audit');
    printCliRows([
        'Target' => (string) ($report['target_directory'] ?? $target_directory),
        'Manifest' => (string) ($report['manifest_filename'] ?? $manifest_filename),
        'Built UTC' => (string) ($report['manifest_built_at_utc'] ?? '-'),
        'Status' => (string) ($report['status'] ?? 'differences'),
        'Expected files' => (int) ($report['expected_files'] ?? 0),
        'Checked files' => (int) ($report['checked_files'] ?? 0),
        'Ignored state paths' => (int) ($report['ignored_paths'] ?? 0),
        'Missing files' => count((array) ($report['missing_files'] ?? [])),
        'Missing directories' => count((array) ($report['missing_directories'] ?? [])),
        'Changed files' => count((array) ($report['changed_files'] ?? [])),
        'Unknown files' => count((array) ($report['unknown_files'] ?? [])),
        'Unsafe paths' => count((array) ($report['unsafe_paths'] ?? [])),
        'Unmanaged paths' => count((array) ($report['unmanaged_paths'] ?? [])),
    ]);

    $sections = [
        'Missing files' => (array) ($report['missing_files'] ?? []),
        'Missing directories' => (array) ($report['missing_directories'] ?? []),
        'Changed files' => (array) ($report['changed_files'] ?? []),
        'Unknown files' => (array) ($report['unknown_files'] ?? []),
        'Unsafe paths' => (array) ($report['unsafe_paths'] ?? []),
        'Unmanaged paths (not checked)' => (array) ($report['unmanaged_paths'] ?? []),
    ];
    foreach ($sections as $title => $paths) {
        if ($paths === []) {
            continue;
        }
        printCliSection($title);
        foreach (array_slice($paths, 0, 100) as $path) {
            fwrite(STDOUT, '  ' . (string) $path . PHP_EOL);
        }
        if (count($paths) > 100) {
            fwrite(STDOUT, '  ... ' . (count($paths) - 100) . ' more' . PHP_EOL);
        }
    }

    if (empty($report['ok'])) {
        fflush(STDOUT);
        fail('deploy audit found differences');
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

function runStaticExportAuthor(array $arguments): void {
    $author_slug = (string) ($arguments[0] ?? '');
    if ($author_slug === '') {
        fail('author username or slug is required');
    }

    $options = parseStaticExportOptions(array_slice($arguments, 1));
    $runtime = buildCliRuntime(true);
    $service = $runtime['static_export_service'] ?? null;
    if (!$service instanceof TinyMashStaticExportService) {
        fail('static export service is unavailable');
    }

    $target_directory = (string) ($options['output'] ?? '');
    if ($target_directory === '') {
        $artifact = $service->createAuthorArtifact($author_slug, $author_slug, ['base_url' => (string) ($options['base_url'] ?? '')]);
        printCliTitle('tinymash static export author');
        printCliRows([
            'Artifact' => (string) ($artifact['id'] ?? ''),
            'Archive' => (string) ($artifact['download_filename'] ?? ''),
            'Owner' => (string) ($artifact['owner_username'] ?? ''),
            'Expires' => (string) ($artifact['expires_at_utc'] ?? ''),
        ]);
        return;
    }

    $report = $service->exportAuthorToDirectory($author_slug, $target_directory, ['base_url' => (string) ($options['base_url'] ?? '')]);
    printCliTitle('tinymash static export author');
    printCliRows([
        'Output' => $target_directory,
        'Routes' => (int) ($report['route_count'] ?? 0),
        'Assets' => (int) ($report['asset_count'] ?? 0),
        'Warnings' => count(is_array($report['warnings'] ?? null) ? $report['warnings'] : []),
    ]);
}

function runStaticExportSite(array $arguments): void {
    $options = parseStaticExportOptions($arguments);
    $runtime = buildCliRuntime(true);
    $service = $runtime['static_export_service'] ?? null;
    if (!$service instanceof TinyMashStaticExportService) {
        fail('static export service is unavailable');
    }

    $export_options = [
        'base_url' => (string) ($options['base_url'] ?? ''),
        'exclude_authors' => is_array($options['exclude_authors'] ?? null) ? $options['exclude_authors'] : [],
    ];
    $target_directory = (string) ($options['output'] ?? '');
    if ($target_directory === '') {
        $artifact = $service->createSiteArtifact('cli', $export_options);
        printCliTitle('tinymash static export site');
        printCliRows([
            'Artifact' => (string) ($artifact['id'] ?? ''),
            'Archive' => (string) ($artifact['download_filename'] ?? ''),
            'Owner' => (string) ($artifact['owner_username'] ?? ''),
            'Expires' => (string) ($artifact['expires_at_utc'] ?? ''),
        ]);
        return;
    }

    $report = $service->exportSiteToDirectory($target_directory, $export_options);
    printCliTitle('tinymash static export site');
    printCliRows([
        'Output' => $target_directory,
        'Routes' => (int) ($report['route_count'] ?? 0),
        'Assets' => (int) ($report['asset_count'] ?? 0),
        'Included authors' => count(is_array($report['included_authors'] ?? null) ? $report['included_authors'] : []),
        'Excluded authors' => count(is_array($report['excluded_authors'] ?? null) ? $report['excluded_authors'] : []),
        'Warnings' => count(is_array($report['warnings'] ?? null) ? $report['warnings'] : []),
    ]);
}

function parseStaticExportOptions(array $arguments): array {
    $options = [
        'base_url' => '',
        'output' => '',
        'exclude_authors' => [],
    ];

    foreach ($arguments as $argument) {
        $argument = (string) $argument;
        if (str_starts_with($argument, '--base-url=')) {
            $options['base_url'] = trim(substr($argument, strlen('--base-url=')));
            continue;
        }
        if (str_starts_with($argument, '--output=')) {
            $options['output'] = trim(substr($argument, strlen('--output=')));
            continue;
        }
        if (str_starts_with($argument, '--exclude-author=')) {
            $author_slug = trim(substr($argument, strlen('--exclude-author=')));
            if ($author_slug !== '') {
                $options['exclude_authors'][] = $author_slug;
            }
            continue;
        }

        fail('unknown static export option: ' . $argument);
    }

    return $options;
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

function setUserPassword(string $username, string $password, string $role = ''): void {
    $username = trim($username);
    if ($username === '') {
        fail('username must not be empty');
    }
    if ($password === '') {
        fail('password must not be empty');
    }

    $repository = new TinyMashUserRepository(TINYMASH_USERS_DIR, getCliEditorDefaultMode());
    $existing = $repository->getUserByUsername($username);

    $role = strtolower(trim($role));
    if ($role === '' && is_array($existing)) {
        $role = strtolower(trim((string) ($existing['role'] ?? '')));
    }
    if ($role === '') {
        fail('role is required when creating a user; use superadmin or creator');
    }
    if ($role === 'admin') {
        $role = 'superadmin';
    }
    if (!in_array($role, ['superadmin', 'creator'], true)) {
        fail('role must be superadmin or creator');
    }

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

function transformLegacyCliArgv(array $argv): array {
    $legacy_pairs = [
        'system' => [ 'status' => 'system:status', 'plugins' => 'system:plugins', 'themes' => 'system:themes' ],
        'maintenance' => [ 'status' => 'maintenance:status', 'on' => 'maintenance:on', 'off' => 'maintenance:off' ],
        'cache' => [ 'clear' => 'cache:clear', 'warm' => 'cache:warm' ],
        'housekeeping' => [ 'status' => 'housekeeping:status', 'run' => 'housekeeping:run' ],
        'content' => [ 'publish-due' => 'content:publish-due' ],
        'media' => [ 'usage' => 'media:usage', 'cleanup' => 'media:cleanup' ],
        'benchmark' => [ 'public' => 'benchmark:public' ],
        'audit' => [ 'remote-media' => 'audit:remote-media', 'shortcodes' => 'audit:shortcodes' ],
        'export' => [ 'site' => 'export:site', 'author' => 'export:author' ],
        'static-export' => [ 'site' => 'static-export:site', 'author' => 'static-export:author' ],
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

class TinymashConsoleApplication extends Application {
    protected function getDefaultInputDefinition(): InputDefinition {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'Command to run'),
            new InputOption('help', 'h', InputOption::VALUE_NONE, 'Show command help, or list commands when no command is given'),
            new InputOption('silent', null, InputOption::VALUE_NONE, 'Suppress all output'),
            new InputOption('quiet', 'q', InputOption::VALUE_NONE, 'Show errors only'),
            new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Increase output detail: -v, -vv, or -vvv'),
            new InputOption('version', 'V', InputOption::VALUE_NONE, 'Show the tinymash version'),
            new InputOption('ansi', null, InputOption::VALUE_NEGATABLE, 'Force or disable ANSI output'),
            new InputOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Do not ask interactive questions'),
        ]);
    }
}

class TinymashCallableCommand extends Command {
    /** @var callable */
    protected $handler;
    protected array $command_definition;

    public function __construct(array $definition, callable $handler) {
        $this->handler = $handler;
        $this->command_definition = $definition;
        parent::__construct((string) ($definition['name'] ?? ''));
        $aliases = is_array($definition['aliases'] ?? null) ? $definition['aliases'] : [];
        if ($aliases !== []) {
            $this->setAliases($aliases);
        }
    }

    protected function configure(): void {
        configureTinymashCommand($this, $this->command_definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        validateTinymashCommandInput($input, $this->command_definition);
        $read_only = !empty($this->command_definition['read_only']);
        $read_only_when = $this->command_definition['read_only_when'] ?? null;
        if (is_callable($read_only_when) && $read_only_when($input)) {
            $read_only = true;
        }
        guardRootUserForCliCommand($read_only, (bool) $input->getOption('allow-root'));
        call_user_func($this->handler, buildTinymashHandlerArguments($input, $this->command_definition), $input, $output);
        return Command::SUCCESS;
    }
}

class TinymashPluginBridgeCommand extends Command {
    protected string $plugin_command;
    protected array $command_definition;

    public function __construct(array $definition) {
        $this->command_definition = $definition;
        $this->plugin_command = (string) ($definition['command'] ?? '');
        parent::__construct($this->plugin_command);
    }

    protected function configure(): void {
        configureTinymashCommand(
            $this,
            array_merge($this->command_definition, [
                'name' => $this->plugin_command,
                'description' => (string) ($this->command_definition['summary'] ?? 'Run a plugin command.'),
            ])
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        validateTinymashCommandInput($input, $this->command_definition);
        guardRootUserForCliCommand(false, (bool) $input->getOption('allow-root'));
        if (!runPluginCliCommand($this->plugin_command, buildTinymashHandlerArguments($input, $this->command_definition))) {
            throw new \RuntimeException('Plugin command is unavailable.');
        }

        return Command::SUCCESS;
    }
}

function configureTinymashCommand(Command $command, array $definition): void {
    $command->setDescription(trim((string) ($definition['description'] ?? '')));

    foreach ((array) ($definition['arguments'] ?? []) as $argument) {
        if (!is_array($argument) || empty($argument['name'])) {
            continue;
        }
        $mode = !empty($argument['required']) ? InputArgument::REQUIRED : InputArgument::OPTIONAL;
        if (!empty($argument['multiple'])) {
            $mode |= InputArgument::IS_ARRAY;
        }
        $command->addArgument(
            (string) $argument['name'],
            $mode,
            (string) ($argument['description'] ?? ''),
            $argument['default'] ?? null,
            is_array($argument['choices'] ?? null) ? $argument['choices'] : []
        );
    }

    foreach ((array) ($definition['options'] ?? []) as $option) {
        if (!is_array($option) || empty($option['name'])) {
            continue;
        }
        $mode = !empty($option['value']) ? InputOption::VALUE_REQUIRED : InputOption::VALUE_NONE;
        if (!empty($option['multiple'])) {
            $mode |= InputOption::VALUE_IS_ARRAY;
        }
        $command->addOption(
            (string) $option['name'],
            isset($option['shortcut']) ? (string) $option['shortcut'] : null,
            $mode,
            (string) ($option['description'] ?? ''),
            $option['default'] ?? null,
            is_array($option['choices'] ?? null) ? $option['choices'] : []
        );
    }

    $help = [];
    $examples = array_values(array_filter(array_map('trim', (array) ($definition['examples'] ?? []))));
    if ($examples !== []) {
        $help[] = "Examples:\n  " . implode("\n  ", $examples);
    }
    $notes = trim((string) ($definition['notes'] ?? ''));
    if ($notes !== '') {
        $help[] = $notes;
    }
    if ($help !== []) {
        $command->setHelp(implode("\n\n", $help));
    }
}

function validateTinymashCommandInput(InputInterface $input, array $definition): void {
    foreach ((array) ($definition['arguments'] ?? []) as $argument) {
        if (!is_array($argument) || empty($argument['name']) || empty($argument['choices'])) {
            continue;
        }
        $value = $input->getArgument((string) $argument['name']);
        if ($value !== null && $value !== '' && !in_array($value, (array) $argument['choices'], true)) {
            throw new ConsoleInvalidArgumentException(sprintf(
                'Invalid <%s> value "%s". Allowed values: %s.',
                (string) $argument['name'],
                (string) $value,
                implode(', ', (array) $argument['choices'])
            ));
        }
    }

    foreach ((array) ($definition['options'] ?? []) as $option) {
        if (!is_array($option) || empty($option['name'])) {
            continue;
        }
        $name = (string) $option['name'];
        $value = $input->getOption($name);
        if (!empty($option['required']) && ($value === null || $value === '' || $value === [])) {
            throw new ConsoleInvalidArgumentException('The --' . $name . ' option is required.');
        }
        if (!empty($option['integer']) && $value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ConsoleInvalidArgumentException('The --' . $name . ' option must be an integer.');
        }
        if (!empty($option['integer']) && $value !== null && $value !== '') {
            $integer_value = (int) $value;
            if (isset($option['min']) && $integer_value < (int) $option['min']) {
                throw new ConsoleInvalidArgumentException('The --' . $name . ' option must be at least ' . (int) $option['min'] . '.');
            }
            if (isset($option['max']) && $integer_value > (int) $option['max']) {
                throw new ConsoleInvalidArgumentException('The --' . $name . ' option must not exceed ' . (int) $option['max'] . '.');
            }
        }
        if (!empty($option['pattern']) && $value !== null && $value !== '' && preg_match((string) $option['pattern'], (string) $value) !== 1) {
            throw new ConsoleInvalidArgumentException((string) ($option['pattern_message'] ?? ('The --' . $name . ' option has an invalid value.')));
        }
        if (!empty($option['choices']) && $value !== null && $value !== '' && !in_array($value, (array) $option['choices'], true)) {
            throw new ConsoleInvalidArgumentException(sprintf(
                'Invalid --%s value "%s". Allowed values: %s.',
                $name,
                (string) $value,
                implode(', ', (array) $option['choices'])
            ));
        }
    }

    foreach ((array) ($definition['exclusive_options'] ?? []) as $group) {
        $selected = [];
        foreach ((array) $group as $name) {
            $value = $input->getOption((string) $name);
            if ($value !== null && $value !== false && $value !== '' && $value !== []) {
                $selected[] = '--' . $name;
            }
        }
        if (count($selected) > 1) {
            throw new ConsoleInvalidArgumentException('Use only one of ' . implode(' or ', $selected) . '.');
        }
    }

    foreach ((array) ($definition['paired_options'] ?? []) as $group) {
        $selected = [];
        foreach ((array) $group as $name) {
            $value = $input->getOption((string) $name);
            if ($value !== null && $value !== false && $value !== '' && $value !== []) {
                $selected[] = '--' . $name;
            }
        }
        if ($selected !== [] && count($selected) !== count((array) $group)) {
            throw new ConsoleInvalidArgumentException('Use ' . implode(' and ', array_map(static fn(string $name): string => '--' . $name, (array) $group)) . ' together.');
        }
    }
}

function buildTinymashHandlerArguments(InputInterface $input, array $definition): array {
    $arguments = [];
    foreach ((array) ($definition['arguments'] ?? []) as $argument) {
        if (!is_array($argument) || empty($argument['name'])) {
            continue;
        }
        $value = $input->getArgument((string) $argument['name']);
        if (is_array($value)) {
            foreach ($value as $item) {
                $arguments[] = (string) $item;
            }
            continue;
        }
        if ($value !== null && $value !== '') {
            $arguments[] = (string) $value;
        }
    }

    foreach ((array) ($definition['options'] ?? []) as $option) {
        if (!is_array($option) || empty($option['name'])) {
            continue;
        }
        $name = (string) $option['name'];
        $value = $input->getOption($name);
        if (empty($option['value'])) {
            if ($value) {
                $arguments[] = '--' . $name;
            }
            continue;
        }
        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item !== null && $item !== '') {
                $arguments[] = '--' . $name . '=' . (string) $item;
            }
        }
    }

    return $arguments;
}

class TinymashSetupCommand extends Command {
    public function __construct() {
        parent::__construct('setup');
    }

    protected function configure(): void {
        $this
            ->setDescription('Configure a deployed installation and create its first superadmin.')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'Site title')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Default public language tag', 'en')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Default timezone', 'UTC')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Superadmin username', 'admin')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Superadmin email address')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the superadmin password from STDIN')
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Read the superadmin password from a file')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Mark the site public')
            ->addOption('private', null, InputOption::VALUE_NONE, 'Keep the site private')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Allow an existing-config update or recovery of an unfinished setup')
            ->setHelp(
                "Examples:\n"
                . "  php8.4 bin/tinymash.php setup\n"
                . "  printf '%s' \"\$PASSWORD\" | php8.4 bin/tinymash.php setup --site-name=\"My Site\" --base-url=https://example.com --password-stdin --no-interaction\n\n"
                . "Existing configuration is preserved. Reruns update only setup fields after confirmation, or with --update in non-interactive mode."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        guardRootUserForCliCommand(false, (bool) $input->getOption('allow-root'));

        $service = new TinyMashSetupService(dirname(__DIR__));
        $config_exists = $service->hasConfiguration();
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

        $preflight = $service->preflight();
        $rows = [];
        foreach ($preflight['checks'] as $label => $passed) {
            $rows[] = [$label, $passed ? '<info>ok</info>' : '<error>failed</error>'];
        }
        $io->section('Preflight');
        $io->table(['Check', 'Status'], $rows);
        if (!$preflight['ok']) {
            $io->error('Fix the failed preflight checks and run setup again.');
            return Command::FAILURE;
        }
        try {
            $state = $service->inspectState();
            if ($state['state'] === 'blocked') {
                throw new \RuntimeException($state['reason']);
            }
            if ($state['state'] === 'partial') {
                if (!$input->getOption('update')) {
                    throw new \RuntimeException('An unfinished setup requires --update and the existing superadmin username.');
                }
                $io->warning('An active superadmin exists without configuration. Recovery will keep that account and write the site configuration.');
                if ($is_interactive) {
                    if (!$io->confirm('Recover this unfinished setup?', false)) {
                        return Command::SUCCESS;
                    }
                } else {
                    foreach (['--site-name', '--base-url', '--admin-user'] as $option) {
                        if (!$input->hasParameterOption($option)) {
                            throw new \RuntimeException('Unattended recovery requires explicit --site-name, --base-url, and --admin-user values.');
                        }
                    }
                }
            }
            $service->loadConfiguration();
            $setup_values = $this->collectSetupValues($input, $io, $service);
            $password = $this->collectSetupPassword($input, $io, $service, (string) $setup_values['admin_user']);
            $setup_values = $service->save($setup_values, $password, $config_exists || (bool) $input->getOption('update'));
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

    protected function collectSetupValues(InputInterface $input, SymfonyStyle $io, TinyMashSetupService $service): array {
        $defaults = $service->getDefaults();
        $site_name = $this->resolveStringValue($input, $io, 'site-name', 'Site name', $defaults['site_name'], TinyMashSetupService::normalizeSiteName(...));
        $base_url = $this->resolveStringValue($input, $io, 'base-url', 'Base URL', $defaults['base_url'], TinyMashSetupService::normalizeBaseUrl(...));
        $language = $this->resolveStringValue($input, $io, 'language', 'Default language', $defaults['language'], TinyMashSetupService::normalizeLanguage(...));
        $timezone = $this->resolveStringValue($input, $io, 'timezone', 'Timezone', $defaults['timezone'], TinyMashSetupService::normalizeTimezone(...));
        $admin_user = $this->resolveStringValue($input, $io, 'admin-user', 'Superadmin username', $defaults['admin_user'], TinyMashSetupService::normalizeUsername(...));
        $admin_email = $this->resolveStringValue($input, $io, 'admin-email', 'Admin e-mail (optional)', $defaults['admin_email'], TinyMashSetupService::normalizeEmail(...));
        $default_public = $defaults['is_public'];

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
        if (is_string($option_value) && mb_trim($option_value) !== '') {
            return $normalizer($option_value);
        }

        if (!$input->isInteractive()) {
            return $normalizer($default);
        }

        return $io->ask($question, $default, static function (?string $value) use ($normalizer): string {
            return $normalizer((string) $value);
        });
    }

    protected function collectSetupPassword(InputInterface $input, SymfonyStyle $io, TinyMashSetupService $service, string $admin_user): string {
        $existing_admin = $service->getExistingUser($admin_user);
        $password = readSetupPasswordFromInput($input);
        if ($password !== '') {
            return TinyMashSetupService::validatePassword($password);
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
        TinyMashSetupService::validatePassword($password);
        $confirmation = (string) $io->askHidden('Confirm superadmin password');
        if ($password !== $confirmation) {
            throw new \InvalidArgumentException('Superadmin passwords did not match.');
        }

        return $password;
    }

}

class TinymashUpgradePrepareCommand extends Command {
    public function __construct() {
        parent::__construct('upgrade:prepare');
    }

    protected function configure(): void {
        $this
            ->setDescription('Prepare a clean release with state from an old installation.')
            ->addArgument('old-site-directory', InputArgument::REQUIRED, 'Old tinymash installation root')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect without copying state')
            ->addOption('confirm-writes-stopped', null, InputOption::VALUE_NONE, 'Confirm that old-site writers are stopped')
            ->addOption('allow-missing-extensions', null, InputOption::VALUE_NONE, 'Allow state preparation when active plugins or selected themes are absent')
            ->setHelp(
                "Run this command from the root of a clean new release:\n"
                . "  php8.4 bin/tinymash.php upgrade:prepare /path/to/old-tinymash --dry-run\n"
                . "  php8.4 bin/tinymash.php upgrade:prepare /path/to/old-tinymash --confirm-writes-stopped --no-interaction\n\n"
                . "The command copies authoritative site state only. It does not copy runtime data or extension code, stop services, or swap directories."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $dry_run = (bool) $input->getOption('dry-run');
        guardRootUserForCliCommand($dry_run, (bool) $input->getOption('allow-root'));

        $old_site_directory = trim((string) $input->getArgument('old-site-directory'));
        $allow_missing_extensions = (bool) $input->getOption('allow-missing-extensions');

        $io->title('tinymash upgrade prepare');
        try {
            $service = new TinyMashUpgradePrepareService(dirname(__DIR__), $old_site_directory, $allow_missing_extensions);
            $inspection = $service->inspect();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->section('Installations');
        $io->table(
            ['Role', 'Directory', 'Version'],
            [
                ['Old', (string) ($inspection['source_root'] ?? $old_site_directory), (string) ($inspection['source_version'] ?? 'unknown')],
                ['New', (string) ($inspection['target_root'] ?? dirname(__DIR__)), (string) ($inspection['target_version'] ?? APP_VERSION)],
            ]
        );

        $io->section('Preflight');
        $check_rows = [];
        foreach ((array) ($inspection['checks'] ?? []) as $check) {
            $check_rows[] = [
                (string) ($check['check'] ?? ''),
                !empty($check['ok']) ? '<info>ok</info>' : '<error>failed</error>',
                (string) ($check['detail'] ?? ''),
            ];
        }
        $io->table(['Check', 'Status', 'Detail'], $check_rows);

        $inventory = (array) ($inspection['inventory'] ?? []);
        if ($inventory !== []) {
            $io->section('State transfer');
            $io->table(
                ['Files', 'Directories', 'Size'],
                [[
                    number_format((int) ($inventory['files'] ?? 0)),
                    number_format((int) ($inventory['directories_count'] ?? 0)),
                    $this->formatBytes((int) ($inventory['bytes'] ?? 0)),
                ]]
            );
        }

        $discarded_paths = (array) ($inspection['discarded_paths'] ?? []);
        if ($discarded_paths !== []) {
            $io->section('Deliberately left behind');
            $io->listing($discarded_paths);
        }

        $extensions = (array) ($inspection['extensions'] ?? []);
        $extension_review = array_merge(
            (array) ($extensions['old_plugin_directories'] ?? []),
            (array) ($extensions['old_theme_directories'] ?? [])
        );
        if ($extension_review !== []) {
            $io->section('Extension review');
            $io->listing($extension_review);
            $io->writeln('Extension code is not copied automatically.');
        }

        $missing_extensions = [];
        foreach ((array) ($extensions['missing_active_plugins'] ?? []) as $plugin_key) {
            $missing_extensions[] = 'Plugin: ' . $plugin_key;
        }
        foreach ((array) ($extensions['missing_selected_themes'] ?? []) as $theme_key) {
            $missing_extensions[] = 'Theme: ' . $theme_key;
        }
        if ($missing_extensions !== []) {
            $io->section('Missing required extensions');
            $io->listing($missing_extensions);
        }

        $review_paths = (array) ($inspection['review_paths'] ?? []);
        if ($review_paths !== []) {
            $io->section('Manual review');
            $review_rows = [];
            foreach (array_slice($review_paths, 0, 100) as $item) {
                $review_rows[] = [
                    (string) ($item['path'] ?? ''),
                    (string) ($item['type'] ?? ''),
                    number_format((int) ($item['files'] ?? 0)),
                    $this->formatBytes((int) ($item['bytes'] ?? 0)),
                    (string) ($item['guidance'] ?? ''),
                ];
            }
            $io->table(['Path', 'Type', 'Files', 'Size', 'Guidance'], $review_rows);
            if (count($review_paths) > 100) {
                $io->writeln('... ' . (count($review_paths) - 100) . ' more paths');
            }
        }

        $modified_managed_files = (array) ($inspection['modified_managed_files'] ?? []);
        if ($modified_managed_files !== []) {
            $io->section('Modified managed files');
            $io->listing(array_slice($modified_managed_files, 0, 100));
            $io->writeln('These application files are not copied over the new release. Review local changes manually.');
        }

        foreach ((array) ($inspection['warnings'] ?? []) as $warning) {
            $io->warning((string) $warning);
        }
        if (empty($inspection['ok'])) {
            foreach ((array) ($inspection['errors'] ?? []) as $error) {
                $io->error((string) $error);
            }
            return Command::FAILURE;
        }

        if ($dry_run) {
            $io->success('Dry run complete. No files were changed.');
            return Command::SUCCESS;
        }

        $writes_stopped = (bool) $input->getOption('confirm-writes-stopped');
        if (!$writes_stopped && !$input->isInteractive()) {
            $io->error('Refusing to copy live state without --confirm-writes-stopped.');
            return Command::FAILURE;
        }
        if (!$writes_stopped && !$io->confirm('Have PHP-FPM, cron, queues, and other old-site writers been stopped?', false)) {
            $io->note('Upgrade preparation aborted. No files were changed.');
            return Command::SUCCESS;
        }

        try {
            $result = $service->prepare();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('The new release now contains verified site state.');
        $io->table(
            ['Result', 'Value'],
            [
                ['Copied files', number_format((int) ($result['copied_files'] ?? 0))],
                ['Copied size', $this->formatBytes((int) ($result['copied_bytes'] ?? 0))],
                ['Deploy audit', !empty($result['audit']['ok']) ? 'ok' : 'failed'],
            ]
        );
        $io->writeln('Next: review listed operator paths and extensions, swap sibling directories, restore permissions, reload PHP-FPM, then run system:status and deploy:audit.');

        return Command::SUCCESS;
    }

    protected function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return $unit === 0 ? (string) (int) $value . ' B' : number_format($value, 1) . ' ' . $units[$unit];
    }
}

function buildTinymashConsoleApplication(bool $include_plugin_commands = true): Application {
    $application = new TinymashConsoleApplication('tinymash', APP_VERSION);
    $application->getDefinition()->addOption(new InputOption('allow-root', null, InputOption::VALUE_NONE, 'Allow mutating commands to run as root without confirmation'));
    $application->get('help')
        ->setDescription('Show help for a command.')
        ->setHelp(
            "Examples:\n"
            . "  php8.4 bin/tinymash.php help cache:warm\n"
            . "  php8.4 bin/tinymash.php cache:warm --help\n\n"
            . "Run the list command to see every available command."
        );
    $application->get('list')
        ->setDescription('List available commands.')
        ->setHelp(
            "Examples:\n"
            . "  php8.4 bin/tinymash.php list\n"
            . "  php8.4 bin/tinymash.php list --raw"
        );
    $application->get('completion')
        ->setDescription('Generate a shell completion script.')
        ->setHelp(
            "Examples:\n"
            . "  php8.4 bin/tinymash.php completion bash\n"
            . "  php8.4 bin/tinymash.php completion fish\n"
            . "  php8.4 bin/tinymash.php completion zsh\n\n"
            . "Write the output to the completion file used by your shell."
        );
    $application->addCommand(new TinymashSetupCommand());
    $application->addCommand(new TinymashUpgradePrepareCommand());

    $commands = [
        new TinymashCallableCommand([
            'name' => 'system:status',
            'description' => 'Show the current runtime status.',
            'aliases' => ['system-status'],
            'read_only' => true,
        ], static fn() => printSystemStatus()),
        new TinymashCallableCommand([
            'name' => 'system:plugins',
            'description' => 'Show installed plugin status and boot diagnostics.',
            'read_only' => true,
        ], static fn() => printSystemPluginDiagnostics()),
        new TinymashCallableCommand([
            'name' => 'system:themes',
            'description' => 'Show installed theme status and manifest diagnostics.',
            'read_only' => true,
        ], static fn() => printSystemThemeDiagnostics()),
        new TinymashCallableCommand([
            'name' => 'maintenance:status',
            'description' => 'Show whether maintenance mode is enabled.',
            'read_only' => true,
        ], static fn() => printMaintenanceStatus()),
        new TinymashCallableCommand([
            'name' => 'maintenance:on',
            'description' => 'Enable maintenance mode.',
        ], static fn() => setMaintenance(true)),
        new TinymashCallableCommand([
            'name' => 'maintenance:off',
            'description' => 'Disable maintenance mode.',
        ], static fn() => setMaintenance(false)),
        new TinymashCallableCommand([
            'name' => 'update:recover',
            'description' => 'Inspect or recover an interrupted in-app update.',
            'arguments' => [
                ['name' => 'action', 'description' => 'Recovery action', 'default' => 'status', 'choices' => ['status', 'resume', 'rollback']],
            ],
            'examples' => [
                'php8.4 bin/tinymash.php update:recover status',
                'php8.4 bin/tinymash.php update:recover resume',
                'php8.4 bin/tinymash.php update:recover rollback',
            ],
            'notes' => 'Resume completes the pending file replacement and CLI health check. Rollback is available only before the updated site resumes writes.',
        ], static fn() => null),
        new TinymashCallableCommand([
            'name' => 'cache:clear',
            'description' => 'Clear compiled templates and persistent runtime caches.',
        ], static fn() => clearCache()),
        new TinymashCallableCommand([
            'name' => 'cache:warm',
            'description' => 'Warm content, media, and public page caches.',
            'options' => [
                ['name' => 'base-url', 'value' => true, 'description' => 'Use this public base URL instead of the configured URL'],
                ['name' => 'userpass', 'value' => true, 'description' => 'Use HTTP Basic Authentication as USER:PASSWORD'],
                ['name' => 'author', 'value' => true, 'description' => 'Warm only this author space'],
                ['name' => 'entries', 'description' => 'Also warm individual content entries'],
                ['name' => 'entry-limit', 'value' => true, 'integer' => true, 'min' => 0, 'description' => 'Limit warmed entries; 0 means no limit', 'default' => 0],
            ],
            'examples' => [
                'php8.4 bin/tinymash.php cache:warm',
                'php8.4 bin/tinymash.php cache:warm --author=cajo --entries --entry-limit=50',
            ],
        ], static fn(array $arguments) => warmCache($arguments)),
        new TinymashCallableCommand([
            'name' => 'housekeeping:status',
            'description' => 'Show housekeeping policy and the last recorded run.',
            'read_only' => true,
        ], static fn() => printHousekeepingStatus()),
        new TinymashCallableCommand([
            'name' => 'housekeeping:run',
            'description' => 'Run housekeeping tasks now.',
            'options' => [
                ['name' => 'no-plugins', 'description' => 'Skip plugin housekeeping tasks'],
            ],
        ], static fn(array $arguments) => runHousekeeping(!in_array('--no-plugins', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'content:publish-due',
            'description' => 'Publish scheduled content that is due.',
        ], static fn() => runScheduledPublishing()),
        new TinymashCallableCommand([
            'name' => 'media:usage',
            'description' => 'Report stored media and where it is referenced.',
            'read_only' => true,
            'options' => [
                ['name' => 'owner', 'value' => true, 'description' => 'Limit results to root or one author slug'],
                ['name' => 'unused-only', 'description' => 'Show only media without known references'],
                ['name' => 'limit', 'value' => true, 'integer' => true, 'min' => 1, 'description' => 'Limit the number of listed records'],
            ],
        ], static fn(array $arguments) => runMediaUsage($arguments)),
        new TinymashCallableCommand([
            'name' => 'media:cleanup',
            'description' => 'Report unused media or generate missing display derivatives.',
            'read_only_when' => static fn(InputInterface $input): bool => (bool) $input->getOption('report-unused')
                || (bool) $input->getOption('dry-run')
                || !(bool) $input->getOption('generate-missing-derivatives'),
            'options' => [
                ['name' => 'dry-run', 'description' => 'Report derivative work without writing files'],
                ['name' => 'generate-missing-derivatives', 'description' => 'Create missing display and lightbox derivatives'],
                ['name' => 'report-unused', 'description' => 'Report media without known references'],
                ['name' => 'owner', 'value' => true, 'description' => 'Limit work to root or one author slug'],
                ['name' => 'limit', 'value' => true, 'integer' => true, 'min' => 1, 'description' => 'Limit the number of checked records'],
            ],
            'exclusive_options' => [['generate-missing-derivatives', 'report-unused']],
            'notes' => 'Without --generate-missing-derivatives, derivative processing is a dry run.',
        ], static fn(array $arguments) => runMediaCleanup($arguments)),
        new TinymashCallableCommand([
            'name' => 'media:orientation',
            'description' => 'Preview or normalize older JPEGs with unhandled EXIF orientation.',
            'read_only_when' => static fn(InputInterface $input): bool => !(bool) $input->getOption('apply'),
            'options' => [
                ['name' => 'apply', 'description' => 'Rewrite eligible stored JPEGs and regenerate derivatives'],
                ['name' => 'owner', 'value' => true, 'description' => 'Limit results to root or one author username'],
                ['name' => 'limit', 'value' => true, 'integer' => true, 'min' => 1, 'description' => 'Limit the number of eligible JPEGs'],
            ],
            'notes' => 'Preview first. Back up the site before applying changes to stored originals.',
        ], static fn(array $arguments) => runMediaOrientation($arguments)),
        new TinymashCallableCommand([
            'name' => 'benchmark:public',
            'description' => 'Measure response times for selected public routes.',
            'read_only' => true,
            'options' => [
                ['name' => 'base-url', 'value' => true, 'description' => 'Use this base URL instead of the configured URL'],
                ['name' => 'userpass', 'value' => true, 'description' => 'Use HTTP Basic Authentication as USER:PASSWORD'],
                ['name' => 'login-user', 'value' => true, 'description' => 'Log in to tinymash as this user before testing'],
                ['name' => 'login-pass', 'value' => true, 'description' => 'Use this tinymash login password'],
                ['name' => 'author', 'value' => true, 'description' => 'Include this author space'],
                ['name' => 'entry', 'value' => true, 'description' => 'Include this content slug or path'],
                ['name' => 'repeat', 'value' => true, 'integer' => true, 'min' => 1, 'max' => 10, 'description' => 'Request each route this many times', 'default' => 3],
            ],
            'paired_options' => [['login-user', 'login-pass']],
            'notes' => 'Use --login-user and --login-pass together. Command-line passwords may be visible to other local users; use a temporary test account when possible.',
        ], static fn(array $arguments) => runPublicBenchmark($arguments)),
        new TinymashCallableCommand([
            'name' => 'audit:remote-media',
            'description' => 'Find remote image references in stored content.',
            'read_only' => true,
            'options' => [
                ['name' => 'author', 'value' => true, 'description' => 'Scan only this author space'],
                ['name' => 'include-unpublished', 'description' => 'Also scan unpublished entries'],
            ],
        ], static fn(array $arguments) => runRemoteMediaAudit($arguments)),
        new TinymashCallableCommand([
            'name' => 'audit:shortcodes',
            'description' => 'Report unsupported shortcode candidates in stored posts and pages.',
            'read_only' => true,
            'options' => [
                ['name' => 'author', 'value' => true, 'description' => 'Scan only this author space'],
                ['name' => 'include-unpublished', 'description' => 'Also scan unpublished entries'],
            ],
            'notes' => 'Read-only. Code spans, code fences, and Markdown links are ignored. Standalone bracketed text may need review.',
        ], static fn(array $arguments) => runShortcodeAudit($arguments)),
        new TinymashCallableCommand([
            'name' => 'check-updates',
            'description' => 'Check Composer packages for updates and security advisories.',
            'options' => [
                ['name' => 'notify', 'description' => 'Send the configured system notification when needed'],
            ],
            'notes' => 'This command writes the current component report to data/runtime/components.json.',
        ], static fn(array $arguments) => runCheckUpdates(in_array('--notify', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'deploy',
            'description' => 'Build a deployable runtime tree.',
            'arguments' => [
                ['name' => 'target-directory', 'required' => true, 'description' => 'Directory to create or replace with the deploy tree'],
            ],
            'notes' => 'The target must not be the source tree or one of its parent directories.',
        ], static fn(array $arguments) => runDeploy((string) $arguments[0])),
        new TinymashCallableCommand([
            'name' => 'deploy:audit',
            'description' => 'Compare deployed files with a deploy manifest.',
            'read_only' => true,
            'arguments' => [
                ['name' => 'deployed-directory', 'required' => true, 'description' => 'Deployed tinymash directory to inspect'],
            ],
            'options' => [
                ['name' => 'manifest', 'value' => true, 'description' => 'Use this manifest instead of deploy-manifest.json'],
            ],
        ], static fn(array $arguments) => runDeployAudit($arguments)),
        new TinymashCallableCommand([
            'name' => 'export:site',
            'description' => 'Export site state to a portable directory.',
            'arguments' => [
                ['name' => 'target-directory', 'required' => true, 'description' => 'Directory to receive the export'],
            ],
            'options' => [
                ['name' => 'with-plugins', 'description' => 'Include data exported by active plugins'],
            ],
        ], static fn(array $arguments) => runExportSite((string) $arguments[0], in_array('--with-plugins', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'export:author',
            'description' => 'Export one author and their site state to a portable directory.',
            'arguments' => [
                ['name' => 'username', 'required' => true, 'description' => 'Author username to export'],
                ['name' => 'target-directory', 'required' => true, 'description' => 'Directory to receive the export'],
            ],
            'options' => [
                ['name' => 'with-plugins', 'description' => 'Include data exported by active plugins'],
            ],
        ], static fn(array $arguments) => runExportAuthor((string) $arguments[0], (string) $arguments[1], in_array('--with-plugins', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'static-export:author',
            'description' => 'Export one public author space as static files.',
            'arguments' => [
                ['name' => 'author', 'required' => true, 'description' => 'Author username or public slug to export'],
            ],
            'options' => [
                ['name' => 'base-url', 'value' => true, 'description' => 'Use this public base URL in generated files'],
                ['name' => 'output', 'value' => true, 'description' => 'Write files to this directory instead of creating an archive'],
            ],
        ], static fn(array $arguments) => runStaticExportAuthor($arguments)),
        new TinymashCallableCommand([
            'name' => 'static-export:site',
            'description' => 'Export public site pages and author spaces as static files.',
            'options' => [
                ['name' => 'base-url', 'value' => true, 'description' => 'Use this public base URL in generated files'],
                ['name' => 'exclude-author', 'value' => true, 'multiple' => true, 'description' => 'Exclude an author slug; repeat for more than one'],
                ['name' => 'output', 'value' => true, 'description' => 'Write files to this directory instead of creating an archive'],
            ],
        ], static fn(array $arguments) => runStaticExportSite($arguments)),
        new TinymashCallableCommand([
            'name' => 'import:site',
            'description' => 'Import a site export bundle into the current installation.',
            'arguments' => [
                ['name' => 'source-directory', 'required' => true, 'description' => 'Directory containing the site export'],
            ],
            'options' => [
                ['name' => 'replace-existing', 'description' => 'Replace conflicting site state'],
                ['name' => 'with-plugins', 'description' => 'Import data handled by active plugins'],
            ],
        ], static fn(array $arguments) => runImportSite((string) $arguments[0], in_array('--replace-existing', $arguments, true), in_array('--with-plugins', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'import:author',
            'description' => 'Import one author export bundle and set a new login password.',
            'arguments' => [
                ['name' => 'source-directory', 'required' => true, 'description' => 'Directory containing the author export'],
                ['name' => 'new-password', 'required' => true, 'description' => 'New login password for the imported author'],
            ],
            'options' => [
                ['name' => 'replace-existing', 'description' => 'Replace conflicting author state'],
                ['name' => 'with-plugins', 'description' => 'Import data handled by active plugins'],
            ],
            'notes' => 'The password is visible in the process list and may enter shell history. Run this command only in a trusted local shell.',
        ], static fn(array $arguments) => runImportAuthor((string) $arguments[0], (string) $arguments[1], in_array('--replace-existing', $arguments, true), in_array('--with-plugins', $arguments, true))),
        new TinymashCallableCommand([
            'name' => 'user:list',
            'description' => 'List local users, roles, and account status.',
            'read_only' => true,
        ], static fn() => listUsers()),
        new TinymashCallableCommand([
            'name' => 'user:set-password',
            'description' => 'Create a local user or replace an existing user password.',
            'arguments' => [
                ['name' => 'username', 'required' => true, 'description' => 'Local username to create or update'],
                ['name' => 'password', 'required' => true, 'description' => 'New login password'],
                ['name' => 'role', 'description' => 'Role for a new user; existing users keep their role when omitted', 'choices' => ['superadmin', 'creator']],
            ],
            'notes' => 'New users require the superadmin or creator role. Existing profiles are preserved and enabled. Passwords may appear in process lists and shell history.',
        ], static fn(array $arguments) => setUserPassword((string) $arguments[0], (string) $arguments[1], (string) ($arguments[2] ?? ''))),
    ];

    foreach ($commands as $command) {
        $application->addCommand($command);
    }

    foreach ($include_plugin_commands ? getPluginCliCommands() : [] as $plugin_command) {
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
$application = buildTinymashConsoleApplication(!$cli_setup_request && $cli_has_config);
$application->run(new ArgvInput($_SERVER['argv']));
