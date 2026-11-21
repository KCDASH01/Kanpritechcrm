<?php

// The shared schema is owned by admin-dashboard. Run this migration through
// admin-dashboard only; this mirror keeps the API schema contract consistent.
return require __DIR__.'/../../../admin-dashboard/database/migrations/2026_11_21_000001_add_future_lead_to_lead_status_enum.php';
