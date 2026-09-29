<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hub extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'location_detail',
    ];

    public function lockers()
    {
        return $this->hasMany(Locker::class);
    }

    public function escrowOrders()
    {
        return $this->hasMany(EscrowOrder::class);
    }
}
