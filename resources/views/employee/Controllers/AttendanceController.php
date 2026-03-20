<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use DateTimeZone;
use App\Traits\Loggable;
use App\Exports\MonthlyAttendanceExport;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;


class AttendanceController extends Controller
{
    use Loggable;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $date = $request->get('date', Carbon::today('Asia/Kolkata')->format('Y-m-d'));
        $selectedDate = Carbon::parse($date, 'Asia/Kolkata');

        // Get all active employees with their attendance for the selected date
        $employees = Employee::where('status', 'active')->with(['attendance' => function($query) use ($date) {
            $query->where('date', $date);
        }])->get();

        // Get attendance statistics for the selected date
        $stats = [
            'total_employees' => $employees->count(),
            'present' => Attendance::where('date', $date)->where('status', 'Present')->count(),
            'absent' => Attendance::where('date', $date)->where('status', 'Absent')->count(),
            'leave' => Attendance::where('date', $date)->where('status', 'Leave')->count(),
            'half_day' => Attendance::where('date', $date)->where('status', 'Half Day')->count(),
            'holiday' => Attendance::where('date', $date)->where('status', 'Holiday')->count(),
            'ncns' => Attendance::where('date', $date)->where('status', 'NCNS')->count(),
            'lwp' => Attendance::where('date', $date)->where('status', 'LWP')->count(),
        ];

        return view('admin.attendance.index', compact('employees', 'selectedDate', 'stats', 'date'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $employees = Employee::all();
        return view('admin.attendance.create', compact('employees'));
    }

    /**
     * Store a newly created resource in storage.
     */
public function store(Request $request)
{
    try {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'status' => 'required|in:Present,Absent,Leave,Half Day,Holiday,NCNS,LWP',
            'remarks' => 'nullable|string|max:255',
            'mark_in' => 'nullable|date_format:H:i:s',
            'mark_out' => 'nullable|date_format:H:i:s',
            'break_time' => 'nullable|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/',
        ]);

        // Logged-in admin
        $loggedAdmin = auth('admin')->user();

        // Check if attendance already exists
        $existingAttendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        /* ===============================
           UPDATE CASE
        ================================= */
        if ($existingAttendance) {
            $existingAttendance->update([
                'status' => $request->status,
                'remarks' => $request->remarks,
            ]);

            $this->logActivity(
                'updated',
                'Attendance',
                $existingAttendance->id,
                'Updated attendance for ' . $existingAttendance->employee->name .
                ' to ' . $existingAttendance->status . ' on ' . $existingAttendance->date
            );

            // ✅ AJAX RESPONSE
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Attendance updated successfully.',
                    'attendance' => $existingAttendance,
                ]);
            }

            return redirect()
                ->route('attendance.index')
                ->with('success', 'Attendance record updated successfully.');
        }

        /* ===============================
           CREATE CASE
        ================================= */
        $attendance = Attendance::create($request->all());

        $this->logActivity(
            'created',
            'Attendance',
            $attendance->id,
            'Marked attendance for ' . $attendance->employee->name .
            ' as ' . $attendance->status . ' on ' . $attendance->date
        );

        /* ===============================
           🔔 NOTIFICATIONS (SKIP SELF)
        ================================= */

     $employeeName = $attendance->employee
    ? $attendance->employee->name
    : ('ID: ' . $attendance->employee_id);

$actorName = $loggedAdmin ? $loggedAdmin->name : 'Admin';

$admins = \App\Models\Admin::all()->filter(function ($admin) use ($loggedAdmin) {
    // ❌ skip self
    if ($loggedAdmin && $admin->id === $loggedAdmin->id) {
        return false;
    }

    return $admin->role === 'super_admin'
        || ($admin->role === 'sub_admin' && $admin->hasPermission('attendance'));
});

foreach ($admins as $adminUser) {
    \App\Models\Notification::create([
        'admin_id' => $adminUser->id,
        'title' => 'Attendance Marked',
        'message' => "{$actorName} marked attendance for employee: {$employeeName}",
        'is_read' => false,
    ]);
}


        /* =============================== */

        // ✅ AJAX RESPONSE
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Attendance marked successfully.',
                'attendance' => $attendance,
            ]);
        }

        return redirect()
            ->route('attendance.index')
            ->with('success', 'Attendance record created successfully.');

    } catch (\Illuminate\Validation\ValidationException $e) {
        throw $e;
    } catch (\Exception $e) {
        Log::error('Attendance store error: ' . $e->getMessage(), [
            'request' => $request->all(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }

        throw $e;
    }
}



    /**
     * Display the specified resource.
     */
   public function show($id)
{
    $attendance = Attendance::with('employee')->findOrFail($id);

    // Calculate TWH (Total Working Hours)
    $twh = '-';
    if ($attendance->mark_in && $attendance->mark_out) {
        try {
            // Parse times more safely - handle potential microseconds or extra data
            $markInTime = substr($attendance->mark_in, 0, 8); // Take only HH:MM:SS
            $markOutTime = substr($attendance->mark_out, 0, 8); // Take only HH:MM:SS

            $date = $attendance->date->format('Y-m-d');
            $markIn = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $date . ' ' . $markInTime, 'Asia/Kolkata');

            // Handle case where mark_out might be next day (if time is less than mark_in)
            $markOutDate = $date;
            if ($markOutTime < $markInTime) {
                $markOutDate = \Carbon\Carbon::createFromFormat('Y-m-d', $date, 'Asia/Kolkata')->addDay()->format('Y-m-d');
            }
            $markOut = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $markOutDate . ' ' . $markOutTime, 'Asia/Kolkata');

            // Calculate total seconds manually to avoid timezone issues
            $markInSeconds = ($markIn->hour * 3600) + ($markIn->minute * 60) + $markIn->second;
            $markOutSeconds = ($markOut->hour * 3600) + ($markOut->minute * 60) + $markOut->second;
            $totalSeconds = $markOutSeconds - $markInSeconds;

            // Subtract break time - break_time is always duration in HH:MM:SS format
            if ($attendance->break_time && $attendance->break_time !== '00:00:00') {
                $breakTime = substr($attendance->break_time, 0, 8); // Take only HH:MM:SS
                $breakParts = explode(':', $breakTime);
                if (count($breakParts) === 3) {
                    $breakSeconds = ($breakParts[0] * 3600) + ($breakParts[1] * 60) + $breakParts[2];
                    $totalSeconds -= $breakSeconds;
                }
            }

            // TWH = Mark Out - Mark In - Break Time
            $workingSeconds = max(0, $totalSeconds);
            $hours = floor($workingSeconds / 3600);
            $minutes = floor(($workingSeconds % 3600) / 60);
            $seconds = $workingSeconds % 60;
            $twh = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } catch (\Exception $e) {
            // If parsing fails, show error in debug
            $twh = 'Error';
            error_log('TWH calculation error for attendance ' . $attendance->id . ': ' . $e->getMessage() . ' | Date: ' . $attendance->date . ' | Mark In: ' . $attendance->mark_in . ' | Mark Out: ' . $attendance->mark_out);
        }
    }

    return response()->json([
        'success' => true,
        'employee' => $attendance->employee->name,
        'status' => $attendance->status,
        'mark_in' => $attendance->mark_in,
        'mark_out' => $attendance->mark_out,
        'break_time' => $attendance->break_time,
        'twh' => $twh,
        'marked_time' => $attendance->marked_time,
        'marked_by' => $attendance->marked_by_type,
        'ip' => $attendance->ip_address,
        'image' => $attendance->image
            ? asset('storage/'.$attendance->image)
            : null,
    ]);
}


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Attendance $attendance)
    {
        $employees = Employee::all();
        return view('admin.attendance.edit', compact('attendance', 'employees'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Attendance $attendance)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'status' => 'required|in:Present,Absent,Leave,Half Day,Holiday,NCNS,LWP',
            'remarks' => 'nullable|string|max:255',
            'marked_time' => 'required|date_format:H:i',
            'mark_in' => 'nullable|date_format:H:i',
            'mark_out' => 'nullable|date_format:H:i',
            'break_time' => 'nullable|regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/',
        ]);

        $attendance->employee_id = $request->employee_id;
        $attendance->date = $request->date;
        $attendance->status = $request->status;
        $attendance->remarks = $request->remarks;

        // Update time fields
        $attendance->mark_in = $request->mark_in ? $request->mark_in . ':00' : null;
        $attendance->mark_out = $request->mark_out ? $request->mark_out . ':00' : null;
        $attendance->break_time = $request->break_time ?: null;

        // Update updated_at with marked_time on the same date
        $dateTimeString = $request->date . ' ' . $request->marked_time;
        $attendance->updated_at = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $dateTimeString, 'Asia/Kolkata');

        $attendance->save();

        // Log activity
        $this->logActivity('updated', 'Attendance', $attendance->id, 'Updated attendance for ' . $attendance->employee->name . ' to ' . $attendance->status . ' on ' . $attendance->date);

        return redirect()->route('attendance.index')->with('success', 'Attendance record updated successfully.');
    }

    /**
     * Mark in time for attendance
     */
    public function markIn(Request $request, Attendance $attendance)
    {
        $request->validate([
            'mark_in' => 'required|date_format:H:i:s',
            'status' => 'required|in:Present,Half Day',
        ]);

        $attendance->update([
            'mark_in' => $request->mark_in,
            'status' => $request->status,
        ]);

        // Log activity
        $this->logActivity('updated', 'Attendance', $attendance->id, 'Marked in time for ' . $attendance->employee->name . ' as ' . $attendance->status);

        return response()->json([
            'success' => true,
            'message' => 'Mark in time updated successfully.',
        ]);
    }

    /**
     * Mark out time for attendance
     */
    public function markOut(Request $request, Attendance $attendance)
    {
        $request->validate([
            'mark_out' => 'required|date_format:H:i:s',
            'is_report_submitted' => 'required|boolean',
        ]);

        $attendance->update([
            'mark_out' => $request->mark_out,
        ]);

        // Store report submission status
        \App\Models\ReportSubmission::updateOrCreate(
            [
                'employee_id' => $attendance->employee_id,
                'report_date' => $attendance->date,
            ],
            [
                'is_submitted' => $request->is_report_submitted,
            ]
        );

        // Log activity
        $this->logActivity('updated', 'Attendance', $attendance->id, 'Marked out time for ' . $attendance->employee->name . ' with report status: ' . ($request->is_report_submitted ? 'Submitted' : 'Not Submitted'));

        return response()->json([
            'success' => true,
            'message' => 'Mark out time updated successfully.',
        ]);
    }

    /**
     * Mark break time for attendance
     */
    public function markBreak(Request $request, Attendance $attendance)
    {
        $request->validate([
            'break_time' => 'required|date_format:H:i:s',
        ]);

        // If break_start is set, calculate duration and store as HH:MM:SS format
        if ($attendance->break_start) {
            $breakStart = Carbon::createFromFormat('H:i:s', $attendance->break_start, 'Asia/Kolkata');
            $breakEnd = Carbon::createFromFormat('H:i:s', $request->break_time, 'Asia/Kolkata');
            $breakDuration = $breakEnd->diff($breakStart);

            // Format duration as HH:MM:SS
            $breakTimeFormatted = sprintf('%02d:%02d:%02d',
                $breakDuration->h,
                $breakDuration->i,
                $breakDuration->s
            );

            $attendance->update([
                'break_time' => $breakTimeFormatted,
            ]);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Ended break for ' . $attendance->employee->name . ' (Duration: ' . $breakTimeFormatted . ')');
        } else {
            // If no break_start, store as end time (legacy behavior)
            $attendance->update([
                'break_time' => $request->break_time,
            ]);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Marked break time for ' . $attendance->employee->name);
        }

        return response()->json([
            'success' => true,
            'message' => 'Break time updated successfully.',
        ]);
    }

    /**
     * Start break for attendance (set break_start time)
     */
    public function startBreak(Request $request, Attendance $attendance)
    {
        $request->validate([
            'break_start' => 'required|date_format:H:i:s',
        ]);

        $attendance->update([
            'break_start' => $request->break_start,
        ]);

        // Log activity
        $this->logActivity('updated', 'Attendance', $attendance->id, 'Started break for ' . $attendance->employee->name);

        return response()->json([
            'success' => true,
            'message' => 'Break started successfully.',
        ]);
    }

    /**
     * Mark in direct - creates attendance if doesn't exist
     */
    public function markInDirect(Request $request)
    {
        // Check if user is admin or employee
        $isAdmin = auth('admin')->check();
        $isEmployee = auth('employee')->check();

        $validationRules = [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'mark_in' => 'required|date_format:H:i:s',
        ];

        // Only require image for employees, not for admins
        if (!$isAdmin) {
            $validationRules['image'] = 'required|string';
        } else {
            $validationRules['image'] = 'nullable|string';
        }

        $request->validate($validationRules);

        // Get IP address
        $userIp = $request->header('CF-Connecting-IP')
            ?? $request->header('X-Forwarded-For')
            ?? $request->ip();
        $userIp = trim(explode(',', $userIp)[0]);

        // Handle image (only if provided)
        $path = null;
        if ($request->image) {
            $imageData = $request->image;
            if (!preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                return response()->json(['success' => false, 'message' => 'Invalid image format'], 400);
            }
            $image = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
            if ($image === false) {
                return response()->json(['success' => false, 'message' => 'Image decoding failed'], 400);
            }
            $extension = strtolower($type[1]);
            if (!in_array($extension, ['jpg', 'jpeg', 'png'])) {
                return response()->json(['success' => false, 'message' => 'Invalid image type'], 400);
            }
            $fileName = 'attendance_' . $request->employee_id . '_' . now()->timestamp . '.' . $extension;
            $path = 'attendance/' . $fileName;
            Storage::disk('public')->put($path, $image);
        }

        // Check if attendance already exists
        $attendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        // Check if mark_in is already set for this day
        if ($attendance && $attendance->mark_in) {
            return response()->json([
                'success' => false,
                'message' => 'Mark In has already been done for this day.',
            ], 400);
        }

        // Determine status based on time (before 9:30 = Present, after = Half Day)
        $markInTime = Carbon::createFromFormat('H:i:s', $request->mark_in, 'Asia/Kolkata');
        $halfDayThreshold = Carbon::createFromTime(9, 30, 0, 'Asia/Kolkata');
        $status = $markInTime->greaterThan($halfDayThreshold) ? 'Half Day' : 'Present';

        if ($attendance) {
            // Update existing attendance
            $updateData = [
                'mark_in' => $request->mark_in,
                'status' => $status,
                'ip_address' => $userIp,
                'marked_time' => now()->format('H:i:s'),
                'marked_by_type' => 'Employee',
            ];
            if ($path) {
                $updateData['image'] = $path;
            }
            $attendance->update($updateData);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Marked in time for ' . $attendance->employee->name . ' as ' . $status);
        } else {
            // Create new attendance record
            $createData = [
                'employee_id' => $request->employee_id,
                'date' => $request->date,
                'status' => $status,
                'mark_in' => $request->mark_in,
                'ip_address' => $userIp,
                'marked_time' => now()->format('H:i:s'),
                'marked_by_type' => 'Employee',
            ];
            if ($path) {
                $createData['image'] = $path;
            }
            $attendance = Attendance::create($createData);

            $this->logActivity('created', 'Attendance', $attendance->id, 'Created attendance and marked in for ' . $attendance->employee->name . ' as ' . $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mark in successful.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Mark out direct - creates attendance if doesn't exist
     */
    public function markOutDirect(Request $request)
    {
        // Check if user is admin or employee
        $isAdmin = auth('admin')->check();
        $isEmployee = auth('employee')->check();

        $validationRules = [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'mark_out' => 'required|date_format:H:i:s',
        ];

        // Only require image for employees, not for admins
        if (!$isAdmin) {
            $validationRules['image'] = 'required|string';
        } else {
            $validationRules['image'] = 'nullable|string';
        }

        $request->validate($validationRules);

        // Validation: Check if mark_in is set before allowing mark_out
        $existingAttendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        if (!$existingAttendance || !$existingAttendance->mark_in) {
            return response()->json([
                'success' => false,
                'message' => 'Mark In is required before Mark Out.',
            ], 400);
        }

        // Validation: Check if mark_out is already set for this day
        if ($existingAttendance->mark_out) {
            return response()->json([
                'success' => false,
                'message' => 'Mark Out has already been done for this day.',
            ], 400);
        }

        // Get IP address
        $userIp = $request->header('CF-Connecting-IP')
            ?? $request->header('X-Forwarded-For')
            ?? $request->ip();
        $userIp = trim(explode(',', $userIp)[0]);

        // Handle image (only if provided)
        $path = null;
        if ($request->image) {
            $imageData = $request->image;
            if (!preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                return response()->json(['success' => false, 'message' => 'Invalid image format'], 400);
            }
            $image = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
            if ($image === false) {
                return response()->json(['success' => false, 'message' => 'Image decoding failed'], 400);
            }
            $extension = strtolower($type[1]);
            if (!in_array($extension, ['jpg', 'jpeg', 'png'])) {
                return response()->json(['success' => false, 'message' => 'Invalid image type'], 400);
            }
            $fileName = 'attendance_' . $request->employee_id . '_' . now()->timestamp . '.' . $extension;
            $path = 'attendance/' . $fileName;
            Storage::disk('public')->put($path, $image);
        }

        // Check if attendance already exists
        $attendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        if ($attendance) {
            // Update existing attendance
            $attendance->update([
                'mark_out' => $request->mark_out,
                'image' => $path,
                'ip_address' => $userIp,
                'marked_time' => now()->format('H:i:s'),
                'marked_by_type' => 'Employee',
            ]);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Marked out time for ' . $attendance->employee->name);
        } else {
            // Create new attendance record
            $attendance = Attendance::create([
                'employee_id' => $request->employee_id,
                'date' => $request->date,
                'status' => 'Present', // Default status when creating
                'mark_out' => $request->mark_out,
                'image' => $path,
                'ip_address' => $userIp,
                'marked_time' => now()->format('H:i:s'),
                'marked_by_type' => 'Employee',
            ]);

            $this->logActivity('created', 'Attendance', $attendance->id, 'Created attendance and marked out for ' . $attendance->employee->name);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mark out successful.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Start break direct - records break start time
     */
    public function startBreakDirect(Request $request)
    {
        // Check if user is admin or employee
        $isAdmin = auth('admin')->check();
        $isEmployee = auth('employee')->check();

        $validationRules = [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'break_start' => 'required|string', // Format: HH:MM:SS
        ];

        // Only require image for employees, not for admins
        if (!$isAdmin) {
            $validationRules['image'] = 'required|string';
        } else {
            $validationRules['image'] = 'nullable|string';
        }

        $request->validate($validationRules);

        // Validation: Check if mark_in is set before allowing start break
        $existingAttendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        if (!$existingAttendance || !$existingAttendance->mark_in) {
            return response()->json([
                'success' => false,
                'message' => 'Mark In is required before starting break.',
            ], 400);
        }

        // Check if attendance already exists
        $attendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        if ($attendance) {
            // Update existing attendance
            $attendance->update([
                'break_start' => $request->break_start,
            ]);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Started break for ' . $attendance->employee->name);
        } else {
            // Create new attendance record
            $attendance = Attendance::create([
                'employee_id' => $request->employee_id,
                'date' => $request->date,
                'status' => 'Present', // Default status when creating
                'break_start' => $request->break_start,
            ]);

            $this->logActivity('created', 'Attendance', $attendance->id, 'Created attendance and started break for ' . $attendance->employee->name);
        }

        return response()->json([
            'success' => true,
            'message' => 'Break started successfully.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * End break direct - calculates and records break duration
     */
    public function endBreakDirect(Request $request)
    {
        // Check if user is admin or employee
        $isAdmin = auth('admin')->check();
        $isEmployee = auth('employee')->check();

        $validationRules = [
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'break_time' => 'required|string', // Format: HH:MM:SS (end time)
        ];

        // Only require image for employees, not for admins
        if (!$isAdmin) {
            $validationRules['image'] = 'required|string';
        } else {
            $validationRules['image'] = 'nullable|string';
        }

        $request->validate($validationRules);

        // Check if attendance already exists
        $attendance = Attendance::where('employee_id', $request->employee_id)
            ->where('date', $request->date)
            ->first();

        $breakTimeFormatted = '00:00:00'; // Default duration

        if ($attendance && $attendance->break_start) {
            // Calculate break duration using break_start and the sent break_time as end time
            $breakStart = Carbon::createFromFormat('H:i:s', $attendance->break_start, 'Asia/Kolkata');
            $breakEnd = Carbon::createFromFormat('H:i:s', $request->break_time, 'Asia/Kolkata');
            $breakDuration = $breakEnd->diff($breakStart);

            // Format duration as HH:MM:SS
            $breakTimeFormatted = sprintf('%02d:%02d:%02d',
                $breakDuration->h,
                $breakDuration->i,
                $breakDuration->s
            );

            // Update existing attendance
            $attendance->update([
                'break_time' => $breakTimeFormatted,
            ]);

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Ended break for ' . $attendance->employee->name . ' (Duration: ' . $breakTimeFormatted . ')');
        } else {
            // If no break_start, create or update attendance with default break time
            $attendance = Attendance::updateOrCreate(
                [
                    'employee_id' => $request->employee_id,
                    'date' => $request->date,
                ],
                [
                    'status' => 'Present', // Default status when creating
                    'break_time' => $breakTimeFormatted,
                ]
            );

            $this->logActivity('updated', 'Attendance', $attendance->id, 'Ended break for ' . $attendance->employee->name . ' (No start time recorded)');
        }

        return response()->json([
            'success' => true,
            'message' => 'Break ended successfully.',
            'attendance' => $attendance,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attendance $attendance)
    {
        $employeeName = $attendance->employee->name;
        $date = $attendance->date;

        $attendance->delete();

        // Log activity
        $this->logActivity('deleted', 'Attendance', null, 'Deleted attendance for ' . $employeeName . ' on ' . $date);

        return redirect()->route('attendance.index')->with('success', 'Attendance record deleted successfully.');
    }

    /**
     * Bulk update attendance records
     */
    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'attendance' => 'required|array',
            'attendance.*.id' => 'nullable|exists:attendances,id',
            'attendance.*.employee_id' => 'required|exists:employees,id',
            'attendance.*.status' => 'required|in:Present,Absent,Leave,Half Day,Holiday,NCNS,LWP',
            'attendance.*.remarks' => 'nullable|string|max:255',
            'date' => 'required|date',
        ]);

        $date = $request->date;

        foreach ($request->attendance as $attendanceData) {
            if (isset($attendanceData['id'])) {
                // Update existing record
                $attendance = Attendance::find($attendanceData['id']);
                if ($attendance) {
                    $attendance->update([
                        'status' => $attendanceData['status'],
                        'remarks' => $attendanceData['remarks'] ?? null,
                    ]);
                }
            } else {
                // Create new record
                Attendance::create([
                    'employee_id' => $attendanceData['employee_id'],
                    'date' => $date,
                    'status' => $attendanceData['status'],
                    'remarks' => $attendanceData['remarks'] ?? null,
                ]);
            }
        }

        return redirect()->route('attendance.index', ['date' => $date])->with('success', 'Attendance records updated successfully.');
    }

    /**
     * Show monthly attendance selection form
     */
    public function monthly(Request $request, $employee = null)
    {
        $employees = Employee::all();
        $selectedEmployee = null;

        if ($employee) {
            $selectedEmployee = Employee::findOrFail($employee);
        }

        $year = $request->get('year', Carbon::now()->year);
        $month_num = $request->get('month_num', Carbon::now()->month);

        return view('admin.attendance.monthly', compact('employees', 'selectedEmployee', 'year', 'month_num'));
    }

  
    /**
     * Show monthly attendance data for selected employee and month
     */
    public function showMonthly(Request $request)
    {
        $request->validate([
            'employee_id' => 'required',
            'year' => 'required|integer|min:2000|max:2030',
            'month_num' => 'required|integer|min:1|max:12',
        ]);

        $employees = Employee::all();
        $year = $request->year;
        $monthNum = $request->month_num;
        $month = sprintf('%04d-%02d', $year, $monthNum);

        // Parse month and year
        $date = Carbon::createFromFormat('Y-m', $month, 'Asia/Kolkata');
        $year = $date->year;
        $monthNum = $date->month;

        if ($request->employee_id === 'all') {
            // Show summary for all employees
            $selectedEmployee = 'all';

            // Get attendance data for all employees for the selected month
            $attendances = Attendance::with('employee')
                ->whereYear('date', $year)
                ->whereMonth('date', $monthNum)
                ->orderBy('employee_id')
                ->orderBy('date')
                ->get();

            // Get the selected month start and end dates
            $selectedMonthStart = Carbon::create($year, $monthNum, 1, 0, 0, 0, 'Asia/Kolkata');
            $selectedMonthEnd = $selectedMonthStart->copy()->endOfMonth();

            // Get all employees and create summaries (even for those with no attendance)
            $employeeSummaries = [];
            foreach ($employees as $employee) {
                // Determine inactive date - use updated_at if employee is currently inactive, resigned, or terminated
                $inactiveDate = null;
                if (in_array($employee->status, ['inactive', 'resigned', 'terminated'])) {
                    $inactiveDate = Carbon::parse($employee->updated_at);
                }

                // Determine if employee should be included based on inactive date
                $shouldInclude = true;
                if ($inactiveDate) {
                    // If inactive date is before or on the selected month start, don't include
                    if ($inactiveDate->lte($selectedMonthStart)) {
                        $shouldInclude = false;
                    }
                }

                if (!$shouldInclude) {
                    continue;
                }

                $employeeAttendances = $attendances->where('employee_id', $employee->id);

                // Filter attendances up to inactive date if exists
                if ($inactiveDate) {
                    $employeeAttendances = $employeeAttendances->filter(function ($att) use ($inactiveDate) {
                        return Carbon::parse($att->date)->lte($inactiveDate);
                    });
                }

                $summary = [
                    'employee' => $employee,
                    'total_days' => $employeeAttendances->count(),
                    'present' => $employeeAttendances->where('status', 'Present')->count(),
                    'absent' => $employeeAttendances->where('status', 'Absent')->count(),
                    'leave' => $employeeAttendances->where('status', 'Leave')->count(),
                    'half_day' => $employeeAttendances->where('status', 'Half Day')->count(),
                    'holiday' => $employeeAttendances->where('status', 'Holiday')->count(),
                    'ncns' => $employeeAttendances->where('status', 'NCNS')->count(),
                    'lwp' => $employeeAttendances->where('status', 'LWP')->count(),
                    'inactive_date' => $inactiveDate,
                ];

                // Calculate salary for the month
                $attendanceData = [
                    'total_days' => $summary['total_days'],
                    'present' => $summary['present'],
                    'absent' => $summary['absent'],
                    'leave' => $summary['leave'],
                    'half_day' => $summary['half_day'],
                    'holiday' => $summary['holiday'],
                    'ncns' => $summary['ncns'],
                    'lwp' => $summary['lwp'],
                ];
                $salaryData = $this->calculateSalary($employee, $attendanceData, [], $month);
                $summary['total_salary'] = $salaryData['net_salary'];

                $employeeSummaries[$employee->id] = $summary;
            }

            // Group attendances by employee_id and date string for correct lookup in view
            $attendances = $attendances->groupBy([
                'employee_id',
                function ($item) {
                    return \Carbon\Carbon::parse($item->date)->format('Y-m-d');
                }
            ]);

            return view('admin.attendance.monthly', [
                'employees' => $employees,
                'selectedEmployee' => $selectedEmployee,
                'month' => $month,
                'year' => $year,
                'month_num' => $monthNum,
                'employeeSummaries' => $employeeSummaries,
                'attendances' => $attendances,
            ]);
        } else {
            // Show individual employee data
            $employee = Employee::findOrFail($request->employee_id);
            $selectedEmployee = $employee;

            // Get attendance data for the selected month and employee
            $attendances = Attendance::where('employee_id', $request->employee_id)
                ->whereYear('date', $year)
                ->whereMonth('date', $monthNum)
                ->orderBy('date')
                ->get();

            // Calculate summary
            $summary = [
                'total_days' => $attendances->count(),
                'present' => $attendances->where('status', 'Present')->count(),
                'absent' => $attendances->where('status', 'Absent')->count(),
                'leave' => $attendances->where('status', 'Leave')->count(),
                'half_day' => $attendances->where('status', 'Half Day')->count(),
                'holiday' => $attendances->where('status', 'Holiday')->count(),
                'ncns' => $attendances->where('status', 'NCNS')->count(),
                'lwp' => $attendances->where('status', 'LWP')->count(),
            ];

            // Get all days in the month for calendar view
            $daysInMonth = $date->daysInMonth;
            $monthlyData = [];

            // Determine inactive date for resigned/terminated employees
            $inactiveDate = null;
            if (in_array($employee->status, ['resigned', 'terminated'])) {
                $inactiveDate = Carbon::parse($employee->updated_at);
            }

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $currentDate = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Asia/Kolkata');
                // Use closure for accurate date comparison
                $attendance = $attendances->first(function ($att) use ($currentDate) {
                    return $att->date->format('Y-m-d') === $currentDate->format('Y-m-d');
                });

                $status = $attendance ? $attendance->status : 'Not Marked';
                // If no attendance and employee is resigned/terminated and current date is after inactive date, show status
                if (!$attendance && $inactiveDate && $currentDate->gt($inactiveDate)) {
                        $status = ucfirst($employee->status);
                }

                $monthlyData[] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'day' => $day,
                    'day_name' => $currentDate->format('D'),
                    'status' => $status,
                    'marked_at' => $attendance ? $attendance->updated_at->setTimezone('Asia/Kolkata')->format('H:i') : null,
                    'remarks' => $attendance ? $attendance->remarks : null,
                ];
            }

            return view('admin.attendance.monthly', [
                'employees' => $employees,
                'employee' => $employee,
                'selectedEmployee' => $selectedEmployee,
                'month' => $month,
                'year' => $year,
                'month_num' => $monthNum,
                'summary' => $summary,
                'monthlyData' => $monthlyData,
                'attendances' => $attendances,
            ]);
        }
    }

    /**
     * Generate attendance report
     */
    public function report(Request $request)
    {
        $date = $request->get('date', Carbon::today('Asia/Kolkata')->format('Y-m-d'));
        $month = $request->get('month', Carbon::today('Asia/Kolkata')->format('m'));
        $year = $request->get('year', Carbon::today('Asia/Kolkata')->format('Y'));

        // Get attendance data for the selected month
        $attendances = Attendance::with('employee')
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date')
            ->orderBy('employee_id')
            ->get();

        // Group by employee for summary
        $employeeSummary = $attendances->groupBy('employee_id')->map(function ($records) {
            return [
                'employee' => $records->first()->employee,
                'total_days' => $records->count(),
                'present' => $records->where('status', 'Present')->count(),
                'absent' => $records->where('status', 'Absent')->count(),
                'leave' => $records->where('status', 'Leave')->count(),
                'half_day' => $records->where('status', 'Half Day')->count(),
                'holiday' => $records->where('status', 'Holiday')->count(),
                'records' => $records,
            ];
        });

        return view('admin.attendance.report', compact('attendances', 'employeeSummary', 'month', 'year', 'date'));
    }

    /**
     * Export monthly attendance data for selected employee and month to Excel
     */
    public function exportMonthly(Request $request)
    {
        $request->validate([
            'employee_id' => 'required',
            'month' => 'required|date_format:Y-m',
        ]);

        $date = Carbon::createFromFormat('Y-m', $request->month, 'Asia/Kolkata');
        $year = $date->year;
        $month = $date->month;

        if ($request->employee_id === 'all') {
            $fileName = 'All_Employees_Attendance_' . $date->format('F_Y') . '.xlsx';
        } else {
            $employee = Employee::findOrFail($request->employee_id);
            $fileName = $employee->name . '_Attendance_' . $date->format('F_Y') . '.xlsx';
        }

        return Excel::download(new MonthlyAttendanceExport($request->employee_id, $month, $year), $fileName);
    }

    /**
     * Export today's attendance data to PDF
     */
    public function exportTodayPdf(Request $request)
    {
        $date = $request->get('date', Carbon::today('Asia/Kolkata')->format('Y-m-d'));
        $selectedDate = Carbon::parse($date, 'Asia/Kolkata');

        // Get all active employees with their attendance for the selected date
        $employees = Employee::where('status', 'active')->with(['attendance' => function($query) use ($date) {
            $query->where('date', $date);
        }])->get();

        // Get attendance statistics for the selected date
        $stats = [
            'total_employees' => $employees->count(),
            'present' => Attendance::where('date', $date)->where('status', 'Present')->count(),
            'absent' => Attendance::where('date', $date)->where('status', 'Absent')->count(),
            'leave' => Attendance::where('date', $date)->where('status', 'Leave')->count(),
            'half_day' => Attendance::where('date', $date)->where('status', 'Half Day')->count(),
            'holiday' => Attendance::where('date', $date)->where('status', 'Holiday')->count(),
            'ncns' => Attendance::where('date', $date)->where('status', 'NCNS')->count(),
            'lwp' => Attendance::where('date', $date)->where('status', 'LWP')->count(),
        ];

        $pdf = Pdf::loadView('admin.attendance.pdf', compact('employees', 'selectedDate', 'stats', 'date'));
        $fileName = 'Attendance_' . $selectedDate->format('Y-m-d') . '.pdf';

        return $pdf->download($fileName);
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



public function syncOfficeIp(Request $request)
{
    // ✅ Only admin allowed



    $userIp = $request->header('CF-Connecting-IP')
        ?? $request->header('X-Forwarded-For')
        ?? $request->ip();

    $userIp = trim(explode(',', $userIp)[0]);

    // 🚫 Block IPv6
    if (filter_var($userIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return back()->with('error', 'IPv6 not allowed');
    }

    $today = now()->toDateString();

    DB::table('office_ips')->updateOrInsert(
        ['date' => $today],
        ['ip' => $userIp,]
    );

    return back()->with('success', "Office IP synced: $userIp");
}


public function mark(Request $request)
{
    $userIp = $request->header('CF-Connecting-IP')
        ?? $request->header('X-Forwarded-For')
        ?? $request->ip();

    $userIp = trim(explode(',', $userIp)[0]);

    // ✅ Get today's synced office IP
    $officeIp = DB::table('office_ips')
        ->where('date', now()->toDateString())
        ->first();

    // ❌ Not synced OR IP mismatch
    if (!$officeIp || $officeIp->ip !== $userIp) {

        // ✅ AJAX / JS request
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance not allowed'
            ], 403);
        }

        // ✅ Normal browser
        return $this->denyAttendance($request);
    }

    // ✅ ALLOWED
    if ($request->expectsJson()) {
        return response()->json([
            'success' => true,
            'message' => 'Attendance allowed'
        ], 200);
    }

    // ✅ Get current attendance status for today
    $employeeId = auth('employee')->id();
    $today = now()->toDateString();
    $attendance = Attendance::where('employee_id', $employeeId)
        ->where('date', $today)
        ->first();

    $hasMarkedIn = $attendance && $attendance->mark_in;
    $hasMarkedOut = $attendance && $attendance->mark_out;
    $breakStarted = $attendance && $attendance->break_start && !$attendance->break_time;

    // ✅ Normal page load
    return view('employee.attendance.mark', compact('hasMarkedIn', 'hasMarkedOut', 'breakStarted'));
}

/**
 * ❌ Common deny response (AJAX + normal)
 */
private function denyAttendance(Request $request)
{
    if ($request->expectsJson()) {
        return response()->json([
            'success' => false,
            'message' => 'Attendance can only be marked from Office WiFi'
        ], 403);
    }

    abort(403, 'Attendance can only be marked from Office WiFi');
}




public function submit(Request $request)
{
    $request->validate([
        'image' => 'required|string',
    ]);

    $employeeId = auth('employee')->id();
    $today = now()->toDateString();

    // ✅ Decide status by server time
    $now = Carbon::now('Asia/Kolkata');
    $halfDayTime = Carbon::createFromTime(9, 30, 0, 'Asia/Kolkata');
    $status = $now->greaterThan($halfDayTime) ? 'Half Day' : 'Present';

    /* -----------------------------
       IMAGE HANDLE
    ------------------------------ */
    $imageData = $request->image;

    if (!preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
        return back()->with('error', 'Invalid image format');
    }

    $image = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
    if ($image === false) {
        return back()->with('error', 'Image decoding failed');
    }

    $extension = strtolower($type[1]);
    if (!in_array($extension, ['jpg', 'jpeg', 'png'])) {
        return back()->with('error', 'Invalid image type');
    }

    $fileName = 'attendance_' . $employeeId . '_' . now()->timestamp . '.' . $extension;
    $path = 'attendance/' . $fileName;
    Storage::disk('public')->put($path, $image);

    /* --------------------------------
       ✅ INSERT OR UPDATE (KEY FIX)
    -------------------------------- */

    Attendance::updateOrCreate(
        [
            'employee_id' => $employeeId,
            'date'        => $today,      // ✅ unique key
        ],
        [
            'status'        => $status,
            'marked_time'   => $now->format('H:i:s'),
            'ip_address'    => $request->ip(),
            'image'         => $path,
            'marked_by_type'=> 'Employee',
        ]
    );

    return redirect()->route('employee.attendance')
            ->with('success', 'Attendance saved as ' . $status);
}






public function autoMarkOutByGet(Request $request)
{
    $request->validate([
        'date' => 'nullable|date',
    ]);

    $timezone = config('attendance.timezone', 'Asia/Kolkata');
    $targetDate = $request->date ?: Carbon::today($timezone)->format('Y-m-d');
    $markOutTime = config('attendance.auto_mark_out_time', '11:53:00');

    $targetDateTime = Carbon::createFromFormat(
        'Y-m-d H:i:s',
        $targetDate . ' ' . $markOutTime,
        $timezone
    );

    $now = Carbon::now($timezone);
    $secondsRemaining = $now->lt($targetDateTime) ? $now->diffInSeconds($targetDateTime) : 0;

    // Browser hit par immediate response dene ke liye wait/sleep remove kiya.
    if ($secondsRemaining > 0) {
        return response()->json([
            'success' => true,
            'message' => 'Route hit successful. Auto mark out time not reached yet.',
            'date' => $targetDate,
            'mark_out_time' => $markOutTime,
            'server_now' => $now->format('Y-m-d H:i:s'),
            'seconds_remaining' => $secondsRemaining,
            'updated_count' => 0,
        ]);
    }

    $updatedCount = Attendance::where('date', $targetDate)
        ->whereNotNull('mark_in')
        ->whereNull('mark_out')
        ->update([
            'mark_out' => $markOutTime,
            'marked_time' => $markOutTime,
            'marked_by_type' => 'Admin',
        ]);

    return response()->json([
        'success' => true,
        'message' => 'Route hit successful. Auto mark out completed.',
        'date' => $targetDate,
        'mark_out_time' => $markOutTime,
        'server_now' => $now->format('Y-m-d H:i:s'),
        'updated_count' => $updatedCount,
    ]);
}

}
