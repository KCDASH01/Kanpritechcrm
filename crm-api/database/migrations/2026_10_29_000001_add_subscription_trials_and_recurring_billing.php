<?php

// The shared schema is owned by admin-dashboard. This mirrored migration is
// intentionally loaded from the canonical file so both applications keep an
// identical migration contract while one migration runner executes it.
return require dirname(__DIR__, 3).'/admin-dashboard/database/migrations/2026_10_29_000001_add_subscription_trials_and_recurring_billing.php';
