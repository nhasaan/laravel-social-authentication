<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSocialProvidersTable extends Migration
{
    public function up()
    {
        Schema::create('social_providers', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 20);
            $table->enum('provider', ['google', 'facebook']);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->timestamps();
            
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('social_providers');
    }
}