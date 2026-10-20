<?php

// Keep a single canonical migration definition so both Laravel applications
// record the same migration name without maintaining duplicate schema logic.

return require __DIR__.'/../../../crm-api/database/migrations/2026_10_20_000001_add_phase3_billing_workflow.php';
