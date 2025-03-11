<?php

namespace App\Services\SocialAuth\Exceptions;

class InvalidStateException extends \Exception
{
    protected $code = 400;
}