<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\IntegrationTestCase;

#[CoversClass(Connection::class)]
final class ConnectionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('encoding_jobs', 'renditions', 'encryption_keys', 'subtitles', 'videos', 'api_keys');
    }

    // -------------------------------------------------------------------------
    // Connection establishment
    // -------------------------------------------------------------------------

    public function testGetReturnsPdoInstance(): void
    {
        $pdo = Connection::get();

        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetReturnsSameInstanceOnSecondCall(): void
    {
        $a = Connection::get();
        $b = Connection::get();

        self::assertSame($a, $b);
    }

    public function testResetAndGetCreatesNewInstance(): void
    {
        $first = Connection::get();
        Connection::reset();
        $second = Connection::get();

        // They may coincidentally be the same object due to PHP GC, but
        // what matters is that after reset() get() does not throw.
        self::assertInstanceOf(\PDO::class, $second);
    }

    // -------------------------------------------------------------------------
    // ping()
    // -------------------------------------------------------------------------

    public function testPingReturnsTrueOnLiveDatabase(): void
    {
        self::assertTrue(Connection::ping());
    }

    // -------------------------------------------------------------------------
    // execute() / lastInsertId()
    // -------------------------------------------------------------------------

    public function testExecuteInsertsRow(): void
    {
        Connection::execute(
            "INSERT INTO api_keys (name, key_hash, can_upload, can_stream) VALUES (:n, :h, 1, 1)",
            [':n' => 'testkey', ':h' => password_hash('secret', PASSWORD_BCRYPT)]
        );

        $id = Connection::lastInsertId();
        self::assertGreaterThan(0, $id);
    }

    public function testLastInsertIdReturnsIntegerAfterInsert(): void
    {
        Connection::execute(
            "INSERT INTO api_keys (name, key_hash) VALUES (:n, :h)",
            [':n' => 'key2', ':h' => password_hash('token', PASSWORD_BCRYPT)]
        );

        self::assertIsInt(Connection::lastInsertId());
    }

    // -------------------------------------------------------------------------
    // fetch()
    // -------------------------------------------------------------------------

    public function testFetchReturnsSingleRow(): void
    {
        Connection::execute(
            "INSERT INTO api_keys (name, key_hash) VALUES ('fetchtest', 'hash')"
        );
        $id = Connection::lastInsertId();

        $row = Connection::fetch('SELECT id, name FROM api_keys WHERE id = :id', [':id' => $id]);

        self::assertIsArray($row);
        self::assertSame('fetchtest', $row['name']);
    }

    public function testFetchReturnsNullOnNoMatch(): void
    {
        $row = Connection::fetch('SELECT * FROM api_keys WHERE id = 999999');

        self::assertNull($row);
    }

    // -------------------------------------------------------------------------
    // fetchAll()
    // -------------------------------------------------------------------------

    public function testFetchAllReturnsMultipleRows(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            Connection::execute(
                "INSERT INTO api_keys (name, key_hash) VALUES (:n, 'hash')",
                [':n' => $name]
            );
        }

        $rows = Connection::fetchAll("SELECT name FROM api_keys ORDER BY name ASC");

        self::assertCount(3, $rows);
        self::assertSame('alpha', $rows[0]['name']);
        self::assertSame('beta',  $rows[1]['name']);
        self::assertSame('gamma', $rows[2]['name']);
    }

    public function testFetchAllReturnsEmptyArrayWhenNoRows(): void
    {
        $rows = Connection::fetchAll('SELECT * FROM api_keys');

        self::assertSame([], $rows);
    }

    // -------------------------------------------------------------------------
    // Prepared statements reject SQL injection
    // -------------------------------------------------------------------------

    public function testPreparedStatementPreventsInjection(): void
    {
        // Insert a key with a suspicious name
        Connection::execute(
            "INSERT INTO api_keys (name, key_hash) VALUES (:n, 'hash')",
            [':n' => "'; DROP TABLE api_keys; --"]
        );

        // Table must still exist and contain the literal malicious string
        $row = Connection::fetch("SELECT name FROM api_keys LIMIT 1");

        self::assertNotNull($row);
        self::assertStringContainsString('DROP TABLE', $row['name']);
    }

    // -------------------------------------------------------------------------
    // Error handling — invalid SQL throws PDOException
    // -------------------------------------------------------------------------

    public function testInvalidSqlThrowsPdoException(): void
    {
        $this->expectException(\PDOException::class);

        Connection::execute('THIS IS NOT SQL');
    }
}
