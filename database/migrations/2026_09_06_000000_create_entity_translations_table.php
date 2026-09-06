<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumer-app copy of entity_translations for Gallery / public UGC reads.
 *
 * Published by a21/lexicon-client (`lexicon-ugc-migrations`).
 * Lexicon server keeps its own editing store and POSTs approved values here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entity_translations')) {
            Schema::create('entity_translations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('entity_type', 80);
                $table->string('entity_id', 64);
                $table->string('field', 60);
                $table->string('locale', 8);
                $table->text('value');
                $table->boolean('needs_review')->default(false);
                $table->string('origin', 32)->nullable();
                $table->boolean('locked')->default(false);
                $table->string('source_hash', 64)->nullable();
                $table->timestamps();

                $table->unique(['entity_type', 'entity_id', 'field', 'locale'], 'entity_translations_unique');
                $table->index(['entity_type', 'field', 'locale'], 'entity_translations_lookup');
            });

            return;
        }

        Schema::table('entity_translations', function (Blueprint $table): void {
            if (! Schema::hasColumn('entity_translations', 'origin')) {
                $table->string('origin', 32)->nullable()->after('needs_review');
            }
            if (! Schema::hasColumn('entity_translations', 'locked')) {
                $table->boolean('locked')->default(false)->after('origin');
            }
            if (! Schema::hasColumn('entity_translations', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->after('locked');
            }
        });
    }

    public function down(): void
    {
        // Do not drop a pre-existing app table on rollback; only drop columns
        // this migration may have added when the table already existed.
        if (! Schema::hasTable('entity_translations')) {
            return;
        }

        $drop = array_values(array_filter([
            Schema::hasColumn('entity_translations', 'origin') ? 'origin' : null,
            Schema::hasColumn('entity_translations', 'locked') ? 'locked' : null,
            Schema::hasColumn('entity_translations', 'source_hash') ? 'source_hash' : null,
        ]));

        if ($drop !== []) {
            Schema::table('entity_translations', function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }
};
