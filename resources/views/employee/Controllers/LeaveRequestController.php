<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    /**
     * Display a listing of leave requests for admin.
     */
    public function index()
    {
        $leaveRequests = LeaveRequest::with('employee')->orderBy('created_at', 'desc')->paginate(10);
        return view('admin.leave-requests.index', compact('leaveRequests'));
    }

    /**
     * Show the form for creating a new leave request (for admin).
     */
    public function create()
    {
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();
        return view('admin.leave-requests.create', compact('employees'));
    }

    /**
     * Store a newly created leave request in storage (for admin).
     */
public function store(Request $request)
{
    $request->validate([
        'employee_id' => 'required|exists:employees,id',
        'leave_type' => 'required',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'days' => 'required|numeric|min:0.5',
        'subject' => 'required|string|max:255',
        'description' => 'required|string',
        'file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
    ]);

    $data = [
        'employee_id' => $request->employee_id,
        'leave_type' => $request->leave_type,
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'days' => $request->days,
        'subject' => $request->subject,
        'description' => $request->description,
        'status' => 'pending',
    ];

    if ($request->hasFile('file')) {
        $data['file_path'] = $request->file('file')->store('leave_requests', 'public');
    }

    $leaveRequest = LeaveRequest::create($data);

    /* ===============================
       🔔 NOTIFICATIONS (SKIP SELF)
    ================================= */

    // Employee name
    $employee = $leaveRequest->employee;
    $employeeName = $employee
        ? $employee->name
        : ('Employee ID: ' . $request->employee_id);

    // Logged-in admin / sub-admin (actor)
    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    // Super Admin + Sub Admins with leave permission
    $admins = \App\Models\Admin::all()->filter(function ($admin) use ($actor) {
        // ❌ Skip the one who created leave
        if ($actor && $admin->id === $actor->id) {
            return false;
        }

        return $admin->role === 'super_admin'
            || ($admin->role === 'sub_admin' && $admin->hasPermission('leave'));
    });

    foreach ($admins as $adminUser) {
        \App\Models\Notification::create([
            'admin_id' => $adminUser->id,
            'title' => 'Leave Created',
            'message' => "{$actorName} created a leave request for {$employeeName} ({$leaveRequest->leave_type} leave from {$leaveRequest->start_date} to {$leaveRequest->end_date}).",
            'is_read' => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('admin.leave-requests.index')
        ->with('success', 'Leave request created successfully.');
}


    /**
     * Show the form for creating a new leave request (for employees).
     */
    public function employeeCreate()
    {
        return view('employee.leave-requests.create');
    }

    /**
     * Store a newly created leave request in storage (for employees).
     */
  public function employeeStore(Request $request)
{
    $request->validate([
        'leave_type' => 'required',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'days' => 'required|numeric|min:0.5',
        'subject' => 'required|string|max:255',
        'description' => 'required|string',
        'file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
    ]);

    // ✅ Auth employee (safe check)
    $employee = Auth::guard('employee')->user();

    if (!$employee) {
        return redirect()->route('employee.login')
            ->with('error', 'Session expired. Please login again.');
    }

    $data = [
        'employee_id' => $employee->id,
        'leave_type' => $request->leave_type,
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'days' => $request->days,
        'subject' => $request->subject,
        'description' => $request->description,
        'status' => 'pending',
    ];

    if ($request->hasFile('file')) {
        $data['file_path'] = $request->file('file')->store('leave_requests', 'public');
    }

    $leaveRequest = LeaveRequest::create($data);

    /* ===============================
       🔔 ADMIN / SUPER ADMIN NOTIFICATION
    ================================== */

    $employeeName = $employee->name ?? ('Employee ID: ' . $employee->id);

    $admins = \App\Models\Admin::whereIn('role', ['admin', 'super_admin'])->get();

    foreach ($admins as $admin) {
        \App\Models\Notification::create([
            'admin_id' => $admin->id,
            'title' => 'New Leave Request',
            'message' => "{$employeeName} has applied for {$leaveRequest->leave_type} leave ({$leaveRequest->start_date} to {$leaveRequest->end_date}).",
            'is_read' => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('employee.leave-requests.index')
        ->with('success', 'Leave request submitted successfully.');
}


    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leaveRequest)
    {
        return view('admin.leave-requests.show', compact('leaveRequest'));
    }

    /**
     * Show the form for editing the specified leave request.
     */
    public function edit(LeaveRequest $leaveRequest)
    {
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();
        return view('admin.leave-requests.edit', compact('leaveRequest', 'employees'));
    }

    /**
     * Update the specified leave request in storage.
     */
   public function update(Request $request, LeaveRequest $leaveRequest)
{
    // Logged-in admin / sub-admin (actor)
    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    // ✅ STATUS ONLY UPDATE (approve / reject)
    if ($request->has('status') && !$request->has('employee_id')) {

        $request->validate([
            'status' => 'required|in:pending,approved,rejected',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $leaveRequest->update([
            'status' => $request->status,
            'remarks' => $request->remarks,
        ]);

        /* ===============================
           🔔 NOTIFICATIONS (STATUS UPDATE)
        ================================= */

        $employee = $leaveRequest->employee;
        $employeeName = $employee ? $employee->name : 'Employee';

        // ✅ EMPLOYEE NOTIFICATION
        \App\Models\Notification::create([
            'admin_id'    => $actor?->id,
            'employee_id' => $employee->id,
            'title'       => 'Leave Request Updated',
            'message'     => "Your leave request has been {$request->status} by {$actorName}.",
            'is_read'     => false,
        ]);

        // ✅ OTHER ADMINS / SUB-ADMINS (EXCEPT ACTOR)
        $admins = \App\Models\Admin::all()->filter(function ($admin) use ($actor) {
            if ($actor && $admin->id === $actor->id) {
                return false; // ❌ skip actor
            }

            return $admin->role === 'super_admin'
                || ($admin->role === 'sub_admin' && $admin->hasPermission('leave-requests'));
        });

        foreach ($admins as $adminUser) {
            \App\Models\Notification::create([
                'admin_id' => $adminUser->id,
                'title'    => 'Leave Request Status Updated',
                'message'  => "{$actorName} {$request->status} a leave request for {$employeeName}.",
                'is_read'  => false,
            ]);
        }

        /* =============================== */

        return redirect()
            ->route('admin.leave-requests.index')
            ->with('success', 'Leave request status updated successfully.');
    }

    // ✅ FULL UPDATE VALIDATION
    $request->validate([
        'employee_id' => 'required|exists:employees,id',
        'leave_type' => 'nullable|in:sick,casual,annual,maternity,paternity,emergency,other',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date|after_or_equal:start_date',
        'days' => 'nullable|numeric|min:0.5',
        'subject' => 'required|string|max:255',
        'description' => 'required|string',
        'status' => 'required|in:pending,approved,rejected',
        'remarks' => 'nullable|string|max:1000',
        'file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
    ]);

    $data = [
        'employee_id' => $request->employee_id,
        'subject' => $request->subject,
        'description' => $request->description,
        'status' => $request->status,
        'remarks' => $request->remarks,
    ];

    if ($request->filled('leave_type')) $data['leave_type'] = $request->leave_type;
    if ($request->filled('start_date')) $data['start_date'] = $request->start_date;
    if ($request->filled('end_date')) $data['end_date'] = $request->end_date;
    if ($request->filled('days')) $data['days'] = $request->days;

    if ($request->hasFile('file')) {
        $newFilePath = $request->file('file')->store('leave_requests', 'public');
        if ($leaveRequest->file_path && $newFilePath) {
            Storage::disk('public')->delete($leaveRequest->file_path);
        }
        $data['file_path'] = $newFilePath;
    }

    $leaveRequest->update($data);

    /* ===============================
       🔔 NOTIFICATIONS (FULL UPDATE)
    ================================= */

    $employee = $leaveRequest->employee;
    $employeeName = $employee ? $employee->name : 'Employee';

    // ✅ EMPLOYEE
    \App\Models\Notification::create([
        'admin_id'    => $actor?->id,
        'employee_id' => $employee->id,
        'title'       => 'Leave Request Updated',
        'message'     => "Your leave request was updated by {$actorName}.",
        'is_read'     => false,
    ]);

    // ✅ ADMINS / SUB-ADMINS (EXCEPT ACTOR)
    $admins = \App\Models\Admin::all()->filter(function ($admin) use ($actor) {
        if ($actor && $admin->id === $actor->id) {
            return false;
        }

        return $admin->role === 'super_admin'
            || ($admin->role === 'sub_admin' && $admin->hasPermission('leave-requests'));
    });

    foreach ($admins as $adminUser) {
        \App\Models\Notification::create([
            'admin_id' => $adminUser->id,
            'title'    => 'Leave Request Updated',
            'message'  => "{$actorName} updated a leave request for {$employeeName}.",
            'is_read'  => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('admin.leave-requests.index')
        ->with('success', 'Leave request updated successfully.');
}


    /**
     * Remove the specified leave request from storage.
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->file_path) {
            Storage::disk('public')->delete($leaveRequest->file_path);
        }

        $leaveRequest->delete();

        return redirect()->route('admin.leave-requests.index')->with('success', 'Leave request deleted successfully.');
    }

    /**
     * Display leave requests for the authenticated employee.
     */
    public function employeeIndex()
    {
        $employee = Auth::guard('employee')->user();
        $leaveRequests = LeaveRequest::where('employee_id', $employee->id)->orderBy('created_at', 'desc')->paginate(10);
        return view('employee.leave-requests.index', compact('leaveRequests'));
    }

    /**
     * Show the specified leave request for the authenticated employee.
     */
    public function employeeShow(LeaveRequest $leaveRequest)
    {
        $employee = Auth::guard('employee')->user();

        // Ensure the employee owns the request
        if ($leaveRequest->employee_id !== $employee->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Log the request
        logger()->info("Request received: " . request()->method() . " " . request()->path());

        return response()->json([
            'id' => $leaveRequest->id,
            'leave_type' => $leaveRequest->leave_type,
            'start_date' => $leaveRequest->start_date,
            'end_date' => $leaveRequest->end_date,
            'days' => $leaveRequest->days,
            'subject' => $leaveRequest->subject,
            'description' => $leaveRequest->description,
            'status' => $leaveRequest->status,
            'remarks' => $leaveRequest->remarks,
            'created_at' => $leaveRequest->created_at,
            'file_path' => $leaveRequest->file_path,
        ]);
    }
}
