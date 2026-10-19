<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/** Fields read or written by core repositories but omitted from the public schema. */
final class Version20261019_001_CompleteCoreSiteSchema extends Migration
{
    public function getDescription(): string
    {
        return 'Complete core site creation and user subscription-summary schema';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `suspended_reason` VARCHAR(40) NULL DEFAULT NULL');

        // UserRepository's canonical summary reads these even when there are
        // no subscriptions. Nullable/default fields make that core read valid;
        // no billing records or cloud functionality are created or enabled.
        $this->sql('ALTER TABLE `oci_subscriptions`
            ADD COLUMN IF NOT EXISTS `plan_key` VARCHAR(80) NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `is_lifetime` TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `stripe_subscription_id` VARCHAR(255) NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `stripe_customer_id` VARCHAR(255) NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `current_period_end` DATETIME NULL DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `trial_end` DATETIME NULL DEFAULT NULL');
    }

    public function down(): void
    {
        // These fields may have predated this additive compatibility migration
        // on an existing installation. Rollback must not drop operator data.
    }
}
