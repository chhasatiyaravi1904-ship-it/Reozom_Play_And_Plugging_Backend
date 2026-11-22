<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current user's own document library (original Reozom "Manage Documents": allUploadDocuments,
 * view/rename/delete/send-to-email of AllDocument). Every action is limited to the user's own files.
 */
class UserDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = UserDocument::where('user_id', $request->user()->id)
            ->with('listing:id,address,city,state,zip')
            ->latest('id')
            ->get()
            ->map(fn (UserDocument $doc) => $this->payload($doc));

        return api_success($documents);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], // 10MB, same as listing uploads
        ]);

        $file = $request->file('file');
        $userId = $request->user()->id;
        $path = $file->store("users/{$userId}/documents", 'local');

        $document = UserDocument::create([
            'user_id' => $userId,
            'name' => trim((string) ($validated['name'] ?? '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'source' => UserDocument::SOURCE_UPLOAD,
        ]);

        return api_success($this->payload($document), 'Document uploaded successfully.', 201);
    }

    public function rename(Request $request, UserDocument $document): JsonResponse
    {
        $this->authorizeOwner($request, $document);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $document->update(['name' => trim($validated['name'])]);

        return api_success($this->payload($document), 'Document renamed.');
    }

    public function destroy(Request $request, UserDocument $document): JsonResponse
    {
        $this->authorizeOwner($request, $document);

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return api_success(null, 'Document deleted.');
    }

    public function download(Request $request, UserDocument $document): Response
    {
        $this->authorizeOwner($request, $document);
        if (! Storage::disk('local')->exists($document->path)) {
            return api_error('Document file not found.', 404);
        }

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';
        $filename = str_replace('"', '', $document->original_name);

        return response(Storage::disk('local')->get($document->path), 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function sendToEmail(Request $request, UserDocument $document): JsonResponse
    {
        $this->authorizeOwner($request, $document);
        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:10'],
            'emails.*' => ['required', 'email'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Storage::disk('local')->exists($document->path)) {
            return api_error('Document file not found.', 404);
        }

        $filePath = Storage::disk('local')->path($document->path);
        $name = e($document->name);
        $note = $validated['note'] ?? '';
        $sender = e($request->user()->name ?? $request->user()->email);

        try {
            Mail::send([], [], function ($message) use ($validated, $filePath, $document, $name, $note, $sender) {
                $message->to($validated['emails'])
                    ->subject("Document: {$document->name}")
                    ->html("<p>Hello,</p><p>{$sender} shared the document <strong>{$name}</strong> with you.</p>"
                        .($note !== '' ? '<p><strong>Note:</strong> '.nl2br(e($note)).'</p>' : '')
                        .'<p>Regards,<br>Reozom Real Estate</p>')
                    ->attach($filePath, ['as' => $document->original_name, 'mime' => $document->mime_type]);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send user document email', ['user_document_id' => $document->id, 'error' => $e->getMessage()]);

            return api_error('The email could not be sent. Please try again later.', 502);
        }

        return api_success(['sent_to' => $validated['emails']], 'Document has been sent to '.implode(', ', $validated['emails']));
    }

    private function authorizeOwner(Request $request, UserDocument $document): void
    {
        // 404 rather than 403 so other users' document ids are not revealed
        abort_unless($document->user_id === $request->user()->id, 404);
    }

    private function payload(UserDocument $doc): array
    {
        $listing = $doc->listing;

        return [
            'id' => $doc->id,
            'name' => $doc->name,
            'original_name' => $doc->original_name,
            'mime_type' => $doc->mime_type,
            'size' => $doc->size,
            'source' => $doc->source,
            'listing_id' => $doc->listing_id,
            'listing_address' => $listing ? trim("{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}", ' ,') : null,
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }
}
