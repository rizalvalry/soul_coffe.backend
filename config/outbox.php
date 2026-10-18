<?php

return [
    'recovery_age_minutes' => (int) env('OUTBOX_RECOVERY_AGE_MINUTES', 5),
];
