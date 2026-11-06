<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $fillable = ['identity_number', 'birth_date', 'address', 'phone', 'occupation_type', 'workplace_name', 'work_duration_months'];

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date', 'work_duration_months' => 'integer'];
    }
}
