<?php

namespace App\Services\SocialAuth\Exceptions;

class TokenExchangeException extends \Exception
{
    protected $code = 400;
}