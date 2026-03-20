<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        $query = Lead::with('assignedAdmin');

        if ($admin->role !== 'super_admin') {
            $query->where('assigned_to', $admin->id);
        }

        // Apply filters
        if ($request->filled('lead_id')) {
            $query->where('lead_id', 'like', '%' . $request->lead_id . '%');
        }

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        if ($request->filled('phone')) {
            $query->where('phone', 'like', '%' . $request->phone . '%');
        }

        if ($request->filled('company_name')) {
            $query->where('company_name', 'like', '%' . $request->company_name . '%');
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $leads = $query->latest()->paginate(10)->appends($request->query());

        // Get filter options
        $admins = Admin::all();

        return view('admin.leads.index', compact('leads', 'admins'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $admins = Admin::all();
        return view('admin.leads.create', compact('admins'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:leads,email',
            'phone' => 'required|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'status' => 'required|string|max:255',
            'assigned_to' => 'nullable|exists:admins,id',
            'priority' => 'required|in:Hot,Warm,Cold',
        ]);

        Lead::create($request->all());

        return redirect()->route('admin.leads.index')->with('success', 'Lead created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Lead $lead)
    {
        return view('admin.leads.show', compact('lead'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Lead $lead)
    {
        $admins = Admin::all();
        return view('admin.leads.edit', compact('lead', 'admins'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Lead $lead)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:leads,email,' . $lead->id,
            'phone' => 'required|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'source' => 'required|string|max:255',
            'status' => 'required|string|max:255',
            'assigned_to' => 'nullable|exists:admins,id',
            'priority' => 'required|in:Hot,Warm,Cold',
        ]);

        $lead->update($request->all());

        return redirect()->route('admin.leads.index')->with('success', 'Lead updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lead $lead)
    {
        $lead->delete();

        return redirect()->route('admin.leads.index')->with('success', 'Lead deleted successfully.');
    }
}
