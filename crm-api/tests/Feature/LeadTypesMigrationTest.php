<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class LeadTypesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('leads');
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->enum('types', [
                'webapp_development',
                'mobile_app_development',
                'website_development',
                'digital_marketing',
                'others',
            ])->nullable();
        });
    }

    public function test_migration_preserves_every_legacy_requirement_value(): void
    {
        $legacyValues = [
            'webapp_development',
            'mobile_app_development',
            'website_development',
            'digital_marketing',
            'others',
            null,
        ];

        foreach ($legacyValues as $value) {
            DB::table('leads')->insert(['types' => $value]);
        }

        $this->migration()->up();

        self::assertSame($legacyValues, DB::table('leads')->orderBy('id')->pluck('types')->all());
        self::assertSame('varchar', Schema::getColumnType('leads', 'types'));
    }

    public function test_rollback_is_refused_before_catalogue_values_can_be_truncated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('intentionally irreversible');

        $this->migration()->down();
    }

    public function test_api_migration_is_a_schema_owner_mirror(): void
    {
        $mirror = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_11_18_000001_change_lead_types_enum_to_string.php');

        self::assertIsString($mirror);
        self::assertStringContainsString("admin-dashboard/database/migrations/2026_11_18_000001_change_lead_types_enum_to_string.php", $mirror);
        self::assertStringNotContainsString('ALTER TABLE', $mirror);
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3).'/admin-dashboard/database/migrations/2026_11_18_000001_change_lead_types_enum_to_string.php';
    }
}
