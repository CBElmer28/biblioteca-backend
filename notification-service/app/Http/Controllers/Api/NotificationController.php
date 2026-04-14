<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNotificationJob;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // ── GET /api/v1/admin/notifications ──────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $logs = NotificationLog::query()
            ->when($request->status,       fn($q) => $q->where('status', $request->status))
            ->when($request->event_type,   fn($q) => $q->where('event_type', $request->event_type))
            ->when($request->source,       fn($q) => $q->where('source_service', $request->source))
            ->when($request->email,        fn($q) =>
                $q->where('recipient_email', 'ilike', "%{$request->email}%")
            )
            ->when($request->date_from,    fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from)
            )
            ->when($request->date_to,      fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to)
            )
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json(['success' => true, 'data' => $logs]);
    }

    // ── GET /api/v1/admin/notifications/stats ─────────────────────────────────
    public function stats(): JsonResponse
    {
        $byStatus = NotificationLog::selectRaw("
            status,
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '24 hours') as last_24h,
            COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days')   as last_7d
        ")->groupBy('status')->get()->keyBy('status');

        $byEvent = NotificationLog::selectRaw("
            event_type,
            source_service,
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE status = 'sent')   as sent,
            COUNT(*) FILTER (WHERE status = 'failed') as failed,
            COUNT(*) FILTER (WHERE status = 'skipped')as skipped
        ")
        ->groupBy('event_type', 'source_service')
        ->orderBy('total', 'desc')
        ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'by_status'  => $byStatus,
                'by_event'   => $byEvent,
                'total'      => NotificationLog::count(),
                'failed_pending_retry' => NotificationLog::where('status', 'failed')
                    ->where('attempt', '<', 3)
                    ->count(),
            ],
        ]);
    }

    // ── POST /api/v1/admin/notifications/{id}/retry ───────────────────────────
    public function retry(string $id): JsonResponse
    {
        $log = NotificationLog::whereIn('status', ['failed', 'skipped'])
            ->findOrFail($id);

        $log->update(['status' => 'pending', 'attempt' => $log->attempt + 1]);

        ProcessNotificationJob::dispatch(
            $log->event_type,
            $log->event_payload,
            $log->source_service
        );

        return response()->json([
            'success' => true,
            'message' => 'Notificación reencolada para reenvío.',
        ]);
    }
}