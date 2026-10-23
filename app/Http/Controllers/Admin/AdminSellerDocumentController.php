<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SellerDocument;
use App\Services\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSellerDocumentController extends Controller
{
    /**
     * Download or stream the seller document for an authenticated admin.
     */
    public function show(Request $request, SellerDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        AdminActivityLogger::log('seller.kyc_document_viewed', $document, [
            'document_id' => $document->id,
            'seller_profile_id' => $document->seller_profile_id,
            'type' => $document->type,
            'original_name' => $document->original_name,
        ]);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }
}
