<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row backing `ManageAiSettings`. `current()` is the only way this should ever be
 * read or written — it get-or-creates row #1 rather than letting a second row ever exist, so
 * "the AI settings" always means exactly one place, never "whichever row happened to be read".
 */
class AiSetting extends Model
{
    protected $fillable = [
        'gemini_api_key',
        'gemini_model',
    ];

    protected function casts(): array
    {
        return [
            // Reachable by anyone with database access, unlike .env — encrypted at rest so a DB
            // dump/leak doesn't also leak the live key.
            'gemini_api_key' => 'encrypted',
        ];
    }

    /**
     * Not `firstOrCreate(['id' => 1])`: `id` isn't (and shouldn't be) fillable, so its `create()`
     * call would silently drop the `id` under mass-assignment protection and let auto-increment
     * hand out whatever's next — a different row every time this runs before row #1 exists yet,
     * which is exactly the opposite of "one row, always the same one". Setting `id` as a plain
     * property assignment isn't mass assignment, so it isn't guarded.
     */
    public static function current(): self
    {
        if ($setting = static::query()->find(1)) {
            return $setting;
        }

        $setting = new static();
        $setting->id = 1;
        $setting->save();

        return $setting;
    }
}
