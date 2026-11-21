<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FutureLeadStatusMigrationTest extends TestCase
{
    private const FILE = '2026_11_21_000001_add_future_lead_to_lead_status_enum.php';

    private const LEGACY_STATUSES = [
        'new', 'contacted', 'ringing', 'important', 'converted', 'lost', 'followup', 'meeting', 'not_interested',
    ];

    #[DataProvider('mysqlSchemas')]
    public function test_mysql_enum_preserves_existing_rows_and_column_properties(array $statuses, bool $alreadyExpanded): void
    {
        $port = getenv('GROWNEQ_MIGRATION_TEST_MYSQL_PORT');
        if ($port === false) {
            $this->markTestSkipped('Set GROWNEQ_MIGRATION_TEST_MYSQL_PORT for a disposable loopback MySQL server.');
        }

        $connection = DB::build([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'database' => 'growneq_migration_test',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'prefix' => 'lead_status_'.bin2hex(random_bytes(5)).'_',
        ]);
        $schema = $connection->getSchemaBuilder();

        try {
            $schema->create('leads', function (Blueprint $table) use ($statuses) {
                $table->id();
                $table->enum('status', $statuses)->charset('utf8mb4')->collation('utf8mb4_bin')
                    ->default('new')->comment("Lead's current status")->index();
            });
            $connection->table('leads')->insert(array_map(fn ($status) => ['status' => $status], $statuses));
            $before = $this->statusColumn($connection);

            DB::usingConnection($connection->getName(), function () use ($connection, $statuses, $alreadyExpanded, $before) {
                $migration = $this->migration();
                $connection->enableQueryLog();
                $migration->up();
                $expected = $alreadyExpanded ? $statuses : [...$statuses, 'future_lead'];
                $after = $this->statusColumn($connection);
                $expectedType = "enum('".implode("','", $expected)."')";

                self::assertSame($expectedType, $after['Type']);
                self::assertSame(array_replace($before, ['Type' => $expectedType]), $after);
                self::assertSame('NO', $after['Null']);
                self::assertSame('new', $after['Default']);
                self::assertSame('utf8mb4_bin', $after['Collation']);
                self::assertSame($statuses, $connection->table('leads')->orderBy('id')->pluck('status')->all());
                self::assertSame($alreadyExpanded ? 0 : 1, $this->alterCount($connection));

                $connection->flushQueryLog();
                $migration->up();
                $futureId = $connection->table('leads')->insertGetId(['status' => 'future_lead']);
                $table = $connection->getQueryGrammar()->wrapTable('leads');
                $connection->statement("INSERT INTO {$table} () VALUES ()");
                $migration->down();

                self::assertSame(0, $this->alterCount($connection));
                self::assertSame($after, $this->statusColumn($connection));
                self::assertSame('future_lead', $connection->table('leads')->where('id', $futureId)->value('status'));
                self::assertSame('new', $connection->table('leads')->latest('id')->value('status'));
            });
        } finally {
            $schema->dropIfExists('leads');
            DB::purge($connection->getName());
        }
    }

    public static function mysqlSchemas(): array
    {
        return [
            'legacy schema' => [self::LEGACY_STATUSES, false],
            'already expanded in a different order' => [['new', 'future_lead', ...array_slice(self::LEGACY_STATUSES, 1)], true],
        ];
    }

    public function test_sqlite_accepts_future_lead_after_the_existing_schema_migrations(): void
    {
        $connection = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::usingConnection($connection->getName(), function () use ($connection) {
            $connection->getSchemaBuilder()->create('leads', function (Blueprint $table) {
                $table->id();
                $table->enum('status', self::LEGACY_STATUSES)->default('new');
                $table->enum('types', ['others'])->nullable();
            });
            $directory = dirname(__DIR__, 3).'/admin-dashboard/database/migrations/';
            (require $directory.'2026_11_18_000001_change_lead_types_enum_to_string.php')->up();

            $migration = $this->migration();
            $migration->up();
            $connection->table('leads')->insert(['status' => 'future_lead']);
            $migration->down();

            self::assertSame('future_lead', $connection->table('leads')->value('status'));
        });
    }

    private function statusColumn(Connection $connection): array
    {
        $table = $connection->getQueryGrammar()->wrapTable('leads');

        return (array) $connection->selectOne("SHOW FULL COLUMNS FROM {$table} WHERE Field = 'status'");
    }

    private function alterCount(Connection $connection): int
    {
        return count(array_filter($connection->getQueryLog(), fn ($query) => str_starts_with($query['query'], 'ALTER TABLE')));
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__, 2).'/database/migrations/'.self::FILE;
    }
}
