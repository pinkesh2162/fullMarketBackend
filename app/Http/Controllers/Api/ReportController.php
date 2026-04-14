<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ListingReportMail;
use App\Mail\UserReportMail;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function reportListing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'listing_id' => [
                'required',
                Rule::exists('listings', 'id')->whereNull('deleted_at'),
            ],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return $this->reportValidationError($validator->errors()->first());
        }

        $reporter = $request->user();
        $listing = Listing::query()->find($request->input('listing_id'));
        if ($listing === null) {
            return $this->reportClientError(__('Listing not found.'));
        }

        $message = $this->sanitizeReportMessage($request->input('message'));
        if ($message === '') {
            return $this->reportClientError(__('report_message_invalid'));
        }

        $report = ListingReport::query()->create([
            'reporter_id' => $reporter->id,
            'listing_id' => $listing->id,
            'message' => $message,
            'status' => 'pending',
        ]);

        Log::info('listing_report_submitted', [
            'reporter_id' => $reporter->id,
            'listing_id' => $listing->id,
            'report_id' => $report->id,
        ]);

        $this->sendListingReportMail($reporter, $listing, $message);

        return $this->actionSuccess('report_submitted', ['success' => true]);
    }

    public function reportUser(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reported_user_id' => [
                'required',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return $this->reportValidationError($validator->errors()->first());
        }

        $reportedUserId = (int) $request->input('reported_user_id');
        $reporter = $request->user();

        if ($reportedUserId === (int) $reporter->id) {
            return $this->reportClientError(__('cannot_report_yourself'));
        }

        $reported = User::query()->find($reportedUserId);
        if ($reported === null) {
            return $this->reportClientError(__('user_not_found'));
        }

        $message = $this->sanitizeReportMessage($request->input('message'));
        if ($message === '') {
            return $this->reportClientError(__('report_message_invalid'));
        }

        $report = UserReport::query()->create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'message' => $message,
            'status' => 'pending',
        ]);

        Log::info('user_report_submitted', [
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'report_id' => $report->id,
        ]);

        $this->sendUserReportMail($reporter, $reported, $message);

        return $this->actionSuccess('report_submitted', ['success' => true]);
    }

    private function sanitizeReportMessage(mixed $raw): string
    {
        $text = is_string($raw) ? $raw : '';
        $text = strip_tags($text);
        $text = trim($text);

        return mb_substr($text, 0, 5000);
    }

    private function reportValidationError(string $firstMessage): JsonResponse
    {
        return $this->actionFailure('validation_failed', [
            'error_message' => $firstMessage,
            'error' => $firstMessage,
        ], self::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function reportClientError(string $message): JsonResponse
    {
        return $this->actionFailure('validation_failed', [
            'error_message' => $message,
            'error' => $message,
        ], self::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function sendListingReportMail(User $reporter, Listing $listing, string $message): void
    {
        $to = config('app.moderation_email');
        if (empty($to)) {
            Log::warning('listing_report_email_skipped', [
                'reason' => 'moderation_email_not_configured',
                'listing_id' => $listing->id,
            ]);

            return;
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $listingUrl = $frontend !== '' ? "{$frontend}/listing/{$listing->id}" : '';

        $payload = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'submitted_at_utc' => now()->utc()->toIso8601String(),
            'reporter_id' => $reporter->id,
            'reporter_name' => $this->displayName($reporter),
            'reporter_email' => $reporter->email ?? '',
            'listing_id' => $listing->id,
            'listing_title' => $listing->title ?? '',
            'listing_url' => $listingUrl,
            'message' => $message,
        ];

        try {
            Mail::to($to)->send(new ListingReportMail($payload));
        } catch (\Throwable $e) {
            Log::error('listing_report_mail_failed', [
                'exception' => $e->getMessage(),
                'listing_id' => $listing->id,
            ]);
        }
    }

    private function sendUserReportMail(User $reporter, User $reported, string $message): void
    {
        $to = config('app.moderation_email');
        if (empty($to)) {
            Log::warning('user_report_email_skipped', [
                'reason' => 'moderation_email_not_configured',
                'reported_user_id' => $reported->id,
            ]);

            return;
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $profileUrl = '';
        if ($frontend !== '' && ! empty($reported->unique_key)) {
            $profileUrl = $frontend.'/public-profile?'.http_build_query([
                'unique_id' => (string) $reported->unique_key,
                'type' => 'user',
            ]);
        }

        $payload = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'submitted_at_utc' => now()->utc()->toIso8601String(),
            'reporter_id' => $reporter->id,
            'reporter_name' => $this->displayName($reporter),
            'reporter_email' => $reporter->email ?? '',
            'reported_user_id' => $reported->id,
            'reported_name' => $this->displayName($reported),
            'reported_email' => $reported->email ?? '',
            'reported_unique_key' => (string) ($reported->unique_key ?? ''),
            'reported_profile_url' => $profileUrl,
            'message' => $message,
        ];

        try {
            Mail::to($to)->send(new UserReportMail($payload));
        } catch (\Throwable $e) {
            Log::error('user_report_mail_failed', [
                'exception' => $e->getMessage(),
                'reported_user_id' => $reported->id,
            ]);
        }
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : (string) ($user->email ?? 'User #'.$user->id);
    }
}
