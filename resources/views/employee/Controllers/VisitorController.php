<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VisitorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $visitors = Visitor::latest()->paginate(10);
        return view('admin.visitors.index', compact('visitors'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.visitors.create');
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
            'visited_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Visitor::create($request->all());

        return redirect()->route('visitors.index')
            ->with('success', 'Visitor created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Visitor $visitor)
    {
        return view('admin.visitors.show', compact('visitor'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Visitor $visitor)
    {
        return view('admin.visitors.edit', compact('visitor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Visitor $visitor)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'purpose' => 'nullable|string|max:500',
            'visited_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $visitor->update($request->all());

        return redirect()->route('visitors.index')
            ->with('success', 'Visitor updated successfully.');
    }

    /**
     * Show the visitor card with unique ID and QR code.
     */
    public function card(Visitor $visitor)
    {
        return view('admin.visitors.card', compact('visitor'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Visitor $visitor)
    {
        $visitor->delete();

        return redirect()->route('visitors.index')
            ->with('success', 'Visitor deleted successfully.');
    }
}
