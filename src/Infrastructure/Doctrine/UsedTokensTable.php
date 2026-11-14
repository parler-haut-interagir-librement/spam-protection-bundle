<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * The table of `DbalUsedTokens`: one row per used nonce, its primary key, and when it may be forgotten, indexed for
 * the purge.
 */
final class UsedTokensTable
{
    public const string NAME = 'phil_spam_protection_used_token';

    /** The longest nonce the table holds; the tokens of the bundle carry 32 characters. */
    public const int NONCE_LENGTH = 64;

    /**
     * The schema with the table added, for a migration or a schema listener; a schema that has it is returned as is.
     */
    public static function configure(Schema $schema): Schema
    {
        if ($schema->hasTable(self::NAME)) {
            return $schema;
        }

        return $schema->edit()->addTable(Table::editor()
            ->setUnquotedName(self::NAME)
            ->setColumns(
                Column::editor()->setUnquotedName('nonce')->setTypeName(Types::STRING)->setLength(self::NONCE_LENGTH)->create(),
                Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('nonce')->create())
            ->setIndexes(Index::editor()->setUnquotedName(self::NAME.'_expires_at')->setUnquotedColumnNames('expires_at')->create())
            ->create())->create();
    }
}
