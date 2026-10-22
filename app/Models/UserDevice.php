<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserDevice extends Model
{
    public const ACTIVE = 'ACTIVE';

    public const PENDING = 'PENDING';

    public const REJECTED = 'REJECTED';

    public const REVOKED = 'REVOKED';

    protected $fillable = ['user_id', 'device_id', 'device_name', 'model', 'os_version', 'app_version',
        'status', 'approved_by', 'approved_at', 'last_login_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'last_login_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $device): void {
            $device->active_slot = $device->status === self::ACTIVE ? 1 : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(DeviceLoginAttempt::class);
    }

    public function maskedId(): string
    {
        return self::maskId($this->device_id);
    }

    public static function maskId(?string $id): string
    {
        if ($id === null || $id === '') {
            return 'Perangkat tidak dikenal';
        }

        return strlen($id) > 8 ? substr($id, 0, 4).'…'.substr($id, -4) : '••••';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ACTIVE => 'Aktif',
            self::PENDING => 'Menunggu',
            self::REJECTED => 'Ditolak',
            self::REVOKED => 'Dilepas',
            default => 'Tidak dikenal',
        };
    }
}
