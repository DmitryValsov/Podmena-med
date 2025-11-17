<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем колонку responder_id в таблицу shift_swaps.
     */
    public function up(): void
    {
        Schema::table('shift_swaps', function (Blueprint $table) {
            // На случай, если миграция запускается повторно
            if (! Schema::hasColumn('shift_swaps', 'responder_id')) {
                $table->foreignId('responder_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('requester_id');
            }
        });
    }

    /**
     * Откатываем изменение (удаляем responder_id).
     */
    public function down(): void
    {
        Schema::table('shift_swaps', function (Blueprint $table) {
            if (Schema::hasColumn('shift_swaps', 'responder_id')) {
                // Для новых версий Laravel есть удобный метод:
                // dropConstrainedForeignId('responder_id');
                // Но чтобы чуть безопаснее под SQLite, можно развернуть вручную:

                try {
                    $table->dropConstrainedForeignId('responder_id');
                } catch (\Throwable $e) {
                    // Если вдруг ругнётся (особенно на SQLite) — пробуем просто dropColumn
                    try {
                        $table->dropColumn('responder_id');
                    } catch (\Throwable $e2) {
                        // В dev-окружении можно просто игнорировать,
                        // в проде лучше залогировать
                    }
                }
            }
        });
    }
};
