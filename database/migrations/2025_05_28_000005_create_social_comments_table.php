<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('social_account_id');
            $table->unsignedBigInteger('social_post_id')->nullable();
            $table->string('platform', 20);
            $table->string('platform_comment_id');
            $table->string('platform_post_id')->nullable();
            $table->text('comment');
            $table->string('commenter_id');
            $table->string('commenter_name')->nullable();
            $table->string('commenter_avatar')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_reply')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->integer('like_count')->default(0);
            $table->integer('reply_count')->default(0);
            $table->json('reactions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->foreign('social_post_id')->references('id')->on('social_posts')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('social_comments')->onDelete('cascade');
            $table->unique(['social_account_id', 'platform_comment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_comments');
    }
};
