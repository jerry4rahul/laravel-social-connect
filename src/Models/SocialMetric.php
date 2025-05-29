<?php

namespace VendorName\SocialConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMetric extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'social_account_id',
        'social_post_id',
        'metric_type',
        'metric_value',
        'period_start',
        'period_end',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metric_value' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    /**
     * Get the user that owns the social metric.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('social-connect.user_model', 'App\\Models\\User'));
    }

    /**
     * Get the social account that owns the metric.
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Get the social post that owns the metric.
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /**
     * Scope a query to only include metrics for a specific platform.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $platform
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePlatform($query, string $platform)
    {
        return $query->whereHas('socialAccount', function ($q) use ($platform) {
            $q->where('platform', $platform);
        });
    }

    /**
     * Scope a query to only include metrics of a specific type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeType($query, string $type)
    {
        return $query->where('metric_type', $type);
    }

    /**
     * Scope a query to only include account-level metrics.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAccountLevel($query)
    {
        return $query->whereNotNull('social_account_id')
            ->whereNull('social_post_id');
    }

    /**
     * Scope a query to only include post-level metrics.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePostLevel($query)
    {
        return $query->whereNotNull('social_post_id');
    }

    /**
     * Scope a query to only include metrics for a specific time period.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \DateTime $start
     * @param \DateTime $end
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTimePeriod($query, \DateTime $start, \DateTime $end)
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }
}
