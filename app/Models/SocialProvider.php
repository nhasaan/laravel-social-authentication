<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialProvider extends Model
{
    protected $fillable = [
        'msisdn',
        'provider',
        'provider_user_id',
        'email',
    ];
}
