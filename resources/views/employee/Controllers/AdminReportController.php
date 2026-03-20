<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Traits\Loggable;

class AdminReportController extends Controller
{
    use Loggable;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $reports = Report::with(['employee', 'task'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Get selected date from request, default to today
        $selectedDate = $request->get('date', now()->toDateString());

        // Get all employees with their report submission status for the selected date
        $employees = Employee::where('status', 'active')
            ->with(['reportSubmissions' => function($query) use ($selectedDate) {
                $query->where('report_date', $selectedDate);
            }])
            ->get()
            ->map(function($employee) use ($selectedDate) {
                $submission = $employee->reportSubmissions->first();
                $employee->report_status = $submission ? ($submission->is_submitted ? 'Submitted' : 'Not Submitted') : 'Not Submitted';
                $employee->report_date = $submission ? $submission->report_date : null;
                return $employee;
            });

        return view('admin.reports.index', compact('reports', 'employees', 'selectedDate'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Report $report)
    {
        $report->load(['employee', 'task']);

        return view('admin.reports.show', compact('report'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Report $report)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Report $report)
    {
        $request->validate([
            'admin_status' => 'required|in:sent,read,responded',
            'admin_review' => 'nullable|string|max:1000',
            'rating' => 'nullable|integer|min:1|max:5',
        ]);

        $report->update([
            'admin_status' => $request->admin_status,
            'admin_review' => $request->admin_review,
            'rating' => $request->rating,
        ]);

        $this->logActivity('update', 'Report', $report->id, 'Admin reviewed report for ' . $report->employee->name);

        return redirect()->route('admin.reports.show', $report->id)->with('success', 'Report reviewed successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Report $report)
    {
        //
    }
}
