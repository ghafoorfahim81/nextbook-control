<?php

namespace App\Support;

/** The business types a NextBook company can have: the same values as the ERP's BusinessType enum. */
class BusinessTypes
{
    public const ALL = [
        'pharmacy', 'supermarket', 'grocery', 'manufacturing', 'retail', 'accounting', 'automobile', 'book',
        'clothing', 'hotel', 'carpet', 'electronics', 'computer', 'jewellery', 'mobile', 'pharma_manufacturing', 'ply_carpet',
    ];
}
