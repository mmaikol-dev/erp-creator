<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Eloquent\Model;

class DashboardNav extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
        'route',
        'order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'json',
    ];
}
