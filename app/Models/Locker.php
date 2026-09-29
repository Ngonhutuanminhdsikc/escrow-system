<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Locker extends Model
{
    use HasFactory;

    protected $fillable = [
        'hub_id',
        'locker_code',
    ];

    public function hub()
    {
        return $this->belongsTo(Hub::class);
    }

    public function escrowOrders()
    {
        return $this->hasMany(EscrowOrder::class);
    }
}
