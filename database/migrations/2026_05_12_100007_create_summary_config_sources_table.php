<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('summary_config_sources', function (Blueprint $table) {
            $table->foreignUlid('config_id')->index()->comment('Summary Config ID');
            $table->foreignUlid('source_id')->index()->comment('來源 ID');

            $table->primary(['config_id', 'source_id']);

            $table->comment('Summary Config 與來源的多對多');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_config_sources');
    }
};
