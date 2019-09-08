<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Install;

use Psr\Http\Message\UriInterface;

class Installation
{
    private $basePath;
    private $publicPath;
    private $storagePath;
    private $vendorPath;

    private $configPath;
    private $debug = false;
    private $baseUrl;
    private $customSettings = [];

    /** @var DatabaseConfig */
    private $dbConfig;

    /** @var AdminUser */
    private $adminUser;

    // A few instance variables to persist objects between steps.
    // Could also be local variables in build(), but this way
    // access in closures is easier. :)

    /** @var \Illuminate\Database\ConnectionInterface */
    private $db;

    public function __construct($basePath, $publicPath, $storagePath, $vendorPath)
    {
        $this->basePath = $basePath;
        $this->publicPath = $publicPath;
        $this->storagePath = $storagePath;
        $this->vendorPath = $vendorPath;
    }

    public function configPath($path)
    {
        $this->configPath = $path;

        return $this;
    }

    public function debugMode($flag)
    {
        $this->debug = $flag;

        return $this;
    }

    public function databaseConfig(DatabaseConfig $dbConfig)
    {
        $this->dbConfig = $dbConfig;

        return $this;
    }

    /**
     * @param UriInterface|string $baseUrl
     * @return $this
     */
    public function baseUrl($baseUrl)
    {
        $this->baseUrl = $this->normaliseBaseUrl($baseUrl);

        return $this;
    }

    /**
     * @param UriInterface|string $baseUrl
     * @return string
     */
    public function normaliseBaseUrl($baseUrl)
    {
        $normalisedBaseUrl = rtrim($baseUrl, '/');
        if (! preg_match('#^https?://#i', $normalisedBaseUrl)) {
            $normalisedBaseUrl = sprintf('http://%s', $normalisedBaseUrl);
        }

        $parseUrl = parse_url($normalisedBaseUrl);

        $path = $parseUrl['path'] ?? null;
        if (isset($parseUrl['path']) && strrpos($parseUrl['path'], '.php') !== false) {
            $path = substr($parseUrl['path'], 0, strrpos($parseUrl['path'], '/'));
        }

        return rtrim(
            sprintf('%s://%s%s', $parseUrl['scheme'], $parseUrl['host'], $path),
            '/'
        );
    }

    public function settings($settings)
    {
        $this->customSettings = $settings;

        return $this;
    }

    public function adminUser(AdminUser $admin)
    {
        $this->adminUser = $admin;

        return $this;
    }

    public function prerequisites(): Prerequisite\PrerequisiteInterface
    {
        return new Prerequisite\Composite(
            new Prerequisite\PhpVersion('7.1.0'),
            new Prerequisite\PhpExtensions([
                'dom',
                'gd',
                'json',
                'mbstring',
                'openssl',
                'pdo_mysql',
                'tokenizer',
            ]),
            new Prerequisite\WritablePaths([
                $this->basePath,
                $this->getAssetPath(),
                $this->storagePath,
            ])
        );
    }

    public function build(): Pipeline
    {
        $pipeline = new Pipeline;

        $pipeline->pipe(function () {
            return new Steps\ConnectToDatabase(
                $this->dbConfig,
                function ($connection) {
                    $this->db = $connection;
                }
            );
        });

        $pipeline->pipe(function () {
            return new Steps\StoreConfig(
                $this->debug, $this->dbConfig, $this->baseUrl, $this->getConfigPath()
            );
        });

        $pipeline->pipe(function () {
            return new Steps\RunMigrations($this->db, $this->getMigrationPath());
        });

        $pipeline->pipe(function () {
            return new Steps\WriteSettings($this->db, $this->customSettings);
        });

        $pipeline->pipe(function () {
            return new Steps\CreateAdminUser($this->db, $this->adminUser);
        });

        $pipeline->pipe(function () {
            return new Steps\PublishAssets($this->vendorPath, $this->getAssetPath());
        });

        $pipeline->pipe(function () {
            return new Steps\EnableBundledExtensions($this->db, $this->vendorPath, $this->getAssetPath());
        });

        return $pipeline;
    }

    private function getConfigPath()
    {
        return $this->basePath.'/'.($this->configPath ?? 'config.php');
    }

    private function getAssetPath()
    {
        return "$this->publicPath/assets";
    }

    private function getMigrationPath()
    {
        return __DIR__.'/../../migrations';
    }
}
