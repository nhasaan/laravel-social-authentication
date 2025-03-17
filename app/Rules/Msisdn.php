<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Msisdn implements ValidationRule
{
    /**
     * Validate the mobile number format
     *
     * @param string $attribute
     * @param mixed $value
     * @param Closure $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Remove any non-numeric characters
        $cleanNumber = preg_replace('/[^0-9]/', '', $value);

        // Check if it's a Bangladesh number
        if (preg_match('/^88/', $cleanNumber)) {
            // Bangladesh number validation
            if (strlen($cleanNumber) !== 13) {
                $fail('Bangladesh mobile numbers must be 13 digits long including country code (88).');
                return;
            }

            if (!preg_match('/^8801[0-9]{9}$/', $cleanNumber)) {
                $fail('Bangladesh mobile numbers must start with 8801 followed by 9 digits.');
                return;
            }
        } else {
            // Generic mobile number validation
            if (strlen($cleanNumber) < 8 || strlen($cleanNumber) > 15) {
                $fail('Mobile number must be between 8 and 15 digits.');
                return;
            }

            if (!preg_match('/^[0-9]+$/', $cleanNumber)) {
                $fail('Mobile number can only contain digits.');
                return;
            }
        }
    }
}
