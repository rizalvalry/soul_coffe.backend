<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'user_device_id', 'device_id', 'device_name', 'model',
        'ip', 'succeeded', 'reason'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'FIRST_BINDING' => 'HP pertama diikat',
            'ACTIVE_DEVICE' => 'HP aktif',
            'UNKNOWN_DEVICE' => 'HP tidak dikenal (APK lama)',
            'PENDING_TRANSITION' => 'HP baru ditandai',
            'REJECTED_TRANSITION' => 'HP ditolak, masa transisi',
            'DEVICE_PENDING' => 'Menunggu persetujuan',
            'DEVICE_REJECTED' => 'HP ditolak',
            'DEVICE_UPDATE_REQUIRED' => 'Aplikasi perlu diperbarui',
            'BAD_CREDENTIALS' => 'Nomor HP atau sandi/PIN salah',
            default => 'Login ditolak',
        };
    }
}
