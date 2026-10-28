<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\MigrationRunner;
use App\Telegram\BotApiClient;

session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$projectRoot = dirname(__DIR__);
$configDirectory = dirname(__DIR__, 2) . '/private';
$configPath = $configDirectory . '/php-bot-config.php';
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$installed = is_file($configPath);
$message = '';
$error = '';
$done = false;

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = [
    'bot_username' => '',
    'owner_id' => '',
    'webhook_url' => '',
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
];

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals((string) $_SESSION['installer_csrf'], $csrf)) {
            throw new RuntimeException('Security token expired. Reload this page and try again.');
        }

        foreach (array_keys($old) as $key) {
            $value = $_POST[$key] ?? '';
            if (is_string($value)) {
                $old[$key] = trim($value);
            }
        }

        $token = trim((string) ($_POST['bot_token'] ?? ''));
        $username = ltrim($old['bot_username'], '@');
        $ownerId = $old['owner_id'];
        $secret = trim((string) ($_POST['webhook_secret'] ?? ''));
        $dbHost = $old['db_host'];
        $dbPort = $old['db_port'];
        $dbName = $old['db_name'];
        $dbUser = $old['db_user'];
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $webhookUrl = $old['webhook_url'];

        if ($token === '' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new RuntimeException('Enter a valid Telegram bot token from BotFather.');
        }
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            throw new RuntimeException('Bot username must be 5–32 letters, digits, or underscores, without @.');
        }
        if (!preg_match('/^\d{1,20}$/', $ownerId)) {
            throw new RuntimeException('Owner Telegram ID must be a numeric user ID.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/', $secret)) {
            throw new RuntimeException('Webhook secret must be 32–256 letters, digits, underscores, or hyphens.');
        }
        if ($dbHost === '' || strlen($dbHost) > 255 || !preg_match('/^[A-Za-z0-9._:-]+$/', $dbHost)) {
            throw new RuntimeException('Enter a valid database host.');
        }
        if (!ctype_digit($dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535) {
            throw new RuntimeException('Database port must be between 1 and 65535.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName) || !preg_match('/^[A-Za-z0-9_]+$/', $dbUser)) {
            throw new RuntimeException('Database name and username may contain only letters, digits, and underscores.');
        }
        if ($dbPassword === '') {
            throw new RuntimeException('Database password is required.');
        }
        $urlParts = parse_url($webhookUrl);
        if (!is_array($urlParts) || ($urlParts['scheme'] ?? '') !== 'https'
            || empty($urlParts['host']) || !str_ends_with((string) ($urlParts['path'] ?? ''), '/webhook.php')
            || isset($urlParts['user']) || isset($urlParts['pass'])) {
            throw new RuntimeException('Webhook URL must be an HTTPS URL ending in /webhook.php.');
        }

        if (!extension_loaded('curl') || !extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Enable PHP cURL and PDO MySQL extensions in cPanel first.');
        }
        if (PHP_VERSION_ID < 80100) {
            throw new RuntimeException('PHP 8.1 or newer is required.');
        }

        if (!is_dir($configDirectory) && !mkdir($configDirectory, 0700, true) && !is_dir($configDirectory)) {
            throw new RuntimeException('Could not create the private configuration directory. Create it outside the public web root.');
        }
        $realPrivate = realpath($configDirectory);
        $realDocroot = $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($realPrivate === false || ($realDocroot !== false
            && ($realPrivate === $realDocroot || str_starts_with($realPrivate, rtrim($realDocroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('The private configuration directory is inside the public web root. Move the project or configure a private directory outside public_html.');
        }

        $privateLog = $realPrivate . '/php-bot.log';
        $config = [
            'BOT_TOKEN' => $token,
            'BOT_USERNAME' => $username,
            'BOT_OWNER_ID' => $ownerId,
            'WEBHOOK_SECRET' => $secret,
            'WEBHOOK_URL' => $webhookUrl,
            'APP_ENV' => 'production',
            'LOG_FILE' => $privateLog,
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'DB_NAME' => $dbName,
            'DB_USER' => $dbUser,
            'DB_PASSWORD' => $dbPassword,
            'DB_CHARSET' => 'utf8mb4',
        ];

        $telegram = new BotApiClient($token);
        $me = $telegram->getMe();
        $bot = $me['result'] ?? [];
        if (!is_array($bot) || (string) ($bot['username'] ?? '') !== $username) {
            throw new RuntimeException('The token is valid, but the bot username does not match the username entered.');
        }

        $tempConfig = $realPrivate . '/php-bot-config-' . bin2hex(random_bytes(8)) . '.tmp.php';
        $phpConfig = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($tempConfig, $phpConfig, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the private configuration file. Check directory permissions.');
        }
        chmod($tempConfig, 0600);
        putenv('BOT_CONFIG_FILE=' . $tempConfig);
        $_ENV['BOT_CONFIG_FILE'] = $tempConfig;
        $_SERVER['BOT_CONFIG_FILE'] = $tempConfig;

        require_once $projectRoot . '/src/Bootstrap.php';
        $pdo = Connection::make();
        $pdo->query('SELECT 1')->fetchColumn();

        $runner = new MigrationRunner($pdo, $projectRoot . '/database/migrations');
        $applied = $runner->migrate();

        if (!rename($tempConfig, $configPath)) {
            throw new RuntimeException('Database setup succeeded, but saving the final config failed. Check private directory permissions.');
        }
        chmod($configPath, 0600);
        putenv('BOT_CONFIG_FILE=' . $configPath);

        try {
            (new BotApiClient($token))->setWebhook($webhookUrl, $secret);
            $message = 'Configuration saved, database migrations applied, and Telegram webhook configured. Applied migrations: '
                . ($applied === [] ? 'none (already up to date)' : implode(', ', $applied)) . '.';
            $done = true;
            $installed = true;
        } catch (Throwable $webhookError) {
            $message = 'Configuration and database setup succeeded, but Telegram webhook setup failed. Use the CLI command bin/set-webhook.php after checking the webhook URL and HTTPS certificate.';
            $error = $webhookError->getMessage();
            $installed = true;
        }
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        if (isset($tempConfig) && is_file($tempConfig)) {
            @unlink($tempConfig);
        }
        $error = $exception->getMessage();
    }
}

if ($installed && !$done && $message === '') {
    $message = 'This bot already has a private configuration file. The installer is locked to protect existing credentials.';
}

$defaultWebhook = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $https ? 'https' : 'https';
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
    $scriptDir = rtrim($scriptDir, '/');
    $defaultWebhook = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($scriptDir === '' ? '' : $scriptDir) . '/webhook.php';
}
if ($old['webhook_url'] === '') {
    $old['webhook_url'] = $defaultWebhook;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Bot Installer</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#121a2b;--line:#26334a;--text:#edf3ff;--muted:#9aabc4;--accent:#78a9ff;--ok:#3dd6a2;--bad:#ff7c87}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at top,#162747 0, var(--bg) 55%);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;min-height:100vh;padding:28px 14px}
main{max-width:760px;margin:0 auto}.brand{display:flex;align-items:center;gap:14px;margin-bottom:22px}.logo{width:48px;height:48px;border-radius:15px;background:#203e70;display:grid;place-items:center;font-size:24px}.muted{color:var(--muted)}h1{font-size:clamp(25px,5vw,34px);margin:0}h2{font-size:18px;margin:0 0 14px}.panel{background:rgba(18,26,43,.96);border:1px solid var(--line);border-radius:18px;padding:22px;margin:14px 0;box-shadow:0 14px 40px #0002}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}.field.full{grid-column:1/-1}label{font-size:13px;font-weight:650;color:#cbd8ee}input{width:100%;padding:12px 13px;border:1px solid #34445f;border-radius:10px;background:#0b1323;color:var(--text);font:inherit;min-width:0}input:focus{outline:2px solid var(--accent);outline-offset:1px}.hint{font-size:12px;color:var(--muted)}button,.link{display:inline-flex;justify-content:center;align-items:center;padding:12px 18px;border-radius:11px;background:var(--accent);color:#081327;border:0;font-weight:750;font-size:15px;text-decoration:none;cursor:pointer}.alert{border-radius:12px;padding:14px;margin:14px 0;overflow-wrap:anywhere}.success{background:#10372f;border:1px solid #1d705c}.failure{background:#3a2029;border:1px solid #7d3545}.steps{padding-left:22px;color:var(--muted)}code{overflow-wrap:anywhere}@media(max-width:560px){body{padding:18px 10px}.panel{padding:16px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
</style>
</head>
<body>
<main>
<header class="brand"><div class="logo">⬡</div><div><h1>PHP Bot Installer</h1><div class="muted">Telegram File Store · cPanel setup wizard</div></div></header>
<div class="panel">
<p class="muted">This wizard checks your Telegram bot, connects to MySQL, applies database migrations, saves a private configuration file, and registers the webhook.</p>
<?php if ($message !== ''): ?><div class="alert <?= $done ? 'success' : 'failure' ?>"><?= $escape($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert failure"><strong>Details:</strong> <?= $escape($error) ?></div><?php endif; ?>
<?php if ($installed): ?>
<h2>Installer locked</h2>
<p class="muted">For security, the web installer cannot overwrite an existing configuration. Remove or rename <code>public/install.php</code> after installation. If webhook registration failed, run <code>php bin/set-webhook.php</code> from CLI with BOT_CONFIG_FILE pointing to the private config.</p>
<?php else: ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $escape((string) $_SESSION['installer_csrf']) ?>">
<h2>1. Telegram bot</h2>
<div class="grid">
<div class="field"><label for="bot_token">Bot token</label><input id="bot_token" name="bot_token" type="password" required autocomplete="new-password" placeholder="Token from BotFather"></div>
<div class="field"><label for="bot_username">Bot username</label><input id="bot_username" name="bot_username" required value="<?= $escape($old['bot_username']) ?>" placeholder="my_file_store_bot"><span class="hint">Without the @ symbol.</span></div>
<div class="field"><label for="owner_id">Owner Telegram ID</label><input id="owner_id" name="owner_id" inputmode="numeric" required value="<?= $escape($old['owner_id']) ?>" placeholder="123456789"></div>
<div class="field"><label for="webhook_secret">Webhook secret</label><input id="webhook_secret" name="webhook_secret" type="password" required minlength="32" maxlength="256" autocomplete="new-password" placeholder="32+ random characters"><span class="hint">Use letters, numbers, _ or - only.</span></div>
<div class="field full"><label for="webhook_url">HTTPS webhook URL</label><input id="webhook_url" name="webhook_url" type="url" required value="<?= $escape($old['webhook_url']) ?>"><span class="hint">Must end with /webhook.php and use valid HTTPS.</span></div>
</div>
<h2>2. MySQL database</h2>
<div class="grid">
<div class="field"><label for="db_host">Database host</label><input id="db_host" name="db_host" required value="<?= $escape($old['db_host']) ?>"></div>
<div class="field"><label for="db_port">Database port</label><input id="db_port" name="db_port" inputmode="numeric" required value="<?= $escape($old['db_port']) ?>"></div>
<div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" required value="<?= $escape($old['db_name']) ?>"></div>
<div class="field"><label for="db_user">Database username</label><input id="db_user" name="db_user" required value="<?= $escape($old['db_user']) ?>"></div>
<div class="field full"><label for="db_password">Database password</label><input id="db_password" name="db_password" type="password" required autocomplete="new-password"></div>
</div>
<div class="alert failure"><strong>Before continuing:</strong> create the database and database user in cPanel. The private config must be stored outside the public web root. The wizard will refuse to continue if its private directory is web-accessible.</div>
<button type="submit">Test &amp; Install</button>
</form>
<?php endif; ?>
</div>
<p class="muted">After a successful install, remove <code>public/install.php</code> from the server. Never share bot tokens, database passwords, or webhook secrets.</p>
</main>
</body>
</html>
