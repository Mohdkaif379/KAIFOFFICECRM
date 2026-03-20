<?php

namespace App\Http\Controllers;

use App\Models\SalarySlip;
use App\Models\Employee;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use App\Models\Document;
use App\Models\Notification;

class SalarySlipController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = SalarySlip::with('employee');

        // Filter by employee
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filter by month
        if ($request->filled('month')) {
            $query->where('month', $request->month);
        }

        // Filter by year
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        $salarySlips = $query->orderBy('generated_at', 'desc')->paginate(15);
        $employees = Employee::all();

        return view('admin.salary-slips.index', compact('salarySlips', 'employees'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $employees = Employee::all();
        return view('admin.salary-slips.create', compact('employees'));
    }

    /**
     * Store a newly created resource in storage.
     */
public function store(Request $request)
{
    $request->validate([
        'employee_id' => 'required|exists:employees,id',
        'month' => 'required|string|in:01,02,03,04,05,06,07,08,09,10,11,12',
        'year' => 'required|integer|min:2020|max:2030',
        'deductions' => 'nullable|array',
        'deductions.*.type' => 'required|string',
        'deductions.*.amount' => 'required|numeric|min:0',
    ]);

    // Combine month and year into Y-m format for processing
    $monthYear = $request->year . '-' . $request->month;

    // ✅ Check if salary slip already exists
    if (SalarySlip::existsForEmployeeMonth($request->employee_id, $request->month, $request->year)) {
        return redirect()->back()
            ->withErrors(['month' => 'Salary slip already exists for this employee and month.']);
    }

    $employee = Employee::findOrFail($request->employee_id);

    // ✅ Attendance calculation
    $attendanceData = $this->calculateAttendanceData($employee, $monthYear);

    // ✅ Salary calculation
    $salaryData = $this->calculateSalary(
        $employee,
        $attendanceData,
        $request->deductions ?? [],
        $monthYear
    );

    // ✅ Create salary slip
    $salarySlip = SalarySlip::create([
        'employee_id' => $employee->id,
        'month' => $request->month,
        'year' => $request->year,
        'basic_salary' => $employee->basic_salary ?? 0,
        'hra' => $employee->hra ?? 0,
        'conveyance' => $employee->conveyance ?? 0,
        'medical' => $employee->medical ?? 0,
        'total_working_days' => $attendanceData['total_days'],
        'present_days' => $attendanceData['present'],
        'absent_days' => $attendanceData['absent'],
        'leave_days' => $attendanceData['leave'],
        'half_day_count' => $attendanceData['half_day'],
        'holiday_days' => $attendanceData['holiday'],
        'ncns_days' => $attendanceData['ncns'],
        'lwp_days' => $attendanceData['lwp'],
        'gross_salary' => $salaryData['gross_salary'],
        'deductions' => $request->deductions,
        'net_salary' => $salaryData['net_salary'],
        'generated_at' => now(),
    ]);

    /* ===============================
       🔔 NOTIFICATIONS (SKIP SELF)
    ================================= */

    $employeeName = $employee->name ?? 'Employee ID: ' . $employee->id;

    // ✅ Logged-in admin / sub-admin
    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    // ✅ Super Admin + Sub Admins with salary-slip permission
    $admins = \App\Models\Admin::all()->filter(function ($admin) use ($actor) {
        // ❌ Skip the admin who generated the slip
        if ($actor && $admin->id === $actor->id) {
            return false;
        }

        return $admin->role === 'super_admin'
            || ($admin->role === 'sub_admin' && $admin->hasPermission('salary-slip'));
    });

    foreach ($admins as $adminUser) {
        \App\Models\Notification::create([
            'admin_id' => $adminUser->id,
            'title' => 'Salary Slip Generated',
            'message' => "{$actorName} generated salary slip for {$employeeName} for {$request->month}.",
            'is_read' => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('salary-slips.show', $salarySlip)
        ->with('success', 'Salary slip generated successfully.');
}

public function sendToEmployeeDocuments(SalarySlip $salarySlip)
{
    $employee = $salarySlip->employee;

    if (!$employee) {
        return back()->with('error', 'Employee not found.');
    }

    // ✅ Generate PDF
    $pdf = Pdf::loadView('admin.salary-slips.pdf', compact('salarySlip'));

    // ✅ File name & path
    $fileName = 'salary-slip-' . $salarySlip->month . '-' . $employee->employee_code . '.pdf';
    $filePath = 'documents/' . $fileName;

    // ✅ Store PDF in storage
    Storage::disk('public')->put($filePath, $pdf->output());

    // ✅ Save entry in documents table
    Document::create([
        'employee_id'   => $employee->id,
        'document_type' => 'salary slip',
        'file_path'     => $filePath,
    ]);

    /* ===============================
       🔔 EMPLOYEE NOTIFICATION
    ================================== */

    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    Notification::create([
        'employee_id' => $employee->id,
        'title'       => 'Salary Slip Available',
        'message'     => "Your salary slip for {$salarySlip->month} has been uploaded by {$actorName}.",
        'is_read'     => false,
    ]);

    /* =============================== */

    return back()->with('success', 'Salary slip sent to employee documents successfully.');
}


    /**
     * Display the specified resource.
     */
    public function show(SalarySlip $salarySlip)
    {
        $salarySlip->load('employee');
        return view('admin.salary-slips.show', compact('salarySlip'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SalarySlip $salarySlip)
    {
        $employees = Employee::all();
        return view('admin.salary-slips.edit', compact('salarySlip', 'employees'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SalarySlip $salarySlip)
    {
        $request->validate([
            'deductions' => 'nullable|array',
            'deductions.*.type' => 'required|string',
            'deductions.*.amount' => 'required|numeric|min:0',
            'holiday_days' => 'nullable|integer|min:0|max:31',
        ]);

        $employee = $salarySlip->employee;

        // Recalculate salary with new deductions and holiday days
        $attendanceData = [
            'total_days' => $salarySlip->total_working_days,
            'present' => $salarySlip->present_days,
            'absent' => $salarySlip->absent_days,
            'leave' => $salarySlip->leave_days,
            'half_day' => $salarySlip->half_day_count,
            'holiday' => $request->holiday_days ?? $salarySlip->holiday_days,
            'ncns' => $salarySlip->ncns_days,
            'lwp' => $salarySlip->lwp_days,
        ];

        $salaryData = $this->calculateSalary($employee, $attendanceData, $request->deductions ?? []);

        $salarySlip->update([
            'holiday_days' => $request->holiday_days ?? $salarySlip->holiday_days,
            'deductions' => $request->deductions,
            'gross_salary' => $salaryData['gross_salary'],
            'net_salary' => $salaryData['net_salary'],
        ]);

        return redirect()->route('salary-slips.show', $salarySlip)->with('success', 'Salary slip updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SalarySlip $salarySlip)
    {
        // Delete PDF file if exists
        if ($salarySlip->pdf_path && Storage::exists($salarySlip->pdf_path)) {
            Storage::delete($salarySlip->pdf_path);
        }

        $salarySlip->delete();

        return redirect()->route('salary-slips.index')->with('success', 'Salary slip deleted successfully.');
    }

    /**
     * Generate and download PDF
     */
    public function downloadPdf(SalarySlip $salarySlip)
    {
        $salarySlip->load('employee');

        $pdf = Pdf::loadView('admin.salary-slips.pdf', compact('salarySlip'));

        $filename = 'salary-slip-' . $salarySlip->employee->name . '-' . $salarySlip->month . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Show salary slip template
     */
    public function template()
    {
        $admin = auth('admin')->user();
        return view('admin.salary-slips.template', compact('admin'));
    }

    /**
     * Calculate attendance data for the month
     */
private function calculateAttendanceData(Employee $employee, string $month): array
{
    $date = Carbon::createFromFormat('Y-m', $month);
    $year = $date->year;
    $monthNum = $date->month;

    $attendances = Attendance::where('employee_id', $employee->id)
        ->whereYear('date', $year)
        ->whereMonth('date', $monthNum)
        ->get();

    return [
        'total_days' => $attendances->count(),
        'present' => $attendances->where('status', 'Present')->count(),
        'absent' => $attendances->where('status', 'Absent')->count(),
        'leave' => $attendances->where('status', 'Leave')->count(),
        'half_day' => $attendances->where('status', 'Half Day')->count(),
        'holiday' => $attendances->where('status', 'Holiday')->count(),
        'ncns' => $attendances->where('status', 'NCNS')->count(),
        'lwp' => $attendances->where('status', 'LWP')->count(),
    ];
}


    /**
     * Calculate salary based on attendance and deductions
     */
private function calculateSalary(Employee $employee, array $attendanceData, array $deductions = [], string $month = null): array
{
    $basicSalary = $employee->basic_salary ?? 0;
    $hra = $employee->hra ?? 0;
    $conveyance = $employee->conveyance ?? 0;
    $medical = $employee->medical ?? 0;

    // Calculate total days in the specific month
    if ($month) {
        $date = Carbon::createFromFormat('Y-m', $month);
        $totalDaysInMonth = $date->daysInMonth;
    } else {
        $totalDaysInMonth = Carbon::now()->daysInMonth; // Fallback
    }

    // Daily salary rates
    $basicDaily = $basicSalary / $totalDaysInMonth;
    $hraDaily = $hra / $totalDaysInMonth;
    $conveyanceDaily = $conveyance / $totalDaysInMonth;
    $medicalDaily = $medical / $totalDaysInMonth;

    // Attendance breakdown
    $presentDays = $attendanceData['present'] ?? 0;
    $halfDays = $attendanceData['half_day'] ?? 0;
    $holidayDays = $attendanceData['holiday'] ?? 0;
    $lwpDays = $attendanceData['lwp'] ?? 0;   
    $ncnsDays = $attendanceData['ncns'] ?? 0;

    /*
     * ✔ Paid Days = Present + Holiday + (Half Day × 0.5)
     * LWP & NCNS are only UNPAID days — NO minus calculation
     */
    $paidDays = $presentDays + $holidayDays + ($halfDays * 0.5);

    // Gross Salary (Unpaid days not counted)
    $grossSalary = ($basicDaily * $paidDays) +
                   ($hraDaily * $paidDays) +
                   ($conveyanceDaily * $paidDays) +
                   ($medicalDaily * $paidDays);

    // Calculate deductions
    $totalDeductions = collect($deductions)->sum('amount');
    $netSalary = $grossSalary - $totalDeductions;

    return [
        'gross_salary' => round($grossSalary, 2),
        'net_salary' => round(max(0, $netSalary), 2),
        'total_deductions' => $totalDeductions,
    ];
}



}
