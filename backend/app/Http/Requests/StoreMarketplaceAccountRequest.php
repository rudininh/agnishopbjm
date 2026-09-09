<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMarketplaceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('marketplace_accounts', 'account_key')],
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(['shopee', 'tiktok'])],
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['required', 'array'],
            'credentials.partner_id' => ['required_if:channel,shopee', 'integer', 'min:1'],
            'credentials.partner_key' => ['required_if:channel,shopee', 'string', 'max:500'],
            'credentials.app_key' => ['required_if:channel,tiktok', 'string', 'max:500'],
            'credentials.app_secret' => ['required_if:channel,tiktok', 'string', 'max:500'],
        ];
    }
}