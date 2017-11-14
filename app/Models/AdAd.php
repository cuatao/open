<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdAd extends Model
{
    protected $fillable = [
        'id',
        'ad_adset_id',
        'name'
    ];

    public function adSet()
    {
        return $this->belongsTo(AdAdSet::class, 'id', 'ad_adset_id');
    }
}