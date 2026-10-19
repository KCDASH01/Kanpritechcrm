<?php

// This migration is intentionally mirrored from crm-api/database/migrations.
// The admin-dashboard project owns the complete shared schema history, while the
// CRM API keeps feature migrations for API-local deployment and testing.

return require __DIR__.'/../../../crm-api/database/migrations/2026_10_19_000001_create_billing_foundation.php';
