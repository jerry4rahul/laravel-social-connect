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
        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('platform', 20);
            $table->string('platform_conversation_id');
            $table->string('recipient_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_avatar')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->unique(['social_account_id', 'platform_conversation_id']);
        });

        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('social_account_id');
            $table->unsignedBigInteger('social_conversation_id');
            $table->string('platform', 20);
            $table->string('platform_message_id');
            $table->text('message');
            $table->string('sender_id');
            $table->string('sender_name')->nullable();
            $table->boolean('is_from_me')->default(false);
            $table->boolean('is_read')->default(false);
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')->onDelete('cascade');
            $table->unique(['social_conversation_id', 'platform_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
    }
};
