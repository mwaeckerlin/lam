<?php
// Runs before every LAM request (auto_prepend_file in lam.ini), does its work once per php-fpm start.

namespace MwaeckerlinLam;

final class Preconfigure
{
    private string $config;
    private string $data;

    public function __construct()
    {
        $this->config = rtrim(getenv('CONFIG') ?: '/etc/ldap-account-manager', '/');
        $this->data = rtrim(getenv('DATA') ?: '/var/lib/ldap-account-manager', '/');
    }

    public function run(): void
    {
        if (self::env('LAM_DISABLE_TLS_CHECK') === 'true') {
            putenv('LDAPTLS_REQCERT=never');
        }
        $key = 'mwaeckerlin-lam-preconfigured-' . @file_get_contents('/app/VERSION');
        if (function_exists('apcu_fetch') && apcu_fetch($key) === true) {
            return;
        }
        $this->restoreDefaults($this->data . '.original', $this->data);
        if (self::env('LAM_SKIP_PRECONFIGURE') !== 'true') {
            $this->createConfiguration();
        }
        if (function_exists('apcu_store')) {
            apcu_store($key, true);
        }
    }

    /** Copies every file of the image's defaults that the volume lacks, never overwrites one. */
    private function restoreDefaults(string $source, string $target): void
    {
        $this->makeDirectory($target);
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $to = $target . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                $this->restoreDefaults($entry->getPathname(), $to);
            } elseif (!file_exists($to) && !is_link($to)) {
                $this->writeOnce($to, (string) file_get_contents($entry->getPathname()));
            }
        }
    }

    private function createConfiguration(): void
    {
        $main = $this->config . '/config.cfg';
        $link = $this->data . '/config/config.cfg';
        if (!file_exists($link) && !is_link($link) && !@symlink($main, $link)) {
            throw new PreconfigureException("cannot create the link {$link} to {$main}", $this->data);
        }
        if (file_exists($link)) {
            return;
        }
        $main = (string) readlink($link);
        if (!str_starts_with($main, '/')) {
            $main = dirname($link) . '/' . $main;
        }
        $this->makeDirectory(dirname($main));
        $password = self::secret('LAM_PASSWORD');
        if ($password === '') {
            throw new PreconfigureException(
                'LAM_PASSWORD or LAM_PASSWORD_FILE is not set; it is needed once, to create the configuration',
                null
            );
        }
        $hash = self::hash($password);
        $originals = $this->data . '.original/config';

        $cfg = self::readJson($originals . '/config.cfg.sample');
        $cfg['password'] = $hash;
        $database = self::env('LAM_CONFIGURATION_DATABASE') ?: 'files';
        if (!in_array($database, ['files', 'mysql'], true)) {
            throw new PreconfigureException("LAM_CONFIGURATION_DATABASE is «{$database}», allowed are «files» and «mysql»", null);
        }
        $cfg['configDatabaseType'] = $database;
        $cfg['configDatabaseServer'] = self::env('LAM_CONFIGURATION_HOST');
        $cfg['configDatabasePort'] = self::env('LAM_CONFIGURATION_PORT');
        $cfg['configDatabaseName'] = self::env('LAM_CONFIGURATION_DATABASE_NAME');
        $cfg['configDatabaseUser'] = self::env('LAM_CONFIGURATION_USER');
        $cfg['configDatabasePassword'] = self::secret('LAM_CONFIGURATION_PASSWORD');

        $profileFile = $this->data . '/config/' . ($cfg['default'] ?? 'lam') . '.conf';
        if (!file_exists($profileFile)) {
            $this->writeOnce($profileFile, self::encode($this->profile($originals . '/unix.sample.conf', $hash)));
        }
        $this->writeOnce($main, self::encode($cfg));
    }

    private function profile(string $sample, string $hash): array
    {
        $server = self::env('LDAP_SERVER') ?: 'ldap://ldap:389';
        foreach (preg_split('/\s+/', $server) as $url) {
            if (!preg_match('#^ldaps?://[^\s"]+$#', $url)) {
                throw new PreconfigureException("LDAP_SERVER is «{$server}», every address must start with ldap:// or ldaps://", null);
            }
        }
        $language = self::env('LAM_LANG') ?: 'en_US';
        if (!preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $language)) {
            throw new PreconfigureException("LAM_LANG is «{$language}», expected a locale such as en_US or de_DE", null);
        }
        $domain = self::env('LDAP_DOMAIN') ?: 'my-domain.com';
        $base = self::env('LDAP_BASE_DN') ?: 'dc=' . implode(',dc=', explode('.', $domain));
        $profile = self::readJson($sample);
        $profile['ServerURL'] = $server;
        $profile['Admins'] = self::secret('LDAP_USER') ?: 'cn=admin,' . $base;
        $profile['defaultLanguage'] = $language . '.utf8';
        $profile['Passwd'] = $hash;
        $profile['typeSettings']['suffix_user'] = self::env('LDAP_USERS_DN') ?: $base;
        $profile['typeSettings']['suffix_group'] = self::env('LDAP_GROUPS_DN') ?: $base;
        $profile['toolSettings']['treeViewSuffix'] = $base;
        return $profile;
    }

    /** The {SSHA} form LAM writes itself: base64 of SHA-1 over password and salt, a space, base64 of the salt. */
    private static function hash(string $password): string
    {
        $salt = random_bytes(4);
        return '{SSHA}' . base64_encode(sha1($password . $salt, true)) . ' ' . base64_encode($salt);
    }

    private static function env(string $name): string
    {
        return trim((string) getenv($name));
    }

    /** A value from the variable itself or from the file named in <name>_FILE (Docker secrets). */
    private static function secret(string $name): string
    {
        $file = self::env($name . '_FILE');
        if ($file === '') {
            return self::env($name);
        }
        $value = @file_get_contents($file);
        if ($value === false) {
            throw new PreconfigureException("{$name}_FILE names {$file}, which cannot be read", null);
        }
        return trim($value);
    }

    private static function readJson(string $file): array
    {
        $json = json_decode((string) @file_get_contents($file), true);
        if (!is_array($json)) {
            throw new PreconfigureException("the LAM sample {$file} is missing or no JSON", null);
        }
        return $json;
    }

    private static function encode(array $json): string
    {
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PreconfigureException("cannot create the directory {$directory}", $directory);
        }
    }

    /** Creates the file with its whole content or leaves an existing one alone, also with parallel requests. */
    private function writeOnce(string $file, string $content): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.new';
        if (@file_put_contents($temporary, $content) === false) {
            throw new PreconfigureException("cannot write {$file}", dirname($file));
        }
        @chmod($temporary, 0600);
        $created = @link($temporary, $file);
        if (!$created && !file_exists($file)) {
            $created = @rename($temporary, $file);
        }
        @unlink($temporary);
        if (!$created && !file_exists($file)) {
            throw new PreconfigureException("cannot create {$file}", dirname($file));
        }
    }
}

final class PreconfigureException extends \RuntimeException
{
    public function __construct(string $detail, public readonly ?string $volume)
    {
        parent::__construct($detail);
    }

    public function customerMessage(): string
    {
        if ($this->volume !== null) {
            return "LDAP Account Manager cannot write its configuration: the volume at {$this->volume} is not writable for the container user. The administrator has to give the user of the container write access to the volume (for example with mwaeckerlin/allow-write-access) and restart the container.";
        }
        return 'LDAP Account Manager is not configured yet: the administrator has to correct the setting named below and restart the container.';
    }
}

if (PHP_SAPI !== 'cli') {
    try {
        (new Preconfigure())->run();
    } catch (\Throwable $e) {
        $detail = $e->getMessage();
        error_log('mwaeckerlin/lam: ' . $detail);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Retry-After: 60');
        echo $e instanceof PreconfigureException ? $e->customerMessage() : 'LDAP Account Manager cannot prepare its configuration.', "\n\n", $detail, "\n";
        exit;
    }
}
