import { NextResponse } from 'next/server';

const FEATURE_SET = 'lead-management-v2';

export const dynamic = 'force-dynamic';

export function GET() {
  return NextResponse.json(
    {
      application: 'Growneq CRM',
      release: process.env.GROWNEQ_RELEASE_SHA ?? process.env.NEXT_PUBLIC_RELEASE_SHA ?? 'unknown',
      feature_set: FEATURE_SET,
      features: [
        'employee-lead-transfer',
        'bulk-lead-reassignment',
        'employee-lead-summary',
        'anchored-lead-action-menu',
      ],
    },
    {
      headers: {
        'Cache-Control': 'no-store, max-age=0',
        'X-Growneq-Feature-Set': FEATURE_SET,
      },
    },
  );
}
