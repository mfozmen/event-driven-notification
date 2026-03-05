<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'channel',
        'body_template',
        'variables',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'variables' => 'array',
    ];

    /**
     * @param  array<string, string>  $variables
     */
    public function render(array $variables): string
    {
        $result = $this->body_template;

        foreach ($this->variables as $variable) {
            if (! array_key_exists($variable, $variables)) {
                throw new \InvalidArgumentException("Missing required template variable: {$variable}");
            }

            $result = str_replace("{{{$variable}}}", $variables[$variable], $result);
        }

        return $result;
    }
}
