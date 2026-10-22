<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description'];

    /** Convert the stored string in 'value' to a PHP value based on 'type'. Null stays null. */
    protected function castedValue()
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'number'  => is_numeric($this->value) ? $this->value + 0 : null,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }

    /** Accessor for $setting->casted_value. */
    public function getCastedValueAttribute()
    {
        return $this->castedValue();
    }

    /** Get a setting by key, cast to its type, or $default. Ex: Setting::get('fine_per_day', 500) */
    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->casted_value : $default;
    }

    /** Create or update a setting by key. Ex: Setting::set('fine_per_day', 500, 'number', 'fine_settings') */
    public static function set(string $key, $value, string $type = 'string', ?string $group = null)
    {
        // Store booleans as 'true'/'false' and arrays as JSON so get() reads them back correctly
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        }

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'group' => $group]
        );
    }
}