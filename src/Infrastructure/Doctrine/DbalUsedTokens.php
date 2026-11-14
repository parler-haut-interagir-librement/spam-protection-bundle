<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Doctrine;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Override;
use Phil\SpamProtectionBundle\Application\UsedTokens;
use Psr\Clock\ClockInterface;

/**
 * The used nonces in the table of {@see UsedTokensTable}, through a Doctrine DBAL connection: the primary key on the
 * nonce makes a claim one atomic insert, so two submissions of one token never both pass, on any number of web
 * servers.
 *
 * A claim deletes the nonce's expired row, then inserts the nonce unless it is there, in one statement that does not
 * abort the transaction: `ON CONFLICT DO NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL and MariaDB,
 * `INSERT OR IGNORE` on SQLite; elsewhere, a plain insert whose unique violation is a refused claim.
 */
final readonly class DbalUsedTokens implements UsedTokens
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    #[Override]
    public function claim(string $nonce, int $ttl): bool
    {
        $now = $this->now();

        // An expired row takes no place: once it is gone, the insert decides alone.
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE nonce = ? AND expires_at <= ?', UsedTokensTable::NAME),
            [$nonce, $now],
            [Types::STRING, Types::DATETIME_IMMUTABLE],
        );

        try {
            return 0 < $this->connection->executeStatement(
                self::insertion($this->connection->getDatabasePlatform()),
                [$nonce, $now->modify(\sprintf('%+d seconds', $ttl))],
                [Types::STRING, Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Forgets the nonces whose tokens can no longer be on time; run it from a scheduled task.
     *
     * @return int how many were forgotten
     */
    public function purge(): int
    {
        return (int) $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE expires_at <= ?', UsedTokensTable::NAME),
            [$this->now()],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The insert of a claim on this platform, which leaves a row already there alone without an error where it can.
     */
    public static function insertion(AbstractPlatform $platform): string
    {
        $into = \sprintf('INTO %s (nonce, expires_at) VALUES (?, ?)', UsedTokensTable::NAME);

        return match (true) {
            $platform instanceof PostgreSQLPlatform => 'INSERT '.$into.' ON CONFLICT (nonce) DO NOTHING',
            $platform instanceof AbstractMySQLPlatform => 'INSERT IGNORE '.$into,
            $platform instanceof SQLitePlatform => 'INSERT OR IGNORE '.$into,
            default => 'INSERT '.$into,
        };
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
