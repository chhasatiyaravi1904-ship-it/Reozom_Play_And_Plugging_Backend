<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListingProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'status',
        'agent_id',
        'assigned_zips',
        'config',
    ];

    protected $casts = [
        'assigned_zips' => 'array',
        'config' => 'array',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
