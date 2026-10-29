<?php

namespace App\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Returns\Models\RtoIntakeReport;
use App\Services\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams RTO condition photos from the private disk. Admins need returns.view (route middleware);
 * sellers may only see photos of their own reports.
 */
class RtoPhotoController extends Controller
{
    public function admin(RtoIntakeReport $report, int $index): StreamedResponse
    {
        $path = $this->photoPath($report, $index);

        AdminActivityLogger::log('returns.rto_photo_viewed', $report, [
            'seller_order_id' => $report->seller_order_id,
            'index' => $index,
        ]);

        return Storage::disk('local')->response($path);
    }

    public function seller(Request $request, RtoIntakeReport $report, int $index): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && (int) $user->sellerProfile?->id === (int) $report->seller_profile_id, 403);

        return Storage::disk('local')->response($this->photoPath($report, $index));
    }

    protected function photoPath(RtoIntakeReport $report, int $index): string
    {
        $path = $report->photos[$index] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return $path;
    }
}
