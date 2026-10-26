<?php

namespace App\Shipping\Http\Controllers;

use App\Models\User;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShipmentLabelDownloadController
{
    public function __construct(
        protected ShipmentServiceInterface $shipmentService,
    ) {}

    public function download(Request $request, string $shipmentId): Response
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::findOrFail($shipmentId);

        /** @var User|null $user */
        $user = $request->user();

        // Ensure user is authorized: admin with shipping.view or seller who owns the shipment
        $isAdmin = $user && method_exists($user, 'isAdministrator') && $user->isAdministrator();
        $isOwnerSeller = $user && $user->sellerProfile && $user->sellerProfile->id === $shipment->seller_profile_id;

        if (! $isAdmin && ! $isOwnerSeller) {
            abort(403, 'Unauthorized to download this shipping label.');
        }

        $pdfBinary = $this->shipmentService->getLabelPdf($shipment);

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"shipping-label-{$shipment->awb}.pdf\"",
        ]);
    }
}
