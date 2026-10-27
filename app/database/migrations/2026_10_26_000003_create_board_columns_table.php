<?php

declare(strict_types=1);

use App\Migration;

final class CreateBoardColumnsTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('board_columns', function ($table) {
            $table->integer('column_id', true)->primary();
            $table->integer('board_id')->nullable(false);
            $table->integer('ordinal_position')->nullable(false)->default(0);
            $table->string('title', 255)->nullable(false);
            $table->string('color', 7);
            $table->foreign('board_id')
                ->references('board_id')
                ->on('boards')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('board_columns');
    }
}
