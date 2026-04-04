<?php

namespace App\Services\Lms;

/**
 * LMS enrollment sync. Wire your SIS/LMS API (Canvas, Google Classroom, etc.) here.
 * When disabled, the service is a no-op so production deploys stay safe until credentials exist.
 */
class LmsSyncService
{
    /**
     * @return array{ok: bool, message: string, touched: int}
     */
    public function syncDistrict(string $districtId): array
    {
        if (! config('atlaas.lms_sync_enabled', false)) {
            return [
                'ok' => false,
                'message' => 'LMS sync is off. Set ATLAAS_LMS_SYNC_ENABLED=true in .env once your connector is configured.',
                'touched' => 0,
            ];
        }

        // Example: LmsEnrollment::where('district_id', $districtId)->delete(); then pull from API.
        return [
            'ok' => true,
            'message' => 'Connector not implemented yet — no rows changed. Implement API calls in '.__CLASS__.'.',
            'touched' => 0,
        ];
    }
}
