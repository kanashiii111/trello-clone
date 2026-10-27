<?php

declare(strict_types=1);

use App\Migration;

final class CreateCardsTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('cards', function ($table) {
            $table->integer('card_id', true)->primary();
            $table->integer('board_id')->nullable(false);
            $table->integer('column_id')->nullable(false);
            $table->integer('sort_index')->nullable(false)->default(0);
            $table->string('title', 255)->nullable(false);
            $table->text('content');
            $table->string('color', 7);
            $table->boolean('is_archived')->nullable(false)->default(false);
            $table->dateTimeTz('created_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));
            $table->dateTimeTz('updated_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));

            $table->foreign('board_id')
                ->references('board_id')
                ->on('boards')
                ->onDelete('cascade');
            $table->foreign('column_id')
                ->references('column_id')
                ->on('board_columns')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('cards');
    }
}
