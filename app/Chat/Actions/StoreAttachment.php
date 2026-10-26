<?php

namespace App\Chat\Actions;

use App\Chat\ChatException;
use App\Models\Attachment;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreAttachment
{
    public function handle(Request $request, ?int $visitorId, ?int $operatorId): Attachment
    {
        $request->validate(['file' => ['required', 'file', 'max:'.config('chat.max_upload_kb'), 'mimetypes:image/jpeg,image/png,image/webp,application/pdf']]);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
        $base = preg_replace('/[^\pL\pN._ -]/u', '_', pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME));
        $name = mb_substr($base ?: 'Файл', 0, 100).'.'.$extension;
        $path = $file->storeAs('chat/'.app(TenantContext::class)->id(), (string) Str::uuid().'.'.$extension, 'local');
        if (! $path) {
            throw new ChatException('storage_unavailable', 'Хранилище временно недоступно.', 503);
        }
        try {
            return Attachment::create(['visitor_session_id' => $visitorId, 'operator_id' => $operatorId, 'name' => $name, 'mime' => $mime, 'size' => $file->getSize(), 'path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath())]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }
}
