<?php
/**
 * /content/leads_index.php
 * The curated set of cities and industries used by the local lead pages
 * (/leads/{city}/{industry}) and the dynamic sitemap. Each industry entry is
 * [label, meta description hook]; each city is [name, region].
 */
return [
    'cities' => [
        'toronto'   => ['Toronto',                 'Ontario'],
        'montreal'  => ['Montreal',                'Quebec'],
        'vancouver' => ['Vancouver',               'British Columbia'],
        'calgary'   => ['Calgary',                 'Alberta'],
        'ottawa'    => ['Ottawa',                  'Ontario'],
    ],
    'industries' => [
        'plumber'     => ['Plumber',                 'plumbers without a website'],
        'roofer'      => ['Roofer',                  'roofers without a website'],
        'electrician' => ['Electrician',             'electricians without a website'],
        'landscaper'  => ['Landscaper',              'landscapers without a website'],
        'cleaner'     => ['Cleaning Service',        'cleaning companies without a website'],
        'hvac'        => ['HVAC & Heating',          'HVAC contractors without a website'],
    ],
];