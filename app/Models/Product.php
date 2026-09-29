<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'title',
        'current_price',
        'status',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function escrowOrders()
    {
        return $this->hasMany(EscrowOrder::class);
    }
}
