<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'total_spent',
        'order_count',
    ];

    protected $casts = [
        'total_spent' => 'decimal:2',
        'order_count' => 'integer',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
