<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'version_number' => 'integer', 'file_size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Versi dokumen tidak dapat diubah. Unggah versi baru.'));
    }
}
