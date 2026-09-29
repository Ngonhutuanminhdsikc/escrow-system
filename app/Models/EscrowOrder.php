<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscrowOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_code',
        'buyer_id',
        'seller_id',
        'product_id',
        'hub_id',
        'locker_id',
        'order_type',
        'escrow_amount',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'escrow_amount' => 'decimal:2',
        'completed_at'  => 'datetime',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function hub()
    {
        return $this->belongsTo(Hub::class);
    }

    public function locker()
    {
        return $this->belongsTo(Locker::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(EscrowLedger::class, 'order_id');
    }

    public function disputes()
    {
        return $this->hasMany(Dispute::class, 'order_id');
    }
}
