<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SheetOrder extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_date',
        'order_no',
        'amount',
        'client_name',
        'address',
        'phone',
        'alt_no',
        'country',
        'city',
        'product_name',
        'quantity',
        'status',
        'agent',
        'delivery_date',
        'instructions',
        'cc_email',
        'merchant',
        'order_type',
        'sheet_id',
        'sheet_name',
        'code',
        'store_name',
        'processed',
        'comments',
        'confirmed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'delivery_date' => 'date',
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'processed' => 'boolean',
            'confirmed' => 'boolean',
        ];
    }
}
