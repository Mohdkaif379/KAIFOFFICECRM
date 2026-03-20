<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Interaction;

class ExecutiveController extends Controller
{
    public function index(Request $request)
    {
        $admin = auth('admin')->user();

        // Check if admin has permission to view executives
        if (!$admin->hasPermission('executives')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to view executives.');
        }

        // Get executives based on admin role
        if ($admin->role === 'super_admin') {
            // Super admin can see all executives
            $executives = Admin::where('role', 'sub_admin')->with(['leads', 'proposals'])->get();
        } else {
            // Sub-admin can only see themselves
            $executives = Admin::where('id', $admin->id)->with(['leads', 'proposals'])->get();
        }

        $executiveStats = [];

        foreach ($executives as $executive) {
            // Count approved leads assigned to this executive
            $approvedLeads = Lead::where('assigned_to', $executive->id)
                ->where('status', 'Approved')
                ->count();

            // Count approved proposals created by this executive
            $approvedProposals = Proposal::where('created_by', $executive->id)
                ->where('status', 'Approved')
                ->count();

            // Count total leads assigned to this executive
            $totalLeads = Lead::where('assigned_to', $executive->id)->count();

            // Count total proposals created by this executive
            $totalProposals = Proposal::where('created_by', $executive->id)->count();

            // Count interactions created by this executive
            $totalInteractions = Interaction::where('created_by', $executive->id)->count();

            // Calculate conversion rates
            $leadConversionRate = $totalLeads > 0 ? round(($approvedLeads / $totalLeads) * 100, 2) : 0;
            $proposalConversionRate = $totalProposals > 0 ? round(($approvedProposals / $totalProposals) * 100, 2) : 0;

            $executiveStats[] = [
                'executive' => $executive,
                'approved_leads' => $approvedLeads,
                'approved_proposals' => $approvedProposals,
                'total_leads' => $totalLeads,
                'total_proposals' => $totalProposals,
                'total_interactions' => $totalInteractions,
                'lead_conversion_rate' => $leadConversionRate,
                'proposal_conversion_rate' => $proposalConversionRate,
                'total_approvals' => $approvedLeads + $approvedProposals
            ];
        }

        // Sort by total approvals descending
        usort($executiveStats, function($a, $b) {
            return $b['total_approvals'] <=> $a['total_approvals'];
        });

        return view('admin.executives.index', compact('executiveStats'));
    }

    public function show($id)
    {
        $admin = auth('admin')->user();

        // Check if admin has permission to view executives
        if (!$admin->hasPermission('executives')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to view executives.');
        }

        $executive = Admin::findOrFail($id);

        // Get executive's leads
        $leads = Lead::where('assigned_to', $executive->id)
            ->with('interactions')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get executive's proposals
        $proposals = Proposal::where('created_by', $executive->id)
            ->with('lead')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get executive's interactions
        $interactions = Interaction::where('created_by', $executive->id)
            ->with('lead')
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate stats
        $approvedLeads = $leads->where('status', 'Approved')->count();
        $approvedProposals = $proposals->where('status', 'Approved')->count();

        $stats = [
            'total_leads' => $leads->count(),
            'approved_leads' => $approvedLeads,
            'total_proposals' => $proposals->count(),
            'approved_proposals' => $approvedProposals,
            'total_interactions' => $interactions->count(),
            'lead_conversion_rate' => $leads->count() > 0 ? round(($approvedLeads / $leads->count()) * 100, 2) : 0,
            'proposal_conversion_rate' => $proposals->count() > 0 ? round(($approvedProposals / $proposals->count()) * 100, 2) : 0,
        ];

        return view('admin.executives.show', compact('executive', 'leads', 'proposals', 'interactions', 'stats'));
    }
}
