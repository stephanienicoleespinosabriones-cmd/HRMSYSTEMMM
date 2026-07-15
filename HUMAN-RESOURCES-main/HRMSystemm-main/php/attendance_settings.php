<?php
function attendanceSettings(): array {
    return [
        'shift_start' => '08:00:00',
        'lunch_start' => '12:00:00',
        'lunch_end' => '13:00:00',
        'shift_end' => '17:00:00',
        'auto_clock_out_grace_minutes' => 30,
        'day_off_iso' => 2,
        'day_off_label' => 'Tuesday',
        'standard_work_minutes' => 480,
    ];
}
