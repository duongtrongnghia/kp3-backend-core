<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Jenssegers\Agent\Agent;

/** @mixin Session */
class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $agent = new Agent;
        $agent->setUserAgent((string) ($this->user_agent ?? ''));

        return [
            'id' => $this->id,
            'ip_address' => $this->ip_address,
            'device' => $this->getDeviceType($agent),
            'browser' => $agent->browser() ?: __('api.device.unknown'),
            'platform' => $agent->platform() ?: __('api.device.unknown'),
            'is_current' => $this->is_current,
            'last_activity' => $this->last_activity
                ? date('Y-m-d H:i:s', (int) $this->last_activity)
                : null,
        ];
    }

    private function getDeviceType(Agent $agent): string
    {
        if ($agent->isDesktop()) {
            return __('api.device.desktop');
        }
        if ($agent->isTablet()) {
            return __('api.device.tablet');
        }
        if ($agent->isMobile()) {
            return __('api.device.mobile');
        }

        return __('api.device.unknown');
    }
}
