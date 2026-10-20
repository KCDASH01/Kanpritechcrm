<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingMigrationIdentifierTest extends TestCase
{
    private const MYSQL_IDENTIFIER_LIMIT = 64;

    #[DataProvider('billingMigrationProvider')]
    public function test_generated_and_explicit_schema_identifiers_fit_mysql_limit(string $migration): void
    {
        $source = file_get_contents($migration);
        self::assertIsString($source);
        $identifiers = [];

        preg_match_all("/Schema::(?:create|table)\\('([^']+)'\s*,\s*function\s*\(Blueprint\s+\\\$table\)\s*\{(.*?)\n\s*\}\);/s", $source, $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            [, $table, $body] = $block;
            preg_match_all("/\\\$table->foreignId\\('([^']+)'\\)(.*?);/s", $body, $foreignKeys, PREG_SET_ORDER);
            foreach ($foreignKeys as $foreignKey) {
                [, $column, $definition] = $foreignKey;
                if (! str_contains($definition, 'constrained(')) {
                    continue;
                }
                preg_match("/indexName:\s*'([^']+)'/", $definition, $explicitName);
                $identifiers[] = $explicitName[1] ?? "{$table}_{$column}_foreign";

                if (str_contains($definition, '->unique()')) {
                    $identifiers[] = "{$table}_{$column}_unique";
                }
            }

            preg_match_all("/\\\$table->(?:uuid|string|unsignedBigInteger)\\('([^']+)'[^;]*?->(unique|index)\\(\\)/s", $body, $automaticIndexes, PREG_SET_ORDER);
            foreach ($automaticIndexes as $index) {
                $identifiers[] = "{$table}_{$index[1]}_{$index[2]}";
            }
        }

        preg_match_all("/'([^']+)'\s*\);/", $source, $quotedNames);
        foreach ($quotedNames[1] as $name) {
            if (preg_match('/^(bill_|billing_).*(?:_idx|_unique|_fk)$/', $name)) {
                $identifiers[] = $name;
            }
        }

        self::assertNotEmpty($identifiers, 'The migration identifier audit did not discover any identifiers.');
        foreach (array_unique($identifiers) as $identifier) {
            self::assertLessThanOrEqual(
                self::MYSQL_IDENTIFIER_LIMIT,
                strlen($identifier),
                "MySQL schema identifier exceeds 64 characters: {$identifier}",
            );
        }
    }

    public function test_admin_dashboard_mirrors_canonical_billing_migrations_without_copying_schema_logic(): void
    {
        $root = dirname(__DIR__, 3);
        $phaseOneMirror = file_get_contents($root.'/admin-dashboard/database/migrations/2026_10_19_000001_create_billing_foundation.php');
        self::assertStringContainsString("return require __DIR__.'/../../../crm-api/database/migrations/2026_10_19_000001_create_billing_foundation.php';", $phaseOneMirror);
        self::assertStringNotContainsString('Schema::create', $phaseOneMirror);

        $phaseThreeMirror = file_get_contents($root.'/admin-dashboard/database/migrations/2026_10_20_000001_add_phase3_billing_workflow.php');
        self::assertStringContainsString("return require __DIR__.'/../../../crm-api/database/migrations/2026_10_20_000001_add_phase3_billing_workflow.php';", $phaseThreeMirror);
        self::assertStringNotContainsString('Schema::create', $phaseThreeMirror);
    }

    public static function billingMigrationProvider(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'phase 1 canonical' => [$root.'/crm-api/database/migrations/2026_10_19_000001_create_billing_foundation.php'],
            'phase 3 canonical' => [$root.'/crm-api/database/migrations/2026_10_20_000001_add_phase3_billing_workflow.php'],
        ];
    }
}
