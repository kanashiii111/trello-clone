<?php

declare(strict_types=1);

use App\Migration;

final class CreateNotesTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('notes', function ($table) {
            $table->integer('id', true)->primary();
            $table->string('title', 255)->nullable(false);
            $table->text('content');
            $table->dateTimeTz('created_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('notes');
    }
}
