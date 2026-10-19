<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FreshInstallMigrationTest extends TestCase
{
    public function testLegacyImportMigrationDoesNotRequireCloudOnlyPredecessorColumn(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/versions/Version20260910_001_LegacyImportTracking.php';
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(4))->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                // Fresh core schemas never have migration_free_months. MariaDB
                // aborts even an otherwise idempotent ADD COLUMN after it.
                self::assertStringNotContainsString('migration_free_months', $sql);
                return 0;
            },
        );

        (new \OCI\Database\Migrations\Version20260910_001_LegacyImportTracking($db))->up();

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `legacy_mirrored`', $statements[2]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `oci_legacy_import_runs`', $statements[3]);
    }

    public function testCoreCompatibilityAddsSiteAndCanonicalSummaryFieldsIdempotently(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/versions/Version20261019_001_CompleteCoreSiteSchema.php';
        $statements = [];
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                self::assertStringNotContainsString('INSERT', $sql);
                self::assertStringNotContainsString('DROP', $sql);
                return 0;
            },
        );
        $migration = new \OCI\Database\Migrations\Version20261019_001_CompleteCoreSiteSchema($db);
        $migration->up();
        $migration->down(); // rollback is deliberately non-destructive

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `suspended_reason`', $statements[0]);
        foreach (['plan_key', 'is_lifetime', 'stripe_subscription_id', 'stripe_customer_id', 'current_period_end', 'trial_end'] as $column) {
            self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `' . $column . '`', $statements[1]);
            self::assertStringContainsString($column, \OCI\Identity\Repository\UserRepository::CANONICAL_SUBSCRIPTION_SQL);
        }
    }
}
