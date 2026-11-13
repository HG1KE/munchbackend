<?php

namespace Tests\Unit;

use Tests\TestCase;

class BranchTimeScheduleUpdateTest extends TestCase
{
    public function test_admin_controller_supports_in_place_schedule_update(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/BranchTimeScheduleController.php'));
        $routes = file_get_contents(base_path('routes/admin.php'));
        $page = file_get_contents(resource_path('views/admin-views/business-settings/restaurant/branch-time-schedule.blade.php'));
        $partial = file_get_contents(resource_path('views/admin-views/business-settings/partials/_branch-schedule.blade.php'));

        $this->assertStringContainsString('function updateBranchSchedule', $controller);
        $this->assertStringContainsString("where('id', '!=', \$schedule->id)", $controller);
        $this->assertStringContainsString("'end_time' => 'required|date_format:H:i|after:start_time'", $controller);
        $this->assertStringContainsString("branch_time_schedule_update", $routes);
        $this->assertStringContainsString('openEditBranchScheduleModal', $page);
        $this->assertStringContainsString('saveBranchSchedule', $page);
        $this->assertStringContainsString('openEditBranchScheduleModal', $partial);
        $this->assertStringContainsString("focusField === 'end'", $page);
    }

    public function test_branch_controller_supports_in_place_schedule_update(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/BusinessSettingsController.php'));
        $routes = file_get_contents(base_path('routes/branch.php'));
        $page = file_get_contents(resource_path('views/branch-views/business-settings/branch-index.blade.php'));
        $partial = file_get_contents(resource_path('views/branch-views/business-settings/partials/_branch-schedule.blade.php'));

        $this->assertStringContainsString('function updateBranchSchedule', $controller);
        $this->assertStringContainsString("where('id', '!=', \$schedule->id)", $controller);
        $this->assertStringContainsString('update-schedule', $routes);
        $this->assertStringContainsString('openEditBranchScheduleModal', $page);
        $this->assertStringContainsString('saveBranchSchedule', $page);
        $this->assertStringContainsString('openEditBranchScheduleModal', $partial);
    }
}
