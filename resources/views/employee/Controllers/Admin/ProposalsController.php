<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProposalsController extends Controller
{
    public function index()
    {
        $proposals = Proposal::with(['lead', 'creator'])->latest()->paginate(15);
        $leads = Lead::all();
        return view('admin.proposals.index', compact('proposals', 'leads'));
    }

    public function create(Request $request)
    {
        $leads = Lead::all();
        $leadId = $request->get('lead_id');
        return view('admin.proposals.create', compact('leads', 'leadId'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'proposal_date' => 'required|date',
            'valid_until' => 'nullable|date|after:proposal_date',
            'total_amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:Draft,Sent,Accepted,Rejected,Expired',
            'notes' => 'nullable|string',
        ]);

        Proposal::create([
            'lead_id' => $request->lead_id,
            'title' => $request->title,
            'description' => $request->description,
            'proposal_date' => $request->proposal_date,
            'valid_until' => $request->valid_until,
            'total_amount' => $request->total_amount,
            'status' => $request->status,
            'created_by' => Auth::guard('admin')->id(),
            'notes' => $request->notes,
        ]);

        return redirect()->route('admin.proposals.index')
            ->with('success', 'Proposal created successfully.');
    }

    public function show(Proposal $proposal)
    {
        $proposal->load(['lead', 'creator']);
        return view('admin.proposals.show', compact('proposal'));
    }

    public function edit(Proposal $proposal)
    {
        $leads = Lead::all();
        return view('admin.proposals.edit', compact('proposal', 'leads'));
    }

    public function update(Request $request, Proposal $proposal)
    {
        $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'proposal_date' => 'required|date',
            'valid_until' => 'nullable|date|after:proposal_date',
            'total_amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:Draft,Sent,Accepted,Rejected,Expired',
            'notes' => 'nullable|string',
        ]);

        $proposal->update($request->all());

        return redirect()->route('admin.proposals.index')
            ->with('success', 'Proposal updated successfully.');
    }

    public function destroy(Proposal $proposal)
    {
        $proposal->delete();

        return redirect()->route('admin.proposals.index')
            ->with('success', 'Proposal deleted successfully.');
    }
}
