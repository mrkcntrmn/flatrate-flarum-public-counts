<?php

use Flarum\Extend;
use Flatrate\PublicCounts\PublicCountsController;

return [
    (new Extend\Routes('api'))
        ->post('/flatrate/public-counts', 'flatrate.public-counts', PublicCountsController::class),
];
