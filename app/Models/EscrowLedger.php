<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscrowLedger extends Model
{
    use HasFactory;

    protected $table = 'escrow_ledger';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'user_id',
        'transaction_type',
        'amount',
        'balance_after',
        'idempotency_key',
        'note',
        'created_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at'    => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(EscrowOrder::class, 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
