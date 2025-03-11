<?php

namespace App\Http\Requests\API;

use App\Rules\Msisdn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class SocialAuthCallbackRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'provider' => 'required|string|in:google,facebook',
            'code' => 'required|string',
            'state' => 'required|string',
            'msisdn' => ['string', new Msisdn()],
            'otp' => ['string'],
        ];
    }
    
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}