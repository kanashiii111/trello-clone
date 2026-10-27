<?php

declare(strict_types=1);

use App\Migration;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('users', function ($table) {
            $table->integer('user_id', true)->primary();
            $table->string('email', 255)->nullable(false)->unique();
            $table->string('password_hash', 255)->nullable(false);
            $table->string('name', 255)->nullable(false);
            $table->dateTimeTz('created_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));
            $table->dateTimeTz('updated_datetime')->nullable(false)
                ->default(new \Illuminate\Database\Query\Expression('CURRENT_TIMESTAMP'));
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('users');
    }
}
