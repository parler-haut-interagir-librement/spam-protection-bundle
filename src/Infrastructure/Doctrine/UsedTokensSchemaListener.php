<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds the table of used tokens to the schema the ORM generates for its connection, as Symfony's own stores do:
 * `doctrine:migrations:diff` writes the migration that creates it, `doctrine:schema:validate` keeps it.
 */
final readonly class UsedTokensSchemaListener
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        if ($event->getEntityManager()->getConnection() !== $this->connection) {
            return;
        }

        $event->setSchema(UsedTokensTable::configure($event->getSchema()));
    }
}
