<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->decimal('total_revenue', 10, 2)->default(0);
            $table->integer('order_count')->default(0);
            $table->integer('customer_count')->default(0);
            $table->decimal('average_order_value', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_summaries');
    }
};
