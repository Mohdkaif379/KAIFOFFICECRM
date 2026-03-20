<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\HrMisReport;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HrMisReportController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $reports = HrMisReport::with('createdBy')->get();
        return view('hrm.mis-reports.index', compact('reports'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Calculate Employee Strength data
        $totalEmployees = \App\Models\Employee::where('status', 'active')->count();
        $newJoiners = 0; // Will be calculated based on selected period
        $resignations = 0; // Will be calculated based on selected period
        $terminated = 0; // Will be calculated based on selected period
        $netStrength = $totalEmployees;

        // Calculate Attendance Summary data (for current month as default)
        $currentMonth = now()->format('Y-m');
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $attendanceData = \App\Models\Attendance::whereBetween('date', [$startOfMonth, $endOfMonth])->get();

        $presentDays = $attendanceData->where('status', 'Present')->count();
        $absentDays = $attendanceData->where('status', 'Absent')->count();
        $leavesApproved = $attendanceData->where('status', 'Leave')->count();
        $halfDays = $attendanceData->where('status', 'Half Day')->count();
        $holidayDays = $attendanceData->where('status', 'Holiday')->count();

        // Additional attendance fields
        $ncnsDays = 0; // Will be calculated based on business logic
        $lwpDays = 0; // Will be calculated based on business logic

        $autoFilledData = [
            'total_employees' => $totalEmployees,
            'new_joiners' => $newJoiners,
            'resignations' => $resignations,
            'terminated' => $terminated,
            'net_strength' => $netStrength,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'leaves_approved' => $leavesApproved,
            'half_days' => $halfDays,
            'holiday_days' => $holidayDays,
            'ncns_days' => $ncnsDays,
            'lwp_days' => $lwpDays,
        ];

        return view('hrm.mis-reports.create', compact('autoFilledData'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:daily,weekly,monthly',
            'report_date' => 'nullable|date',
            'week_start' => 'nullable|date',
            'week_end' => 'nullable|date',
            'report_month' => 'nullable|string|max:7',
            'department' => 'nullable|string|max:100',
            'center_branch' => 'nullable|string|max:100',
            'total_employees' => 'nullable|integer',
            'new_joiners' => 'nullable|integer',
            'resignations' => 'nullable|integer',
            'terminated' => 'nullable|integer',
            'net_strength' => 'nullable|integer',
            'present_days' => 'nullable|integer',
            'absent_days' => 'nullable|integer',
            'leaves_approved' => 'nullable|integer',
            'half_days' => 'nullable|integer',
            'holiday_days' => 'nullable|integer',
            'ncns_days' => 'nullable|integer',
            'lwp_days' => 'nullable|integer',
            'requirements_raised' => 'nullable|integer',
            'positions_closed' => 'nullable|integer',
            'positions_pending' => 'nullable|integer',
            'interviews_conducted' => 'nullable|integer',
            'selected' => 'nullable|integer',
            'rejected' => 'nullable|integer',
            'salary_processed' => 'nullable|boolean',
            'salary_disbursed_date' => 'nullable|date',
            'deductions' => 'nullable|string',
            'pending_compliance' => 'nullable|string',
            'grievances_received' => 'nullable|integer',
            'grievances_resolved' => 'nullable|integer',
            'warning_notices' => 'nullable|integer',
            'appreciations' => 'nullable|integer',
            'trainings_conducted' => 'nullable|integer',
            'employees_attended' => 'nullable|integer',
            'training_feedback' => 'nullable|string',
            'birthday_celebrations' => 'nullable|string',
            'engagement_activities' => 'nullable|string',
            'hr_initiatives' => 'nullable|string',
            'special_events' => 'nullable|string',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $data = $request->all();
        $data['created_by'] = Auth::guard('admin')->id();

        $report = HrMisReport::create($data);

        // Send notification to all admins with access to hr-mis-reports module
        $adminsWithAccess = Admin::where('role', 'super_admin')
            ->orWhereJsonContains('permissions', 'hr-mis-reports')
            ->get();

        foreach ($adminsWithAccess as $admin) {
            Notification::create([
                'admin_id' => $admin->id,
                'title' => 'New HR MIS Report Created',
                'message' => 'A new ' . ucfirst($report->report_type) . ' HR MIS report has been created by ' . Auth::guard('admin')->user()->name ,
                'is_read' => false,
            ]);
        }

        return redirect()->route('hr-mis-reports.index')->with('success', 'HR MIS Report created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $report = HrMisReport::findOrFail($id);
        return view('hrm.mis-reports.show', compact('report'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $report = HrMisReport::findOrFail($id);
        return view('hrm.mis-reports.edit', compact('report'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'report_type' => 'required|in:daily,weekly,monthly',
            'report_date' => 'nullable|date',
            'week_start' => 'nullable|date',
            'week_end' => 'nullable|date',
            'report_month' => 'nullable|string|max:7',
            'department' => 'nullable|string|max:100',
            'center_branch' => 'nullable|string|max:100',
            'total_employees' => 'nullable|integer',
            'new_joiners' => 'nullable|integer',
            'resignations' => 'nullable|integer',
            'terminated' => 'nullable|integer',
            'net_strength' => 'nullable|integer',
            'present_days' => 'nullable|integer',
            'absent_days' => 'nullable|integer',
            'leaves_approved' => 'nullable|integer',
            'half_days' => 'nullable|integer',
            'holiday_days' => 'nullable|integer',
            'ncns_days' => 'nullable|integer',
            'lwp_days' => 'nullable|integer',
            'requirements_raised' => 'nullable|integer',
            'positions_closed' => 'nullable|integer',
            'positions_pending' => 'nullable|integer',
            'interviews_conducted' => 'nullable|integer',
            'selected' => 'nullable|integer',
            'rejected' => 'nullable|integer',
            'salary_processed' => 'nullable|boolean',
            'salary_disbursed_date' => 'nullable|date',
            'deductions' => 'nullable|string',
            'pending_compliance' => 'nullable|string',
            'grievances_received' => 'nullable|integer',
            'grievances_resolved' => 'nullable|integer',
            'warning_notices' => 'nullable|integer',
            'appreciations' => 'nullable|integer',
            'trainings_conducted' => 'nullable|integer',
            'employees_attended' => 'nullable|integer',
            'training_feedback' => 'nullable|string',
            'birthday_celebrations' => 'nullable|string',
            'engagement_activities' => 'nullable|string',
            'hr_initiatives' => 'nullable|string',
            'special_events' => 'nullable|string',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $report = HrMisReport::findOrFail($id);
        $data = $request->all();
        $report->update($data);

        return redirect()->route('hr-mis-reports.index')->with('success', 'HR MIS Report updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $report = HrMisReport::findOrFail($id);
        $report->delete();

        return redirect()->route('hr-mis-reports.index')->with('success', 'HR MIS Report deleted successfully.');
    }

    /**
     * Download the specified report as PDF.
     */
    public function downloadPdf(string $id)
    {
        $report = HrMisReport::with('createdBy')->findOrFail($id);

        $pdf = \PDF::loadView('hrm.mis-reports.pdf', compact('report'));

        $filename = 'hr-mis-report-' . $report->id . '-' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }
}
