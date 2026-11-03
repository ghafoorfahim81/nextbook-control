<?php

namespace App\Support;

/**
 * The modules of NextBook that a package can switch on or off. The core
 * (accounting, inventory, purchases, sales) is in every package; these are
 * the optional ones, with the same keys as the ERP's config/modules.php.
 * Keep the two lists the same when the ERP gets a module.
 */
class ErpModules
{
    public const AVAILABLE = ['hr', 'owners', 'expenses', 'landed_costs', 'discount_rules'];
}
