<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'total_sales',
        'total_revenue',
        'order_count',
        'customer_count',
        'average_order_value',
    ];

    protected $casts = [
        'date' => 'date',
        'total_sales' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'order_count' => 'integer',
        'customer_count' => 'integer',
        'average_order_value' => 'decimal:2',
    ];
}
