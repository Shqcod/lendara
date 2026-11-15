<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['claimed_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }
}
