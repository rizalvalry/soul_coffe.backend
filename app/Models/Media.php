<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    const UPDATED_AT = null;

    protected $fillable = [
        'kind',
        'path',
        'mime',
        'bytes',
        'sha256',
        'phash',
        'exif_taken_at',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'exif_taken_at' => 'datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function refillRequestsAsEvidence(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'evidence_photo_id');
    }

    public function refillRequestsAsSignature(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'signature_id');
    }
}
