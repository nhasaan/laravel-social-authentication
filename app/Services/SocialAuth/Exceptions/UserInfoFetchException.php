<?php

namespace App\Services\SocialAuth\Exceptions;

class UserInfoFetchException extends \Exception
{
    protected $code = 400;
}