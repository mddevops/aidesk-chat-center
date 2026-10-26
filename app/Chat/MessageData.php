<?php

namespace App\Chat;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessageData
{
    public static function fromRequest(Request $request, bool $operator = false): array
    {
        $data = $request->validate([
            'client_message_id' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:10000'],
            'kind' => [$operator ? 'sometimes' : 'prohibited', Rule::in(['message', 'note'])],
            'attachment_ids' => ['sometimes', 'array', 'max:5'],
            'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $body = trim($data['body'] ?? '');
        $attachments = array_map('intval', $data['attachment_ids'] ?? []);
        sort($attachments);
        if ($body === '' && $attachments === []) {
            throw ValidationException::withMessages(['body' => 'Введите сообщение или прикрепите файл.']);
        }

        return ['client_message_id' => strtolower($data['client_message_id']), 'body' => $body, 'kind' => $operator ? ($data['kind'] ?? 'message') : 'message', 'attachment_ids' => $attachments];
    }
}
