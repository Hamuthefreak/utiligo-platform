<?php
/**
 * includes/lead_sources/_keywords.php
 *
 * Shared industry → search-keyword expansion used by the lead sources
 * (OSM, TomTom, Yelp …).  "restaurant" fans out to
 * ['restaurant','cafe','takeaway','bistro'] so each engine's query matches
 * the natural alias space.  Pure static dictionary — no API calls.
 *
 * Adding a source vocabulary is optional: escaping is the caller's job
 * (the values here are plain lowercase words).
 */

/**
 * @return array<string> At most $max keywords, always including the original.
 */
function lead_sources_expand_keywords(string $industry, int $max): array {
    static $dict = [
        'restaurant'   => ['restaurant', 'cafe', 'takeaway', 'bistro'],
        'cafe'         => ['cafe', 'coffee', 'bakery'],
        'plumber'      => ['plumber', 'plumbing'],
        'electrician'  => ['electrician', 'electrical'],
        'hairdresser'  => ['hairdresser', 'barber', 'salon', 'hair'],
        'salon'        => ['salon', 'hairdresser', 'barber', 'beauty'],
        'barber'       => ['barber', 'hairdresser', 'salon'],
        'gym'          => ['gym', 'fitness', 'sport'],
        'dentist'      => ['dentist', 'dental'],
        'lawyer'       => ['lawyer', 'attorney', 'solicitor'],
        'accountant'   => ['accountant', 'accounting', 'bookkeeper'],
        'florist'      => ['florist', 'flowers'],
        'pharmacy'     => ['pharmacy', 'chemist'],
        'bakery'       => ['bakery', 'bread', 'pastry'],
        'beauty'       => ['beauty', 'salon', 'spa'],
        'spa'          => ['spa', 'beauty', 'wellness'],
        'mechanic'     => ['mechanic', 'car_repair', 'garage'],
        'real estate'  => ['estate_agent', 'real_estate', 'realtor'],
        'estate_agent' => ['estate_agent', 'real_estate'],
        'vet'          => ['vet', 'veterinary', 'animal'],
        'car'          => ['car', 'car_repair', 'car_wash', 'garage'],
        'cleaning'     => ['cleaning', 'janitorial', 'cleaning_service'],
        'contractor'   => ['contractor', 'construction', 'builder'],
        'tutor'        => ['tutor', 'tutoring', 'education'],
        'hotel'        => ['hotel', 'inn', 'motel', 'bed_and_breakfast'],
        'gym'          => ['gym', 'fitness', 'crossfit'],
        'photographer' => ['photographer', 'photography'],
        'lawn'         => ['lawn_care', 'landscaping', 'gardening'],
        'landscaping'  => ['landscaping', 'lawn_care', 'gardening'],
        'bakery'       => ['bakery', 'bread', 'pastry'],
        'caterer'      => ['catering', 'caterer'],
        'moving'       => ['moving_company', 'movers'],
    ];
    $kw = mb_strtolower(trim($industry), 'UTF-8');
    $extras = $dict[$kw] ?? [];
    $list = array_unique(array_merge([$kw], $extras));
    return array_slice($list, 0, max(1, $max));
}