<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class ContactController
{
    public function store(Request $request): JsonResponse
    {
        if (! $this->originIsAllowed($request)) {
            return $this->failure(403);
        }
        $allowedFields = ['name', 'email', 'message', 'website', 'started_at'];
        if ($request->allFiles() !== [] || array_diff(array_keys($request->all()), $allowedFields) !== []) {
            return $this->failure(422);
        }

        $honeypot = $request->input('website');
        if (! $request->exists('website') || ($honeypot !== null && (! is_string($honeypot) || trim($honeypot) !== ''))) {
            return $this->failure(422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'message' => ['required', 'string', 'min:10', 'max:15000'],
            'website' => ['present', 'nullable', 'string', 'max:0'],
            'started_at' => ['required', 'integer'],
        ]);
        if ($validator->fails()) {
            return $this->failure(422);
        }

        $data = $validator->validated();
        $elapsedMilliseconds = (int) floor(microtime(true) * 1000) - (int) $data['started_at'];
        $minimumMilliseconds = max(1, (int) config('contact.minimum_fill_seconds')) * 1000;
        if ($elapsedMilliseconds < $minimumMilliseconds || $elapsedMilliseconds > 86_400_000) {
            return $this->failure(422);
        }

        $recipient = (string) config('contact.recipient');
        $from = (string) config('mail.from.address');
        if (in_array(config('mail.default'), ['log', 'array'], true)
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failure(503);
        }

        try {
            Mail::to($recipient)->send(new ContactInquiry(
                $data['name'],
                $data['email'],
                $data['message'],
            ));
        } catch (Throwable) {
            return $this->failure(503);
        }

        return response()->json(['ok' => true, 'message' => 'Your message has been sent.']);
    }

    private function originIsAllowed(Request $request): bool
    {
        $expected = rtrim((string) config('contact.allowed_origin'), '/');
        $actual = rtrim((string) $request->headers->get('Origin'), '/');

        return $expected !== '' && $actual !== '' && hash_equals($expected, $actual);
    }

    private function failure(int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Your message could not be sent. Please email Sebastian directly.',
        ], $status);
    }
}
