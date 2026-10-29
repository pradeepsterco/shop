<?php

namespace App\Shipping\Enums;

enum ShipmentEventType: string
{
    case CourierUpdate = 'courier_update';
    case ShipmentCreated = 'shipment_created';
    case ShipmentCancelled = 'shipment_cancelled';
    case PartialDeliveryResolved = 'partial_delivery_resolved';
}
