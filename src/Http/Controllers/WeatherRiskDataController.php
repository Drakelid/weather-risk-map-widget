<?php

namespace LibreNMS\Plugins\WeatherRiskMap\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LibreNMS\Plugins\WeatherRiskMap\DTO\WidgetSettings;
use LibreNMS\Plugins\WeatherRiskMap\Services\WeatherRiskMapFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronous data endpoint for the widget.
 *
 * Security notes:
 *
 *  - Settings are read from the widget's own stored row, NEVER from the
 *    request body. A client cannot widen its device-group filter, change
 *    risk thresholds, or otherwise influence what it is shown by editing
 *    the AJAX payload.
 *  - Device visibility additionally goes through Device::hasAccess($user),
 *    so a restricted user sees only their permitted devices even if they
 *    somehow reference another user's widget id.
 *  - Dashboard ownership is checked with the same policy the core widget
 *    controller uses.
 *  - No API key, credential or provider secret is ever included in the
 *    response; all external calls happen server-side.
 */
class WeatherRiskDataController extends Controller
{
    public function __construct(
        private readonly WeatherRiskMapFactory $factory,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $this->validate($request, [
            'id' => 'nullable|integer|min:0',
        ]);

        $user = Auth::user();
        if ($user === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        $widget = ! empty($validated['id']) ? UserWidget::find($validated['id']) : null;

        if ($widget !== null && $widget->dashboard !== null && ! $user->can('view', $widget->dashboard)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unauthorized access to widget'], 403);
        }

        $stored = $widget !== null ? (array) $widget->settings : [];
        $settings = WidgetSettings::fromArray($stored, (array) config('weather-risk-map'));

        try {
            $payload = $this->factory
                ->forSettings($settings)
                ->build($user, $settings->toBuilderSettings());
        } catch (Throwable $e) {
            // A failure here must degrade the widget, never surface a Laravel
            // exception page inside the dashboard.
            $this->logger->error('weather-risk-map: failed to build widget payload', [
                'widget_id' => $validated['id'] ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => __('weather-risk-map::widget.error_generic'),
            ], 200);
        }

        $payload['status'] = 'ok';
        $payload['settings'] = [
            'min_severity' => $settings->minSeverity,
            'clustering' => $settings->clustering,
            'show_warnings' => $settings->showWarnings,
            'map_mode' => $settings->mapMode,
            'risk_period' => $settings->riskPeriod,
            'hazards' => $settings->hazards,
        ];

        return new JsonResponse($payload);
    }
}
