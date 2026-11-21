<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $column = $connection->selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type,
                    CHARACTER_SET_NAME AS character_set, COLLATION_NAME AS collation,
                    COLUMN_COMMENT AS column_comment
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getDatabaseName(), $connection->getTablePrefix().'leads', 'status'],
            false,
        );

        if ($column?->data_type !== 'enum') {
            return;
        }

        $values = str_getcsv(substr($column->column_type, 5, -1), ',', "'", '\\');
        if (in_array('future_lead', $values, true)) {
            return;
        }

        // Appending the member keeps the order and numeric indexes of existing statuses.
        $type = substr($column->column_type, 0, -1).",'future_lead')";
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable('leads');
        $charset = $grammar->wrap($column->character_set);
        $collation = $grammar->wrap($column->collation);
        $comment = $connection->getPdo()->quote($column->column_comment);

        $connection->statement(
            "ALTER TABLE {$table} MODIFY COLUMN `status` {$type} "
            ."CHARACTER SET {$charset} COLLATE {$collation} NOT NULL DEFAULT 'new' COMMENT {$comment}"
        );
    }

    public function down(): void
    {
        // Keep the expanded enum so a rollback cannot invalidate Future Lead records.
    }
};
