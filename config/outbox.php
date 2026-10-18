<?php

return [
    'publish_mode' => env('OUTBOX_PUBLISH_MODE', 'inline'),
    'recovery_age_minutes' => (int) env('OUTBOX_RECOVERY_AGE_MINUTES', 5),
];
