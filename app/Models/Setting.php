<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /**
     * Cast the raw 'value' column based on the 'type' column.
     */
    protected function castedValue()
    {
        return match ($this->type) {
            'number'  => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }

    public function getCastedValueAttribute()
    {
        return $this->castedValue();
    }

    /**
     * Quick static getter.
     * Usage: Setting::get('fine_per_day', 500)
     */
    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->casted_value : $default;
    }

    /**
     * Quick static setter/creator.
     * Usage: Setting::set('fine_per_day', 500, 'number', 'fine_settings')
     */
    public static function set(string $key, $value, string $type = 'string', ?string $group = null)
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'group' => $group]
        );
    }
}
