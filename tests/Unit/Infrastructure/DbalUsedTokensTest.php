<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\DbalUsedTokens;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\UsedTokensSchemaListener;
use Phil\SpamProtectionBundle\Infrastructure\Doctrine\UsedTokensTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DbalUsedTokens::class)]
#[CoversClass(UsedTokensTable::class)]
#[CoversClass(UsedTokensSchemaListener::class)]
final class DbalUsedTokensTest extends TestCase
{
    private Connection $connection;

    private MockClock $clock;

    private DbalUsedTokens $tokens;

    protected function setUp(): void
    {
        $this->connection = self::connection();
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-14 12:00:00', new DateTimeZone('America/Toronto')));
        $this->tokens = new DbalUsedTokens($this->connection, $this->clock);
    }

    public function testANonceIsClaimedOnceForItsTimeToLive(): void
    {
        self::assertTrue($this->tokens->claim('abc', 60));
        self::assertFalse($this->tokens->claim('abc', 60));
        self::assertTrue($this->tokens->claim('def', 60));
        self::assertSame(['nonce' => 'abc', 'expires_at' => '2026-11-14 17:01:00'], $this->connection->fetchAssociative("SELECT nonce, expires_at FROM phil_spam_protection_used_token WHERE nonce = 'abc'"), 'The expiry is written in UTC.');

        $this->clock->modify('2026-11-14 12:00:59');
        self::assertFalse($this->tokens->claim('abc', 60));
        $this->clock->modify('2026-11-14 12:01:00');
        self::assertTrue($this->tokens->claim('abc', 60), 'A nonce whose token can no longer be on time takes no place.');
        self::assertSame('2026-11-14 17:02:00', $this->connection->fetchOne("SELECT expires_at FROM phil_spam_protection_used_token WHERE nonce = 'abc'"));
    }

    public function testThePurgeForgetsTheExpiredNoncesOnly(): void
    {
        $this->tokens->claim('abc', 60);
        $this->tokens->claim('def', 120);

        self::assertSame(0, $this->tokens->purge());
        $this->clock->modify('+60 seconds');
        self::assertSame(1, $this->tokens->purge());
        self::assertSame(['def'], $this->connection->fetchFirstColumn('SELECT nonce FROM phil_spam_protection_used_token'));
    }

    public function testElsewhereAUniqueViolationIsARefusedClaim(): void
    {
        $this->tokens->claim('abc', 60);
        new ReflectionProperty(Connection::class, 'platform')->setValue($this->connection, new OraclePlatform());

        self::assertFalse($this->tokens->claim('abc', 60));
        $this->clock->modify('+60 seconds');
        self::assertTrue($this->tokens->claim('abc', 60));
    }

    public function testEachPlatformInsertsItsOwnWay(): void
    {
        $into = 'INTO phil_spam_protection_used_token (nonce, expires_at) VALUES (?, ?)';

        self::assertSame(['INSERT '.$into.' ON CONFLICT (nonce) DO NOTHING', 'INSERT IGNORE '.$into, 'INSERT IGNORE '.$into, 'INSERT OR IGNORE '.$into, 'INSERT '.$into], array_map(DbalUsedTokens::insertion(...), [new PostgreSQLPlatform(), new MySQLPlatform(), new MariaDBPlatform(), new SQLitePlatform(), new OraclePlatform()]));
    }

    public function testTheTableHasOneRowPerNonce(): void
    {
        $schema = UsedTokensTable::configure(self::connection(withTable: false)->createSchemaManager()->introspectSchema());
        $table = $schema->getTable(UsedTokensTable::NAME);

        self::assertSame([[64, true, 'string'], [null, true, 'datetime_immutable']], array_map(static fn (string $name): array => [$table->getColumn($name)->getLength(), $table->getColumn($name)->getNotnull(), $table->getColumn($name)->getTypeName()], ['nonce', 'expires_at']));
        self::assertSame(['nonce'], array_map(static fn (object $name): string => $name->toString(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []));
        self::assertTrue($table->hasIndex('phil_spam_protection_used_token_expires_at'));
        self::assertSame($schema, UsedTokensTable::configure($schema));
        self::assertCount(1, $schema->getTables());
    }

    public function testTheTableJoinsTheSchemaOfItsConnectionOnly(): void
    {
        $listener = new UsedTokensSchemaListener($this->connection);

        $other = new GenerateSchemaEventArgs($this->entityManager(self::connection(withTable: false)), $this->emptySchema());
        $listener->postGenerateSchema($other);
        self::assertFalse($other->getSchema()->hasTable(UsedTokensTable::NAME));

        $own = new GenerateSchemaEventArgs($this->entityManager($this->connection), $this->emptySchema());
        $listener->postGenerateSchema($own);
        self::assertTrue($own->getSchema()->hasTable(UsedTokensTable::NAME));
    }

    public static function connection(string $path = '', bool $withTable = true): Connection
    {
        $connection = DriverManager::getConnection('' === $path ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['driver' => 'pdo_sqlite', 'path' => $path]);

        if ($withTable) {
            foreach ($connection->getDatabasePlatform()->getCreateTablesSQL(UsedTokensTable::configure($connection->createSchemaManager()->introspectSchema())->getTables()) as $sql) {
                $connection->executeStatement($sql);
            }
        }

        return $connection;
    }

    private function emptySchema(): Schema
    {
        return self::connection(withTable: false)->createSchemaManager()->introspectSchema();
    }

    private function entityManager(Connection $connection): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }
}
