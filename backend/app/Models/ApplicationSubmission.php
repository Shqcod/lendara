<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApplicationSubmission extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'submitted_at' => 'immutable_datetime', 'version_number' => 'integer',
            'loan_amount' => 'decimal:2', 'monthly_income' => 'decimal:2', 'monthly_expenses' => 'decimal:2',
            'existing_installments' => 'decimal:2', 'tenor_months' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Snapshot pengiriman tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Snapshot pengiriman tidak dapat dihapus.'));
    }
}
