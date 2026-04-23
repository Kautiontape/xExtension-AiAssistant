#!/usr/bin/env php
<?php
/**
 * CLI script to score pending entries via cron.
 *
 * Usage: php score-pending.php
 *
 * Must be run inside the FreshRSS container where the autoloader is available.
 * Intended to be called from cron alongside FreshRSS's feed actualization.
 */

// FreshRSS constants and autoloader
$freshrssPath = '/var/www/FreshRSS';
if (!file_exists($freshrssPath . '/constants.php')) {
	fwrite(STDERR, "FreshRSS not found at {$freshrssPath}\n");
	exit(1);
}

require $freshrssPath . '/constants.php';
require $freshrssPath . '/app/Models/EntryDAO.php';

// Bootstrap FreshRSS
require LIB_PATH . '/lib_rss.php';
FreshRSS_Context::initSystem();

// Load the default user (or first available)
$username = FreshRSS_Context::systemConf()->default_user;
if (!$username) {
	$users = listUsers();
	if (empty($users)) {
		fwrite(STDERR, "No FreshRSS users found\n");
		exit(1);
	}
	$username = $users[0];
}

FreshRSS_Context::initUser($username);
if (!FreshRSS_Context::hasUserConf()) {
	fwrite(STDERR, "Could not load user config for {$username}\n");
	exit(1);
}

// Load the extension
$extPath = __DIR__ . '/extension.php';
require_once $extPath;

$ext = new AiAssistantExtension([
	'path' => __DIR__,
	'name' => 'AI Assistant',
	'entrypoint' => 'AiAssistant',
	'type' => 'user',
]);

// Manually set user config from FreshRSS context
$ext->setUserConfiguration(FreshRSS_Context::userConf()->attributeArray('extensions')['AI Assistant'] ?? []);

$result = $ext->scorePendingEntries();

$scored = $result['scored'] ?? 0;
$status = $result['status'] ?? 'unknown';

if ($status === 'ok') {
	if ($scored > 0) {
		echo "Scored {$scored} entries\n";
	}
} else {
	$msg = $result['message'] ?? 'Unknown error';
	fwrite(STDERR, "Scoring failed: {$msg}\n");
	exit(1);
}
