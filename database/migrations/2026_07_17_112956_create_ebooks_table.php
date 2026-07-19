<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebooks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('file_url');

            $table->enum('format', ['pdf', 'epub', 'mobi'])
                  ->index();

            $table->unsignedBigInteger('file_size')->nullable();

            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);

            $table->boolean('is_downloadable')->default(true);
            $table->enum('access_type', ['public', 'member_only'])
                  ->default('member_only')
                  ->index();

            $table->softDeletes();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebooks');
    }
};