<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketplaceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'channel' => ['sometimes', Rule::in(['shopee', 'tiktok'])],
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
            'credentials.partner_id' => ['required_if:channel,shopee', 'integer', 'min:1'],
            'credentials.partner_key' => ['required_if:channel,shopee', 'string', 'max:500'],
            'credentials.app_key' => ['required_if:channel,tiktok', 'string', 'max:500'],
            'credentials.app_secret' => ['required_if:channel,tiktok', 'string', 'max:500'],
        ];
    }
}