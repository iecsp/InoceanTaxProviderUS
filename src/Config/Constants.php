<?php declare(strict_types=1);

namespace InoceanTaxProviderUS\Config;

final class Constants
{
    public const RULE_NAME = 'Customers from U.S. - for Tax Provider';
    public const USA_RULE_ID = '77b5387e345f40d28641e0b6dd278410';
    public const TAX_PROVIDER_ID = '46875ffca8844b448751c635b090ea50';
    public const TAXES = [
        ['id' => 'a10512c6301e49dfaec634f169082078', 'tax_rate' => 0, 'name' => '(US) COMBINED TAX', 'position' => 0, 'tax_type' => 'COMBINED-TAX'],
        ['id' => '60cd957d3d174950874f25726c33721f', 'tax_rate' => 0, 'name' => '(US) TAX-FREE', 'position' => 0, 'tax_type' => 'TAX-FREE'],
    ];
    public const STATE_CODES = ['AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
    public const DEFAULT_COUNTRY = 'US';
}
