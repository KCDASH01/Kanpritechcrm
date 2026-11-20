<?php

namespace Tests\Unit;

use App\Services\DynamicTargetMetricService;
use App\Services\TargetCoachService;
use App\Services\TargetProgressService;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class TargetCoachServiceTest extends TestCase
{
    private TargetCoachService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TargetCoachService(
            Mockery::mock(DynamicTargetMetricService::class),
            Mockery::mock(TargetProgressService::class),
        );
    }

    public function test_additive_projection_uses_weekdays_without_changing_actual_value(): void
    {
        $result = $this->service->analyse($this->progress(), '2026-11-02', '2026-11-13', 'Asia/Kolkata', Carbon::parse('2026-11-06', 'Asia/Kolkata'));

        $this->assertSame(50.0, $result['actual_value']);
        $this->assertSame(5, $result['elapsed_working_days']);
        $this->assertSame(5, $result['remaining_working_days']);
        $this->assertSame(10.0, $result['actual_daily_run_rate']);
        $this->assertSame(10.0, $result['required_daily_run_rate']);
        $this->assertSame(100.0, $result['projected_value']);
        $this->assertSame('on_track', $result['projection_status']);
    }

    public function test_ratio_projection_is_not_fabricated(): void
    {
        $progress = $this->progress() + [];
        $progress['unit_type'] = 'percentage';
        $progress['metric_source'] = 'lead_conversion_ratio';

        $result = $this->service->analyse($progress, '2026-11-02', '2026-11-30', 'Asia/Kolkata', Carbon::parse('2026-11-06', 'Asia/Kolkata'));

        $this->assertFalse($result['projection_available']);
        $this->assertNull($result['projected_value']);
        $this->assertSame('unavailable', $result['projection_status']);
        $this->assertSame('Projection unavailable for this target type.', $result['projection_note']);
    }

    public function test_exceeded_target_is_reported_without_negative_shortfall(): void
    {
        $progress = $this->progress();
        $progress['actual_value'] = 125.0;
        $progress['remaining_value'] = 0.0;

        $result = $this->service->analyse($progress, '2026-11-02', '2026-11-30', 'Asia/Kolkata', Carbon::parse('2026-11-06', 'Asia/Kolkata'));

        $this->assertSame('exceeded', $result['projection_status']);
        $this->assertSame(0.0, $result['remaining_value']);
        $this->assertSame(0.0, $result['required_daily_run_rate']);
    }

    /** @return array<string, mixed> */
    private function progress(): array
    {
        return [
            'id' => 1, 'target_name' => 'Revenue', 'target_value' => 100.0,
            'actual_value' => 50.0, 'remaining_value' => 50.0,
            'achievement_percentage' => 50.0, 'unit_type' => 'currency',
            'metric_source' => 'revenue_generated', 'direction' => 'higher',
        ];
    }
}
