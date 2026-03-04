<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWhatsAppMessageRequest extends FormRequest
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
            'conversation_id' => ['required', 'integer', 'exists:whatsapp_conversations,id'],
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'in:text,image,audio,video,document'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
