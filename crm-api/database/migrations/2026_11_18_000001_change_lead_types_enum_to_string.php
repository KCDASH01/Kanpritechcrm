<?php

// The shared schema is owned by admin-dashboard. Run this migration through
// admin-dashboard only; this mirror keeps the API schema contract consistent.
return require __DIR__.'/../../../admin-dashboard/database/migrations/2026_11_18_000001_change_lead_types_enum_to_string.php';
