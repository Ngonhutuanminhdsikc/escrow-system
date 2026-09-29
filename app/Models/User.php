<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name',
        'full_name',
        'email',
        'password',
        'wallet_balance',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'wallet_balance'    => 'decimal:2',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function buyerOrders()
    {
        return $this->hasMany(EscrowOrder::class, 'buyer_id');
    }

    public function sellerOrders()
    {
        return $this->hasMany(EscrowOrder::class, 'seller_id');
    }
}
