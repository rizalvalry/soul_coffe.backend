<?php

namespace Tests\Unit;

use PDO;
use Pdo\Mysql;
use Tests\TestCase;

class DatabasePersistentConnectionTest extends TestCase
{
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DB_PERSISTENT', 'MYSQL_ATTR_SSL_CA'] as $name) {
            $this->originalEnvironment[$name] = [
                'serverExists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'envExists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'process' => getenv($name),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $original) {
            if ($original['serverExists']) {
                $_SERVER[$name] = $original['server'];
            } else {
                unset($_SERVER[$name]);
            }

            if ($original['envExists']) {
                $_ENV[$name] = $original['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($original['process'] === false) {
                putenv($name);
            } else {
                putenv($name.'='.$original['process']);
            }
        }

        parent::tearDown();
    }

    public function test_mysql_and_mariadb_use_persistent_connections_when_enabled(): void
    {
        $this->setEnvironment('DB_PERSISTENT', 'true');

        $connections = (require base_path('config/database.php'))['connections'];

        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertSame(true, $connections[$driver]['options'][PDO::ATTR_PERSISTENT]);
        }
    }

    public function test_mysql_and_mariadb_default_to_non_persistent_connections(): void
    {
        $this->setEnvironment('DB_PERSISTENT', null);

        $connections = (require base_path('config/database.php'))['connections'];

        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertSame(false, $connections[$driver]['options'][PDO::ATTR_PERSISTENT]);
        }
    }

    public function test_mysql_and_mariadb_disable_persistence_for_explicit_false(): void
    {
        $this->setEnvironment('DB_PERSISTENT', 'false');

        $connections = (require base_path('config/database.php'))['connections'];

        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertSame(false, $connections[$driver]['options'][PDO::ATTR_PERSISTENT]);
        }
    }

    public function test_existing_ssl_ca_option_remains_when_persistence_is_enabled(): void
    {
        $this->setEnvironment('DB_PERSISTENT', 'true');
        $this->setEnvironment('MYSQL_ATTR_SSL_CA', '/test/ca.pem');

        $connections = (require base_path('config/database.php'))['connections'];
        $sslCaOption = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;

        foreach (['mysql', 'mariadb'] as $driver) {
            $this->assertSame('/test/ca.pem', $connections[$driver]['options'][$sslCaOption]);
            $this->assertSame(true, $connections[$driver]['options'][PDO::ATTR_PERSISTENT]);
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }
}
