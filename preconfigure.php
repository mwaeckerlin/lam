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

    /** Writes a missing configuration from all variables, then applies every variable the operator set to the files in the volumes. */
    private function createConfiguration(): void
    {
        $main = $this->mainFile();
        $password = self::secret('LAM_PASSWORD');
        if (!file_exists($main)) {
            $this->makeDirectory(dirname($main));
            if ($password === '') {
                throw new PreconfigureException(
                    'LAM_PASSWORD or LAM_PASSWORD_FILE is not set; it is needed to create the configuration',
                    null
                );
            }
            $hash = self::hash($password);
            $originals = $this->data . '.original/config';
            $cfg = self::mainSettings(self::readJson($originals . '/config.cfg.sample'), true);
            $cfg['password'] = $hash;
            $profileFile = $this->profileFile($cfg);
            if (!file_exists($profileFile)) {
                $profile = self::profileSettings(self::readJson($originals . '/unix.sample.conf'), true);
                $profile['Passwd'] = $hash;
                $this->writeOnce($profileFile, self::encode($profile));
            }
            $this->writeOnce($main, self::encode($cfg));
        }

        $cfg = null;
        if ($password !== '' || self::anyGiven(self::MAIN_VARIABLES)) {
            $cfg = self::readSettings($main);
            $changed = self::mainSettings($cfg, false);
            if ($password !== '' && !self::verify($password, (string) ($changed['password'] ?? ''))) {
                $changed['password'] = self::hash($password);
            }
            if ($changed !== $cfg) {
                $this->replace($main, self::encode($changed));
            }
        }
        if ($password !== '' || self::anyGiven(self::PROFILE_VARIABLES)) {
            $profileFile = $this->profileFile($cfg ?? self::readSettings($main));
            if (file_exists($profileFile)) {
                $profile = self::readSettings($profileFile);
                $changed = self::profileSettings($profile, false);
                if ($password !== '' && !self::verify($password, (string) ($changed['Passwd'] ?? ''))) {
                    $changed['Passwd'] = self::hash($password);
                }
                if ($changed !== $profile) {
                    $this->replace($profileFile, self::encode($changed));
                }
            }
        }
    }

    private const MAIN_VARIABLES = ['LAM_CONFIGURATION_DATABASE', 'LAM_CONFIGURATION_HOST', 'LAM_CONFIGURATION_PORT',
        'LAM_CONFIGURATION_DATABASE_NAME', 'LAM_CONFIGURATION_USER', 'LAM_CONFIGURATION_PASSWORD'];
    private const PROFILE_VARIABLES = ['LDAP_SERVER', 'LDAP_DOMAIN', 'LDAP_BASE_DN', 'LDAP_USERS_DN', 'LDAP_GROUPS_DN',
        'LDAP_USER', 'LAM_LANG'];

    /** The main configuration behind the link config/config.cfg of the data volume, which is created where it is missing. */
    private function mainFile(): string
    {
        $main = $this->config . '/config.cfg';
        $link = $this->data . '/config/config.cfg';
        if (!file_exists($link) && !is_link($link) && !@symlink($main, $link)) {
            throw new PreconfigureException("cannot create the link {$link} to {$main}", $this->data);
        }
        if (!is_link($link)) {
            return $link;
        }
        $target = (string) readlink($link);
        return str_starts_with($target, '/') ? $target : dirname($link) . '/' . $target;
    }

    private function profileFile(array $cfg): string
    {
        $name = (string) ($cfg['default'] ?? 'lam');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name) || str_starts_with($name, '.')) {
            throw new PreconfigureException("the default server profile in config.cfg is «{$name}», which is no file name", null);
        }
        return $this->data . '/config/' . $name . '.conf';
    }

    /** The main configuration with the values of the variables: all of them with $all, else only those the operator set. */
    private static function mainSettings(array $cfg, bool $all): array
    {
        if ($all || self::given('LAM_CONFIGURATION_DATABASE')) {
            $database = self::env('LAM_CONFIGURATION_DATABASE') ?: 'files';
            if (!in_array($database, ['files', 'mysql'], true)) {
                throw new PreconfigureException("LAM_CONFIGURATION_DATABASE is «{$database}», allowed are «files» and «mysql»", null);
            }
            $cfg['configDatabaseType'] = $database;
        }
        $port = self::env('LAM_CONFIGURATION_PORT');
        if ($port !== '' && (!preg_match('/^[0-9]{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new PreconfigureException("LAM_CONFIGURATION_PORT is «{$port}», expected a port number from 1 to 65535", null);
        }
        $fields = [
            'configDatabaseServer' => 'LAM_CONFIGURATION_HOST',
            'configDatabasePort' => 'LAM_CONFIGURATION_PORT',
            'configDatabaseName' => 'LAM_CONFIGURATION_DATABASE_NAME',
            'configDatabaseUser' => 'LAM_CONFIGURATION_USER',
            'configDatabasePassword' => 'LAM_CONFIGURATION_PASSWORD',
        ];
        foreach ($fields as $field => $name) {
            if ($all || self::given($name)) {
                $cfg[$field] = $name === 'LAM_CONFIGURATION_PASSWORD' ? self::secret($name) : self::env($name);
            }
        }
        return $cfg;
    }

    /** The server profile with the values of the variables: all of them with $all, else only those the operator set and what derives from them. */
    private static function profileSettings(array $profile, bool $all): array
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
        $baseSet = $all || self::given('LDAP_DOMAIN') || self::given('LDAP_BASE_DN');
        if ($all || self::given('LDAP_SERVER')) {
            $profile['ServerURL'] = $server;
        }
        if ($baseSet || self::given('LDAP_USER')) {
            $profile['Admins'] = self::secret('LDAP_USER') ?: 'cn=admin,' . $base;
        }
        if ($all || self::given('LAM_LANG')) {
            $profile['defaultLanguage'] = $language . '.utf8';
        }
        if ($baseSet || self::given('LDAP_USERS_DN')) {
            $profile['typeSettings']['suffix_user'] = self::env('LDAP_USERS_DN') ?: $base;
        }
        if ($baseSet || self::given('LDAP_GROUPS_DN')) {
            $profile['typeSettings']['suffix_group'] = self::env('LDAP_GROUPS_DN') ?: $base;
        }
        if ($baseSet) {
            $profile['toolSettings']['treeViewSuffix'] = $base;
        }
        return $profile;
    }

    /** True where the operator gave the variable a value, directly or, for the variables read with secret(), through <name>_FILE. */
    private static function given(string $name): bool
    {
        return self::env($name) !== ''
            || (in_array($name, ['LAM_PASSWORD', 'LDAP_USER', 'LAM_CONFIGURATION_PASSWORD'], true) && self::env($name . '_FILE') !== '');
    }

    private static function anyGiven(array $names): bool
    {
        foreach ($names as $name) {
            if (self::given($name)) {
                return true;
            }
        }
        return false;
    }

    private static function verify(string $password, string $hash): bool
    {
        if (!preg_match('/^\{SSHA\}(\S+) (\S+)$/', $hash, $parts)) {
            return false;
        }
        $digest = base64_decode($parts[1], true);
        $salt = base64_decode($parts[2], true);
        return $digest !== false && $salt !== false && hash_equals($digest, sha1($password . $salt, true));
    }

    private static function readSettings(string $file): array
    {
        $json = json_decode((string) @file_get_contents($file), true);
        if (!is_array($json)) {
            throw new PreconfigureException(
                "{$file} is not in the JSON format of LAM 9, so the LAM_* and LDAP_* variables set for this container cannot be applied to it; convert it as the README describes or leave the variables unset",
                null
            );
        }
        return $json;
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

    /** Replaces the file with its whole content in one step, so a parallel request reads the old or the new file, never half of one. */
    private function replace(string $file, string $content): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.new';
        if (@file_put_contents($temporary, $content) === false) {
            throw new PreconfigureException("cannot write {$file}", dirname($file));
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new PreconfigureException("cannot replace {$file}", dirname($file));
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
