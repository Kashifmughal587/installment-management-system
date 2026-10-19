<?php
/**
 * Database access - PDO singleton.
 * Connects to the database named in config.php and creates it if missing.
 * No credentials are ever echoed; failures are logged safely.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Karachi');
}
date_default_timezone_set(APP_TIMEZONE);

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        // Connect without a database so we can create it if needed.
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $options);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . str_replace('`', '', DB_NAME) . '`');
    } catch (PDOException $e) {
        app_log('DB connection failed: ' . $e->getMessage());
        fatal_error('Database connection failed. Please check configuration.');
    }

    return $pdo;
}
