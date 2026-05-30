<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('stripes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('foreign_id')->index()->comment('外鍵 ID');
            $table->string('foreign_type')->index()->comment('外鍵 Type');
            $table->string('stripe_id')->index()->nullable()->comment('Stripe ID');
            $table->text('stripe_detail')->nullable()->comment('Stripe Detail');

            $table->index(['foreign_id', 'foreign_type']);

            $this->timestampsWithIndex($table, false, false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripes');
    }
};
