<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\Server;

class DashboardController extends Controller
{
    public function index()
    {
        $recentDeployments = Deployment::with(['project', 'server'])
            ->latest('id')
            ->take(25)
            ->get();

        $servers = Server::withCount('projects')->get();

        return view('dashboard.index', compact('recentDeployments', 'servers'));
    }
}
