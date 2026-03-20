<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InteractionsController extends Controller
{
    public function index(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        $query = Interaction::with(['lead', 'creator']);

        if ($admin->role !== 'super_admin') {
            $query->whereHas('lead', function ($q) use ($admin) {
                $q->where('assigned_to', $admin->id);
            });
        }

        // Apply filters
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }

        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->activity_type);
        }

        if ($request->filled('subject')) {
            $query->where('subject', 'like', '%' . $request->subject . '%');
        }

        if ($request->filled('activity_status')) {
            $query->where('activity_status', $request->activity_status);
        }

        if ($request->filled('activity_date_from')) {
            $query->whereDate('activity_date', '>=', $request->activity_date_from);
        }

        if ($request->filled('activity_date_to')) {
            $query->whereDate('activity_date', '<=', $request->activity_date_to);
        }

        if ($request->filled('next_follow_up_from')) {
            $query->whereDate('next_follow_up', '>=', $request->next_follow_up_from);
        }

        if ($request->filled('next_follow_up_to')) {
            $query->whereDate('next_follow_up', '<=', $request->next_follow_up_to);
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        $interactions = $query->latest()->paginate(15)->appends($request->query());

        // Get filter options
        $leads = $admin->role === 'super_admin' ? Lead::all() : Lead::where('assigned_to', $admin->id)->get();
        $admins = \App\Models\Admin::all();

        return view('admin.interactions.index', compact('interactions', 'leads', 'admins'));
    }

    public function create(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        if ($admin->role === 'super_admin') {
            $leads = Lead::all();
        } else {
            $leads = Lead::where('assigned_to', $admin->id)->get();
        }

        $leadId = $request->get('lead_id');
        return view('admin.interactions.create', compact('leads', 'leadId'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'activity_type' => 'required|in:Call,WhatsApp,Email,Meeting,Note',
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'activity_status' => 'required|in:Pending,Completed',
            'activity_date' => 'required|date',
            'next_follow_up' => 'nullable|date|after:activity_date',
        ]);

        Interaction::create([
            'lead_id' => $request->lead_id,
            'activity_type' => $request->activity_type,
            'subject' => $request->subject,
            'description' => $request->description,
            'activity_status' => $request->activity_status,
            'activity_date' => $request->activity_date,
            'next_follow_up' => $request->next_follow_up,
            'created_by' => Auth::guard('admin')->id(),
        ]);

        return redirect()->route('admin.interactions.index')
            ->with('success', 'Interaction created successfully.');
    }

    public function show(Interaction $interaction)
    {
        $interaction->load(['lead', 'creator']);
        return view('admin.interactions.show', compact('interaction'));
    }

    public function edit(Interaction $interaction)
    {
        $leads = Lead::all();
        return view('admin.interactions.edit', compact('interaction', 'leads'));
    }

    public function update(Request $request, Interaction $interaction)
    {
        $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'activity_type' => 'required|in:Call,WhatsApp,Email,Meeting,Note',
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'activity_status' => 'required|in:Pending,Completed',
            'activity_date' => 'required|date',
            'next_follow_up' => 'nullable|date|after:activity_date',
        ]);

        $interaction->update($request->all());

        return redirect()->route('admin.interactions.index')
            ->with('success', 'Interaction updated successfully.');
    }

    public function destroy(Interaction $interaction)
    {
        $interaction->delete();

        return redirect()->route('admin.interactions.index')
            ->with('success', 'Interaction deleted successfully.');
    }
}
