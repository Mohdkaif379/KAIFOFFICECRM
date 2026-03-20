<?php

namespace App\Http\Controllers;

use App\Models\InvitedVisitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDF;

class InvitedVisitorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $invitedVisitors = InvitedVisitor::latest()->paginate(10);
        return view('admin.invited-visitors.index', compact('invitedVisitors'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.invited-visitors.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'purpose' => 'nullable|string|max:500',
            'invited_at' => 'nullable|date',
            'first_contact_person_name' => 'nullable|string|max:255',
            'contact_person_phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $request->all();
        $data['invitation_code'] = Str::upper(Str::random(8));

        InvitedVisitor::create($data);

        return redirect()->route('invited-visitors.index')
            ->with('success', 'Invited visitor created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(InvitedVisitor $invitedVisitor)
    {
        return view('admin.invited-visitors.show', compact('invitedVisitor'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(InvitedVisitor $invitedVisitor)
    {
        return view('admin.invited-visitors.edit', compact('invitedVisitor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InvitedVisitor $invitedVisitor)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'purpose' => 'nullable|string|max:500',
            'invited_at' => 'nullable|date',
            'first_contact_person_name' => 'nullable|string|max:255',
            'contact_person_phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $invitedVisitor->update($request->all());

        return redirect()->route('invited-visitors.index')
            ->with('success', 'Invited visitor updated successfully.');
    }

    /**
     * Show the invited visitor card with unique ID and QR code.
     */
    public function card(InvitedVisitor $invitedVisitor)
    {
        return view('admin.invited-visitors.card', compact('invitedVisitor'));
    }

    /**
     * Generate and download invitation PDF.
     */
    public function invitationPdf(InvitedVisitor $invitedVisitor)
    {
        $pdf = PDF::loadView('admin.invited-visitors.invitation-pdf', compact('invitedVisitor'));
        return $pdf->download('invitation-' . $invitedVisitor->name . '-' . $invitedVisitor->id . '.pdf');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InvitedVisitor $invitedVisitor)
    {
        $invitedVisitor->delete();

        return redirect()->route('invited-visitors.index')
            ->with('success', 'Invited visitor deleted successfully.');
    }
}
