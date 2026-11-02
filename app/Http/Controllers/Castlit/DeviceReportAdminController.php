<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\DeviceReport;
use App\Support\DeviceReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** castlit-admin « Journaux »: the logs phones sent, to read and search. */
class DeviceReportAdminController extends Controller
{
    public const LEVELS = ['all' => 'Tout', 'warning' => 'Avertissements et erreurs', 'error' => 'Erreurs'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('castlit.admin.logs', [
            'reports' => DeviceReport::with('install:id,subdomain,domain')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('device', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%")
                    ->orWhereHas('install', fn ($i) => $i->where('subdomain', 'like', "%{$search}%"))))
                ->latest()
                ->limit(200)
                ->get(),
            'search' => $search,
        ]);
    }

    public function show(Request $request, DeviceReport $report): View
    {
        $level = array_key_exists((string) $request->query('niveau'), self::LEVELS) ? (string) $request->query('niveau') : 'all';
        $search = trim((string) $request->query('q', ''));

        return view('castlit.admin.log', [
            'report' => $report->load('install', 'conversation'),
            'lines' => DeviceReports::lines($report, $level, $search),
            'level' => $level,
            'search' => $search,
        ]);
    }

    public function download(DeviceReport $report): StreamedResponse
    {
        return Storage::disk('local')->download($report->file_path, $report->reference.'.log.gz');
    }
}
