<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index()
    {
        $summary = $this->dashboard->summaryCards();
        $health = $this->dashboard->healthOverview();
        $recentDeployments = $this->dashboard->recentDeployments();
        $recentReleases = $this->dashboard->recentReleases();
        $agents = $this->dashboard->agentStatusWidget();
        $serverOverview = $this->dashboard->serverOverviewWidget();

        return view('dashboard.index', compact('summary', 'health', 'recentDeployments', 'recentReleases', 'agents', 'serverOverview'));
    }
}
