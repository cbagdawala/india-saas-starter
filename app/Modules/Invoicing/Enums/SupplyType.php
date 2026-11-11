<?php

namespace App\Modules\Invoicing\Enums;

enum SupplyType: string
{
    case Intra = 'intra';   // buyer state = seller state: CGST + SGST
    case Inter = 'inter';   // different state or other territory (97): IGST
    case Export = 'export'; // buyer outside India: zero-rated under LUT, or IGST (config)
}
