<?php

namespace App\Http\Requests\Trades;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateTradeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'offering' => ['sometimes', 'array'],
            'receiving' => ['sometimes', 'array'],
            'offering_coins' => ['required', 'integer', 'min:0'],
            'receiving_coins' => ['required', 'integer', 'min:0'],
        ];
    }
}
