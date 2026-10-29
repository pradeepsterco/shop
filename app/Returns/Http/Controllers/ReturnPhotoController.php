<?php

namespace App\Returns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Orders\Contracts\ReturnableOrderLookupInterface;
use App\Returns\Models\ReturnRequest;
use App\Services\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams return photos from the private disk: the customer's photos ("customer") or the seller's condition
 * report photos ("seller"). Admins need returns.view (route middleware); sellers only see their own packages.
 */
class ReturnPhotoController extends Controller
{
    public function admin(ReturnRequest $request, string $kind, int $index): StreamedResponse
    {
        $path = $this->photoPath($request, $kind, $index);

        // Thumbnails on the returns queue load every photo on each render; log a viewing once per admin, request and hour.
        if (Cache::add('return-photo-viewed:'.auth()->id().':'.$request->id.':'.$kind, true, now()->addHour())) {
            AdminActivityLogger::log('returns.return_photo_viewed', $request, ['kind' => $kind, 'index' => $index]);
        }

        return Storage::disk('local')->response($path);
    }

    public function seller(Request $http, ReturnRequest $request, string $kind, int $index, ReturnableOrderLookupInterface $orders): StreamedResponse
    {
        $user = $http->user();
        $sellerProfileId = $orders->forSellerOrder($request->seller_order_id)?->sellerProfileId;
        abort_unless($user instanceof User && $sellerProfileId !== null && (int) $user->sellerProfile?->id === $sellerProfileId, 403);

        return Storage::disk('local')->response($this->photoPath($request, $kind, $index));
    }

    protected function photoPath(ReturnRequest $request, string $kind, int $index): string
    {
        $photos = match ($kind) {
            'customer' => $request->photos ?? [],
            'seller' => $request->seller_report_photos ?? [],
            default => [],
        };

        $path = $photos[$index] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return $path;
    }
}
