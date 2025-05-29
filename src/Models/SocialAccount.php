<?php

namespace VendorName\SocialConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'platform',
        'platform_id',
        'name',
        'username',
        'email',
        'avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'token_expires_at' => 'datetime',
        'scopes' => 'array',
        'metadata' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the user that owns the social account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('social-connect.user_model', 'App\\Models\\User'));
    }

    /**
     * Check if the token has expired.
     *
     * @return bool
     */
    public function hasTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * Check if the account has a refresh token.
     *
     * @return bool
     */
    public function hasRefreshToken(): bool
    {
        return !empty($this->refresh_token);
    }

    /**
     * Scope a query to only include accounts for a specific platform.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $platform
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
