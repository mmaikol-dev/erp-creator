<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiAssistantChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:3000'],
            'mode' => ['nullable', Rule::in(['deep'])],
            'conversation_id' => ['nullable', 'integer'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required_with:history', 'string', 'max:3000'],
        ];
    }
}
