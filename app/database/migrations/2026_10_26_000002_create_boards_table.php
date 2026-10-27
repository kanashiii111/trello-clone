<?php

declare(strict_types=1);

use App\Migration;

final class CreateBoardsTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('boards', function ($table) {
            $table->integer('board_id', true)->primary();
            $table->string('title', 255)->nullable(false);
            $table->integer('owner_user_id')->nullable(false);
            $table->boolean('is_public')->nullable(false)->default(false);
            $table->dateTimeTz('created_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));
            $table->dateTimeTz('updated_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));

            $table->foreign('owner_user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('boards');
    }
}
