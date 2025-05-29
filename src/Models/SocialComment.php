<?php

namespace VendorName\SocialConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialComment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'social_account_id',
        'social_post_id',
        'platform',
        'platform_comment_id',
        'platform_post_id',
        'comment',
        'commenter_id',
        'commenter_name',
        'commenter_avatar',
        'parent_id',
        'is_reply',
        'is_hidden',
        'like_count',
        'reply_count',
        'reactions',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_reply' => 'boolean',
        'is_hidden' => 'boolean',
        'like_count' => 'integer',
        'reply_count' => 'integer',
        'reactions' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the user that owns the comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('social-connect.user_model', 'App\Models\User'));
    }

    /**
     * Get the social account that owns the comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Get the post that owns the comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /**
     * Get the parent comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SocialComment::class, 'parent_id');
    }

    /**
     * Get the replies to this comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function replies(): HasMany
    {
        return $this->hasMany(SocialComment::class, 'parent_id');
    }
}
