<?php

namespace App\Modules\Ordering\Enums;

enum FulfillmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
}
