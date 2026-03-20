<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Report;
use App\Models\ReportSubmission;
use App\Models\Task;
use App\Traits\Loggable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class EmployeeController extends Controller
{
    use Loggable;
    /**
     * Display a listing of the employees.
     */
    public function index()
    {
        $employees = Employee::select('id', 'employee_code', 'name', 'email', 'phone', 'position', 'department', 'status', 'profile_image')->paginate(10);

        // Fetch distinct departments for filter dropdown
        $departments = Employee::select('department')->distinct()->whereNotNull('department')->orderBy('department')->pluck('department');

        return view('employee.index', compact('employees', 'departments'));
    }

    /**
     * Show the form for creating a new employee.
     */
    public function create()
    {
        return view('employee.create');
    }

    /**
     * Store a newly created employee in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_code' => 'required|string|max:50|unique:employees,employee_code',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:employees,email',
            'password' => 'required|string|min:6|confirmed',
            'phone' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'required|date',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'status' => ['required', Rule::in(['active', 'inactive', 'terminated', 'resigned'])],
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            // Family Details
            'relations' => 'nullable|array',
            'relations.*' => 'nullable|in:father,mother,spouse,son,daughter,brother,sister,emergency',
            'family_names' => 'nullable|array',
            'family_names.*' => 'nullable|string|max:255',
            'contact_numbers' => 'nullable|array',
            'contact_numbers.*' => 'nullable|string|max:50',
            'aadhars' => 'nullable|array',
            'aadhars.*' => 'nullable|string|max:20',
            'pans' => 'nullable|array',
            'pans.*' => 'nullable|string|max:20',
            'aadhar_files' => 'nullable|array',
            'aadhar_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
            'pan_files' => 'nullable|array',
            'pan_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
            // Bank Details
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'ifsc_code' => 'nullable|string|max:20',
            'branch_name' => 'nullable|string|max:255',
            // Qualifications
            'degrees' => 'nullable|array',
            'degrees.*' => 'nullable|string|max:255',
            'institutions' => 'nullable|array',
            'institutions.*' => 'nullable|string|max:255',
            'year_of_passings' => 'nullable|array',
            'year_of_passings.*' => 'nullable|integer|min:1900|max:' . (date('Y') + 10),
            'grades' => 'nullable|array',
            'grades.*' => 'nullable|string|max:50',
            // Experience
            'company_names' => 'nullable|array',
            'company_names.*' => 'nullable|string|max:255',
            'positions' => 'nullable|array',
            'positions.*' => 'nullable|string|max:255',
            'start_dates' => 'nullable|array',
            'start_dates.*' => 'nullable|date',
            'end_dates' => 'nullable|array',
            'end_dates.*' => 'nullable|date',
            // Addresses
            'address_types' => 'nullable|array',
            'address_types.*' => 'nullable|in:permanent,current,office',
            'street_addresses' => 'nullable|array',
            'street_addresses.*' => 'nullable|string|max:255',
            'cities' => 'nullable|array',
            'cities.*' => 'nullable|string|max:100',
            'states' => 'nullable|array',
            'states.*' => 'nullable|string|max:100',
            'postal_codes' => 'nullable|array',
            'postal_codes.*' => 'nullable|string|max:20',
            'countries' => 'nullable|array',
            'countries.*' => 'nullable|string|max:100',
            // Payroll
            'basic_salary' => 'nullable|string|max:255',
            'hra' => 'nullable|string|max:255',
            'conveyance' => 'nullable|string|max:255',
            'medical' => 'nullable|string|max:255',
            // Documents
            'document_types' => 'nullable|array',
            'document_types.*' => 'nullable|string|max:100',
            'document_files' => 'nullable|array',
            'document_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:2048',
            ]);

        // Handle profile image
        if ($request->hasFile('profile_image')) {
            $validated['profile_image'] = $request->file('profile_image')->store('profile_images', 'public');
        }

        // Hash password
        $validated['password'] = Hash::make($validated['password']);

        // Create employee
        $employee = Employee::create($validated);

        $this->logActivity('create', 'Employee', $employee->id, 'Employee created successfully');

        // Create related records
        $this->createRelatedRecords($employee, $request);

        return redirect()->route('employees.index')->with('success', 'Employee created successfully.');
    }

    /**
     * Display the specified employee details.
     */
    public function show(Employee $employee)
    {
        return view('employee.show', compact('employee'));
    }

    /**
     * Display the authenticated employee's profile.
     */
    public function profile()
    {
        $employee = auth('employee')->user();
        return view('employee.profile', compact('employee'));
    }

    /**
     * Show the form for editing the specified employee.
     */
    public function edit(Employee $employee)
    {
        $employee->load(['familyDetails', 'qualifications', 'experiences', 'addresses', 'documents']);
        return view('employee.edit', compact('employee'));
    }

    /**
     * Update the specified employee in storage.
     */
    public function update(Request $request, Employee $employee)
    {
        try {
            $validated = $request->validate([
                'employee_code' => ['required', 'string', 'max:50', Rule::unique('employees')->ignore($employee->id)],
                'name' => 'required|string|max:255',
                'email' => ['required', 'email', 'max:255', Rule::unique('employees')->ignore($employee->id)],
                'password' => 'nullable|string|min:6|confirmed',
                'phone' => 'nullable|string|max:50',
                'dob' => 'nullable|date',
                'hire_date' => 'required|date',
                'position' => 'nullable|string|max:100',
                'department' => 'nullable|string|max:100',
                'status' => ['required', Rule::in(['active', 'inactive', 'terminated', 'resigned'])],
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
                // Family Details
                'relations' => 'nullable|array',
                'relations.*' => 'nullable|in:father,mother,spouse,son,daughter,brother,sister,emergency',
                'family_names' => 'nullable|array',
                'family_names.*' => 'nullable|string|max:255',
                'contact_numbers' => 'nullable|array',
                'contact_numbers.*' => 'nullable|string|max:50',
                'aadhars' => 'nullable|array',
                'aadhars.*' => 'nullable|string|max:20',
                'pans' => 'nullable|array',
                'pans.*' => 'nullable|string|max:20',
                'aadhar_files' => 'nullable|array',
                'aadhar_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'pan_files' => 'nullable|array',
                'pan_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                // Bank Details
                'bank_name' => 'nullable|string|max:255',
                'account_number' => 'nullable|string|max:50',
                'ifsc_code' => 'nullable|string|max:20',
                'branch_name' => 'nullable|string|max:255',
                // Qualifications
                'degrees' => 'nullable|array',
                'degrees.*' => 'nullable|string|max:255',
                'institutions' => 'nullable|array',
                'institutions.*' => 'nullable|string|max:255',
                'year_of_passings' => 'nullable|array',
                'year_of_passings.*' => 'nullable|integer|min:1900|max:' . (date('Y') + 10),
                'grades' => 'nullable|array',
                'grades.*' => 'nullable|string|max:50',
                // Experience
                'company_names' => 'nullable|array',
                'company_names.*' => 'nullable|string|max:255',
                'positions' => 'nullable|array',
                'positions.*' => 'nullable|string|max:255',
                'start_dates' => 'nullable|array',
                'start_dates.*' => 'nullable|date',
                'end_dates' => 'nullable|array',
                'end_dates.*' => 'nullable|date',
                // Addresses
                'address_types' => 'nullable|array',
                'address_types.*' => 'nullable|in:permanent,current,office',
                'street_addresses' => 'nullable|array',
                'street_addresses.*' => 'nullable|string|max:255',
                'cities' => 'nullable|array',
                'cities.*' => 'nullable|string|max:100',
                'states' => 'nullable|array',
                'states.*' => 'nullable|string|max:100',
                'postal_codes' => 'nullable|array',
                'postal_codes.*' => 'nullable|string|max:20',
                'countries' => 'nullable|array',
                'countries.*' => 'nullable|string|max:100',
            // Payroll
            'basic_salary' => 'nullable|string|max:255',
            'hra' => 'nullable|string|max:255',
            'conveyance' => 'nullable|string|max:255',
            'medical' => 'nullable|string|max:255',
                // Documents
                'document_types' => 'nullable|array',
                'document_types.*' => 'nullable|string|max:100',
                'document_files' => 'nullable|array',
                'document_files.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:2048',
            ]);

            // Handle profile image
            if ($request->hasFile('profile_image')) {
                if ($employee->profile_image) {
                    Storage::disk('public')->delete($employee->profile_image);
                }
                $validated['profile_image'] = $request->file('profile_image')->store('profile_images', 'public');
            }

            // Handle password
            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            // Update employee
            $employee->update($validated);

            $this->logActivity('update', 'Employee', $employee->id, 'Employee updated successfully');

            // Update related records
            $this->updateRelatedRecords($employee, $request);

            return redirect()->route('employees.index')->with('success', 'Employee updated successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'An error occurred while updating the employee: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Update related records for the employee.
     */
/**
 * Update related records for the employee.
 */
private function updateRelatedRecords(Employee $employee, Request $request)
{
    // ----------------- Family Details -----------------
    $existingIds = $employee->familyDetails->pluck('id')->toArray();
    $updatedIds = [];

    if ($request->relations && is_array($request->relations)) {
        foreach ($request->relations as $index => $relation) {
            if (!empty($relation)) {
                $familyData = [
                    'employee_id' => $employee->id,
                    'relation' => $relation,
                    'name' => $request->family_names[$index] ?? null,
                    'contact_number' => $request->contact_numbers[$index] ?? null,
                    'aadhar' => $request->aadhars[$index] ?? null,
                    'pan' => $request->pans[$index] ?? null,
                ];

                if (isset($existingIds[$index])) {
                    $existingFamily = $employee->familyDetails->find($existingIds[$index]);
                    if ($existingFamily) {
                        // Preserve old files
                        $familyData['aadhar_file'] = $existingFamily->aadhar_file;
                        $familyData['pan_file'] = $existingFamily->pan_file;

                        // Upload new files if provided
                        if ($request->hasFile("aadhar_files.{$index}")) {
                            $familyData['aadhar_file'] = $request->file("aadhar_files.{$index}")->store('documents', 'public');
                        }
                        if ($request->hasFile("pan_files.{$index}")) {
                            $familyData['pan_file'] = $request->file("pan_files.{$index}")->store('documents', 'public');
                        }

                        $existingFamily->update($familyData);
                        $updatedIds[] = $existingFamily->id;
                    }
                } else {
                    // New family member
                    if ($request->hasFile("aadhar_files.{$index}")) {
                        $familyData['aadhar_file'] = $request->file("aadhar_files.{$index}")->store('documents', 'public');
                    }
                    if ($request->hasFile("pan_files.{$index}")) {
                        $familyData['pan_file'] = $request->file("pan_files.{$index}")->store('documents', 'public');
                    }

                    $newFamily = $employee->familyDetails()->create($familyData);
                    $updatedIds[] = $newFamily->id;
                }
            }
        }
    }

    // Delete removed family members
    $employee->familyDetails()->whereNotIn('id', $updatedIds)->delete();

    // ----------------- Qualifications -----------------
    $employee->qualifications()->delete();
    if ($request->degrees && is_array($request->degrees)) {
        foreach ($request->degrees as $index => $degree) {
            if (!empty($degree)) {
                $employee->qualifications()->create([
                    'employee_id' => $employee->id,
                    'degree' => $degree,
                    'institution' => $request->institutions[$index] ?? null,
                    'year_of_passing' => isset($request->year_of_passings[$index]) ? (int)$request->year_of_passings[$index] : null,
                    'grade' => $request->grades[$index] ?? null,
                ]);
            }
        }
    }

    // ----------------- Experience -----------------
    $employee->experiences()->delete();
    if ($request->company_names && is_array($request->company_names)) {
        foreach ($request->company_names as $index => $company) {
            if (!empty($company)) {
                $employee->experiences()->create([
                    'employee_id' => $employee->id,
                    'company_name' => $company,
                    'position' => $request->positions[$index] ?? null,
                    'start_date' => $request->start_dates[$index] ?? null,
                    'end_date' => $request->end_dates[$index] ?? null,
                ]);
            }
        }
    }

    // ----------------- Addresses -----------------
    $employee->addresses()->delete();
    if ($request->address_types && is_array($request->address_types)) {
        foreach ($request->address_types as $index => $type) {
            if (!empty($type)) {
                $employee->addresses()->create([
                    'employee_id' => $employee->id,
                    'address_type' => $type,
                    'street_address' => $request->street_addresses[$index] ?? null,
                    'city' => $request->cities[$index] ?? null,
                    'state' => $request->states[$index] ?? null,
                    'postal_code' => $request->postal_codes[$index] ?? null,
                    'country' => $request->countries[$index] ?? null,
                ]);
            }
        }
    }

    // ----------------- Documents -----------------
    if ($request->document_types && is_array($request->document_types)) {
        foreach ($request->document_types as $index => $type) {
            if (!empty($type)) {
                $existingDocument = $employee->documents[$index] ?? null;

                // Determine file path
                if ($request->hasFile("document_files.{$index}") && $request->file("document_files.{$index}")->isValid()) {
                    $filePath = $request->file("document_files.{$index}")->store('documents', 'public');
                } else {
                    // Preserve old file from hidden input or existing document
                    $filePath = $request->old_document_files[$index] ?? ($existingDocument->file_path ?? null);
                }

                if ($existingDocument) {
                    $existingDocument->update([
                        'document_type' => $type,
                        'file_path' => $filePath,
                    ]);
                } else {
                    $employee->documents()->create([
                        'document_type' => $type,
                        'file_path' => $filePath,
                    ]);
                }
            }
        }
    }
}


    /**
     * Create related records for the employee.
     */
    private function createRelatedRecords(Employee $employee, Request $request)
    {
        // Family Details
        if ($request->relations && is_array($request->relations)) {
            foreach ($request->relations as $index => $relation) {
                if (!empty($relation)) {
                    $familyData = [
                        'employee_id' => $employee->id,
                        'relation' => $relation,
                        'name' => $request->family_names[$index] ?? null,
                        'contact_number' => $request->contact_numbers[$index] ?? null,
                        'aadhar' => $request->aadhars[$index] ?? null,
                        'pan' => $request->pans[$index] ?? null,
                    ];

                    if ($request->hasFile("aadhar_files.{$index}")) {
                        $familyData['aadhar_file'] = $request->file("aadhar_files.{$index}")->store('documents', 'public');
                    }
                    if ($request->hasFile("pan_files.{$index}")) {
                        $familyData['pan_file'] = $request->file("pan_files.{$index}")->store('documents', 'public');
                    }

                    $employee->familyDetails()->create($familyData);
                }
            }
        }

        // Bank Details (stored in employees table, no separate BankDetail model)
        // Already handled in $employee->create()

        // Qualifications
        if ($request->degrees && is_array($request->degrees)) {
            foreach ($request->degrees as $index => $degree) {
                if (!empty($degree)) {
                    $employee->qualifications()->create([
                        'employee_id' => $employee->id,
                        'degree' => $degree,
                        'institution' => $request->institutions[$index] ?? null,
                        'year_of_passing' => isset($request->year_of_passings[$index]) ? (int)$request->year_of_passings[$index] : null,
                        'grade' => $request->grades[$index] ?? null,
                    ]);
                }
            }
        }

        // Experience
        if ($request->company_names && is_array($request->company_names)) {
            foreach ($request->company_names as $index => $company) {
                if (!empty($company)) {
                    $employee->experiences()->create([
                        'employee_id' => $employee->id,
                        'company_name' => $company,
                        'position' => $request->positions[$index] ?? null,
                        'start_date' => $request->start_dates[$index] ?? null,
                        'end_date' => $request->end_dates[$index] ?? null,
                    ]);
                }
            }
        }

        // Addresses
        if ($request->address_types && is_array($request->address_types)) {
            foreach ($request->address_types as $index => $type) {
                if (!empty($type)) {
                    $employee->addresses()->create([
                        'employee_id' => $employee->id,
                        'address_type' => $type,
                        'street_address' => $request->street_addresses[$index] ?? null,
                        'city' => $request->cities[$index] ?? null,
                        'state' => $request->states[$index] ?? null,
                        'postal_code' => $request->postal_codes[$index] ?? null,
                        'country' => $request->countries[$index] ?? null,
                    ]);
                }
            }
        }

        // Payroll (stored in employees table, no separate Payroll model)
        // Already handled in $employee->create()

        // Documents
        if ($request->document_types && is_array($request->document_types)) {
            foreach ($request->document_types as $index => $type) {
                if (!empty($type) && $request->hasFile("document_files.{$index}")) {
                    $path = $request->file("document_files.{$index}")->store('documents', 'public');
                    $employee->documents()->create([
                        'employee_id' => $employee->id,
                        'document_type' => $type,
                        'file_path' => $path,
                    ]);
                }
            }
        }
    }

    /**
     * Terminate the specified employee.
     */
    public function terminate(Employee $employee)
    {
        $employee->update(['status' => 'terminated']);

        $this->logActivity('terminate', 'Employee', $employee->id, 'Employee terminated successfully');

        return redirect()->route('employees.index')->with('success', 'Employee terminated successfully.');
    }

    /**
     * Mark the specified employee as resigned.
     */
    public function resign(Employee $employee)
    {
        $employee->update(['status' => 'resigned']);

        $this->logActivity('resign', 'Employee', $employee->id, 'Employee resigned successfully');

        return redirect()->route('employees.index')->with('success', 'Employee resigned successfully.');
    }

    /**
     * Remove the specified employee from storage.
     */
    public function destroy(Employee $employee)
    {
        $this->logActivity('delete', 'Employee', $employee->id, 'Employee deleted successfully');

        $employee->delete();
        return redirect()->route('employees.index')->with('success', 'Employee deleted successfully.');
    }

    /**
     * Show the employee login form.
     */
    public function showLoginForm()
    {
        // Get dynamic logo and company name for login page
        $logo = '';
        $company_name = 'Bitmax Group'; // Default
        $admin = \App\Models\Admin::where('role', 'super_admin')->first() ?? \App\Models\Admin::first();
        if ($admin) {
            if ($admin->company_logo && \Illuminate\Support\Facades\Storage::disk('public')->exists('company_logos/' . $admin->company_logo)) {
                $logo = asset('storage/company_logos/' . $admin->company_logo);
            } else {
                // Use static logo
                $logo = asset('images/logo.png');
            }
            $company_name = $admin->company_name ?? 'Bitmax Group';
        }

        return view('employee.login', compact('logo', 'company_name'));
    }

    /**
     * Handle employee login.
     */
    public function login(Request $request)
    {
        $request->validate([
            'employee_code' => 'required|string',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('employee_code', 'password');

        if (Auth::guard('employee')->attempt($credentials)) {
            $employee = Auth::guard('employee')->user();

            // Check if employee status is active
            if ($employee->status !== 'active') {
                Auth::guard('employee')->logout();
                return back()->withErrors([
                    'employee_code' => 'Your account is not active. Please contact administrator.',
                ]);
            }

            return redirect()->intended(route('employee.dashboard'));
        }

        return back()->withErrors([
            'employee_code' => 'The provided credentials do not match our records.',
        ]);
    }

    /**
     * Handle employee logout.
     */
    public function logout(Request $request)
    {
        Auth::guard('employee')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('employee.login');
    }

    /**
     * Show the employee dashboard.
     */
    public function dashboard()
    {
        $employee = Auth::guard('employee')->user();

        // Get current month attendance summary
        $currentMonth = Carbon::now('Asia/Kolkata');
        $year = $currentMonth->year;
        $month = $currentMonth->month;

        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $attendanceSummary = [
            'total_days' => $attendances->count(),
            'present' => $attendances->where('status', 'Present')->count(),
            'absent' => $attendances->where('status', 'Absent')->count(),
            'leave' => $attendances->where('status', 'Leave')->count(),
            'half_day' => $attendances->where('status', 'Half Day')->count(),
            'holiday' => $attendances->where('status', 'Holiday')->count(),
        ];

        return view('employee.dashboard', compact('employee', 'attendanceSummary'));
    }

    /**
     * Update the authenticated employee's profile.
     */
    public function updateProfile(Request $request)
    {
        $employee = Auth::guard('employee')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'nullable|string|min:6|confirmed',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($request->hasFile('profile_image')) {
            if ($employee->profile_image) {
                Storage::disk('public')->delete($employee->profile_image);
            }
            $validated['profile_image'] = $request->file('profile_image')->store('profile_images', 'public');
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $employee->update($validated);

        $this->logActivity('update profile', 'Employee', $employee->id, 'Employee profile updated successfully');

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Display tasks assigned to the authenticated employee.
     */
    public function tasks()
    {
        $employee = Auth::guard('employee')->user();

        $tasks = \App\Models\Task::where(function($query) use ($employee) {
            $query->where('assigned_to', $employee->id)
                  ->orWhereJsonContains('team_members', (string)$employee->id)
                  ->orWhere('team_lead_id', $employee->id);
        })
        ->with(['assignedEmployee', 'teamLead'])
        ->orderBy('created_at', 'desc')
        ->paginate(10);

        return view('employee.tasks', compact('tasks'));
    }

    /**
     * Display the specified task for the employee.
     */
    public function showTask(Task $task)
    {
        $employee = Auth::guard('employee')->user();

        if ($task->assigned_to !== $employee->id && !in_array($employee->id, $task->team_members ?? []) && $task->team_lead_id !== $employee->id) {
            abort(403, 'You are not authorized to view this task.');
        }

        $task->load(['assignedEmployee', 'teamLead']);

        return view('employee.task-detail', compact('task'));
    }

    /**
     * Show the form for editing the specified task for the authenticated employee.
     */
    public function editTask(\App\Models\Task $task)
    {
        $employee = Auth::guard('employee')->user();

        // Only team leads can edit team tasks
        if ($task->team_lead_id !== $employee->id) {
            abort(403, 'You are not authorized to edit this task.');
        }

        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->get();

        return view('employee.task-edit', compact('task', 'employees'));
    }

    /**
     * Update the specified task for the authenticated employee.
     */
    public function updateTask(Request $request, \App\Models\Task $task)
    {
        $employee = Auth::guard('employee')->user();

        // Only team leads can edit tasks
        if ($task->team_lead_id !== $employee->id) {
            abort(403, 'You are not authorized to edit this task.');
        }

        $validated = $request->validate([
            'task_name' => 'required|string|max:255',
            'description' => 'required|string',
            'assigned_team' => ['required', Rule::in(['Individual', 'Team'])],
            'assigned_to' => 'nullable|exists:employees,id',
            'team_lead_id' => 'nullable|exists:employees,id',
            'team_members' => 'nullable|array',
            'team_members.*' => 'exists:employees,id',
            'team_created_by' => ['nullable', Rule::in(['admin', 'team_lead'])],
            'selected_team' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => ['required', Rule::in(['Not Started', 'In Progress', 'Completed', 'On Hold'])],
            'priority' => ['required', Rule::in(['Low', 'Medium', 'High'])],
            'progress' => 'nullable|numeric|min:0|max:100',
        ]);

        $task->update($validated);

        $this->logActivity('update task', 'Task', $task->id, 'Task updated successfully');

        return redirect()->route('employee.tasks.show', $task)->with('success', 'Task updated successfully.');
    }

    /**
     * Update task progress for the authenticated employee.
     */
    public function updateTaskProgress(Request $request, \App\Models\Task $task)
    {
        $employee = Auth::guard('employee')->user();

        if (($task->assigned_team == 'Individual' && $task->assigned_to !== $employee->id) || ($task->assigned_team == 'Team' && $task->team_lead_id !== $employee->id)) {
            abort(403, 'You are not authorized to update this task.');
        }

        $validated = $request->validate([
            'progress' => 'required|numeric|min:0|max:100',
            'status' => ['nullable', Rule::in(['Not Started', 'In Progress', 'Completed', 'On Hold'])],
        ]);

        $task->update($validated);

        $this->logActivity('update task progress', 'Task', $task->id, 'Task progress updated successfully');

        return back()->with('success', 'Task progress updated successfully.');
    }

    /**
     * Display reports for the authenticated employee.
     */
    public function reports()
    {
        $employee = Auth::guard('employee')->user();
        $reports = Report::where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('employee.reports', compact('reports'));
    }

    /**
     * Display the daily report template for the authenticated employee.
     */
    public function dailyReport()
    {
        $employee = Auth::guard('employee')->user();
        return view('employee.daily-report', compact('employee'));
    }

    /**
     * Show the form for creating a new report.
     */
    public function createReport()
    {
        $employee = Auth::guard('employee')->user();

        $teamTask = \App\Models\Task::whereJsonContains('team_members', (string)$employee->id)->first();
        $hasTeamLead = !is_null($teamTask);
        $teamLead = $hasTeamLead ? $teamTask->teamLead : null;

        // Fetch tasks assigned to employee, where employee is team member, or where employee is team lead
        $tasks = \App\Models\Task::where(function($query) use ($employee) {
            $query->where('assigned_to', $employee->id)
                  ->orWhereJsonContains('team_members', (string)$employee->id)
                  ->orWhere('team_lead_id', $employee->id);
        })->orderBy('created_at', 'desc')->get();

        return view('employee.create-report', compact('hasTeamLead', 'teamLead', 'tasks'));
    }

    /**
     * Store a newly created report in storage.
     */
    public function storeReport(Request $request)
    {
        $employee = Auth::guard('employee')->user();

        $validated = $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,txt,jpg,jpeg,png|max:2048',
            'send_to_team_lead' => 'nullable|boolean',
        ]);

        $teamTask = \App\Models\Task::whereJsonContains('team_members', (int)$employee->id)->first();
        $isInTeam = !is_null($teamTask);

        $reportData = [
            'employee_id' => $employee->id,
            'task_id' => $validated['task_id'],
            'title' => $validated['title'],
            'content' => $validated['content'],
            'sent_to_admin' => true,
            'sent_to_team_lead' => $isInTeam && ($request->send_to_team_lead ?? false),
            'team_lead_id' => $isInTeam ? $teamTask->team_lead_id : null,
            'status' => 'sent',
        ];

        if ($request->hasFile('attachment')) {
            $reportData['attachment'] = $request->file('attachment')->store('reports', 'public');
        }

        $report = Report::create($reportData);

        $this->logActivity('create report', 'Report', $report->id, 'Report sent successfully');

        return redirect()->route('employee.reports')->with('success', 'Report sent successfully.');
    }

    /**
     * Display the specified report.
     */
    public function showReport(Report $report)
    {
        $employee = Auth::guard('employee')->user();

        if ($report->employee_id !== $employee->id) {
            abort(403, 'You are not authorized to view this report.');
        }

        return response()->json($report);
    }

    /**
     * Display team management page for team leaders.
     */
    public function teamManagement()
    {
        $employee = Auth::guard('employee')->user();

        // Check if employee is a team leader
        $teamTasks = Task::where('team_lead_id', $employee->id)->get();

        if ($teamTasks->isEmpty()) {
            abort(403, 'You are not authorized to access team management.');
        }

        // Get reports sent to this team leader
        $reports = Report::where('team_lead_id', $employee->id)
            ->where('sent_to_team_lead', true)
            ->with(['employee', 'task'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('employee.team-management', compact('teamTasks', 'reports'));
    }

    /**
     * Display reports from team members for team leaders.
     */
    public function teamReports()
    {
        $employee = Auth::guard('employee')->user();

        // Check if employee is a team leader
        $teamTasks = Task::where('team_lead_id', $employee->id)->get();

        if ($teamTasks->isEmpty()) {
            abort(403, 'You are not authorized to access team reports.');
        }

        // Get reports sent to this team leader
        $reports = Report::where('team_lead_id', $employee->id)
            ->where('sent_to_team_lead', true)
            ->with(['employee', 'task'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // No need to add team_lead_status manually as it exists in the model fillable

        return view('employee.team-reports', compact('reports'));
    }

    /**
     * Show and review a specific report from team member.
     */
    public function showTeamReport(Report $report)
    {
        $employee = Auth::guard('employee')->user();

        // Check if this report was sent to this team leader
        if ($report->team_lead_id !== $employee->id || !$report->sent_to_team_lead) {
            abort(403, 'You are not authorized to view this report.');
        }

        $report->load(['employee', 'task']);

        return view('employee.team-report-detail', compact('report'));
    }

    /**
     * Update review for a team member's report.
     */
    public function updateTeamReport(Request $request, Report $report)
    {
        $employee = Auth::guard('employee')->user();

        // Check if this report was sent to this team leader
        if ($report->team_lead_id !== $employee->id || !$report->sent_to_team_lead) {
            abort(403, 'You are not authorized to review this report.');
        }

        $validated = $request->validate([
            'team_lead_status' => ['required', Rule::in(['sent', 'read', 'responded'])],
            'team_lead_review' => 'nullable|string|max:1000',
            'team_lead_rating' => 'nullable|integer|min:1|max:5',
        ]);

        $report->update([
            'team_lead_status' => $validated['team_lead_status'],
            'team_lead_review' => $validated['team_lead_review'],
            'team_lead_rating' => $validated['team_lead_rating'],
        ]);

        $this->logActivity('update team report', 'Report', $report->id, 'Team lead review updated successfully');

        return redirect()->route('employee.team-reports.show', $report->id)->with('success', 'Team lead review updated successfully.');
    }

    /**
     * Display activities index for the authenticated employee.
     */
    public function activitiesIndex()
    {
        $employee = Auth::guard('employee')->user();

        $activities = \App\Models\Activity::where(function($query) use ($employee) {
            $query->whereHas('employees', function($subQuery) use ($employee) {
                $subQuery->where('employee_id', $employee->id);
            })->orWhere('scoring_scope', "all");
        })->with('employees')->paginate(10);

        // Check which activities the employee has already submitted
        $submittedActivities = \App\Models\Point::where('from_employee_id', $employee->id)
            ->pluck('activity_id')
            ->unique()
            ->toArray();

        return view('employee.activities-index', compact('activities', 'submittedActivities'));
    }

    /**
     * Start an activity for the authenticated employee.
     */
    public function startActivity(\App\Models\Activity $activity)
    {
        $employee = Auth::guard('employee')->user();

        // Check if activity is pending
        if ($activity->status === 'pending') {
            // Redirect back to activities index with error message
            return redirect()->route('employee.activities.index')->with('error', 'This activity is pending and cannot be started yet.');
        }

        // Determine if activity is assigned to all employees or selected employees
        if ($activity->scoring_scope === 'all') {
            // Load all employees for scoring
            $employeesForScoring = \App\Models\Employee::all();
        } else {
            // Load only assigned employees for scoring
            $employeesForScoring = $activity->employees()->get();
        }

        // Exclude the current logged-in employee from scoring list
        $employeesForScoring = $employeesForScoring->filter(function ($emp) use ($employee) {
            return $emp->id !== $employee->id;
        });

        // Check if employee has already submitted
        $hasSubmitted = \App\Models\Point::where('activity_id', $activity->id)
            ->where('from_employee_id', $employee->id)
            ->exists();

        $submittedPoints = [];
        if ($hasSubmitted) {
            // Load submitted points
            $submittedPoints = \App\Models\Point::where('activity_id', $activity->id)
                ->where('from_employee_id', $employee->id)
                ->with(['toEmployee', 'criteria'])
                ->get()
                ->groupBy(['to_employee_id', 'criteria_id'])
                ->map(function ($criteriaPoints) {
                    return $criteriaPoints->map(function ($point) {
                        return $point->first()->points ?? 0;
                    });
                });
        }

        // Pass filtered employees, submission status, and submitted points to the view
        return view('employee.activity-form', [
            'activity' => $activity,
            'employeesForScoring' => $employeesForScoring,
            'isPending' => false,
            'hasSubmitted' => $hasSubmitted,
            'submittedPoints' => $submittedPoints,
        ]);
    }

    public function submitActivity(\Illuminate\Http\Request $request, \App\Models\Activity $activity)
    {
        $employee = Auth::guard('employee')->user();

        // Check if employee is assigned to this activity
        if (!$activity->employees->contains($employee->id)) {
            abort(403, 'You are not authorized to submit this activity.');
        }

        $validated = $request->validate([
            'scores' => 'required|array',
            'scores.*.*' => 'required|numeric|min:0',
            'best_employee_id' => 'nullable|exists:employees,id',
            'best_employee_description' => 'nullable|string|max:1000',
        ]);

        // Save points for each participant and criterion
        foreach ($validated['scores'] as $toEmployeeId => $criteriaScores) {
            foreach ($criteriaScores as $criterionId => $points) {
                \App\Models\Point::updateOrCreate(
                    [
                        'activity_id' => $activity->id,
                        'from_employee_id' => $employee->id,
                        'to_employee_id' => $toEmployeeId,
                        'criteria_id' => $criterionId,
                    ],
                    [
                        'points' => $points,
                    ]
                );
            }
        }

        // Save best employee selection if enabled
        if ($activity->enable_best_employee) {
            $activity->best_employee_id = $validated['best_employee_id'] ?? null;
            $activity->best_employee_description = $validated['best_employee_description'] ?? null;
            $activity->save();
        }

        // Optionally update activity status or other logic here

        return redirect()->route('employee.activities.index')->with('success', 'Activity evaluation submitted successfully.');
    }

    /**
     * Display the employee card.
     */
    public function card(Employee $employee)
    {
        return view('admin.employees.card', compact('employee'));
    }

    /**
     * Show attendance selection form for the authenticated employee.
     */
    public function attendance()
    {
        $employee = Auth::guard('employee')->user();
        return view('employee.attendance', compact('employee'));
    }

    /**
     * Show attendance data for the authenticated employee for selected month.
     */
    public function showAttendance(Request $request)
    {
        $employee = Auth::guard('employee')->user();

        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $month = $request->month;

        // Parse month and year
        $date = Carbon::createFromFormat('Y-m', $month, 'Asia/Kolkata');
        $year = $date->year;
        $monthNum = $date->month;

        // Get attendance data for the selected month and employee
        $attendances = Attendance::where('employee_id', $employee->id)
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
        ];

        // Get all days in the month for calendar view
        $daysInMonth = $date->daysInMonth;
        $monthlyData = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Asia/Kolkata');
            // Use closure for accurate date comparison
            $attendance = $attendances->first(function ($att) use ($currentDate) {
                return $att->date->format('Y-m-d') === $currentDate->format('Y-m-d');
            });

            $monthlyData[] = [
                'date' => $currentDate->format('Y-m-d'),
                'day' => $day,
                'day_name' => $currentDate->format('D'),
                'status' => $attendance ? $attendance->status : 'Not Marked',
                'marked_at' => $attendance ? $attendance->updated_at->setTimezone('Asia/Kolkata')->format('H:i') : null,
                'remarks' => $attendance ? $attendance->remarks : null,
                'mark_in' => $attendance ? $attendance->mark_in : null,
                'mark_out' => $attendance ? $attendance->mark_out : null,
                'break_time' => $attendance ? $attendance->break_time : null,
                'twh' => $attendance && $attendance->mark_in && $attendance->mark_out ? $this->calculateTWH($attendance->mark_in, $attendance->mark_out, $attendance->break_time) : null,
            ];
        }

        return view('employee.attendance', compact('employee', 'month', 'summary', 'monthlyData', 'attendances'));
    }

    /**
     * Calculate Total Working Hours (TWH)
     */
    private function calculateTWH($markIn, $markOut, $breakTime = null, $date = null)
    {
        try {
            // Use current date if not provided
            $attendanceDate = $date ? Carbon::parse($date) : Carbon::now('Asia/Kolkata');
            $dateStr = $attendanceDate->format('Y-m-d');

            // Parse times more safely - handle potential microseconds or extra data
            $markInTime = substr($markIn, 0, 8); // Take only HH:MM:SS
            $markOutTime = substr($markOut, 0, 8); // Take only HH:MM:SS

            $markInCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $markInTime, 'Asia/Kolkata');

            // Handle case where mark_out might be next day (if time is less than mark_in)
            $markOutDate = $dateStr;
            if ($markOutTime < $markInTime) {
                $markOutDate = Carbon::createFromFormat('Y-m-d', $dateStr, 'Asia/Kolkata')->addDay()->format('Y-m-d');
            }
            $markOutCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $markOutDate . ' ' . $markOutTime, 'Asia/Kolkata');

            // Calculate total seconds manually to avoid timezone issues
            $markInSeconds = ($markInCarbon->hour * 3600) + ($markInCarbon->minute * 60) + $markInCarbon->second;
            $markOutSeconds = ($markOutCarbon->hour * 3600) + ($markOutCarbon->minute * 60) + $markOutCarbon->second;
            $totalSeconds = $markOutSeconds - $markInSeconds;

            // Subtract break time - break_time is always duration in HH:MM:SS format
            if ($breakTime && $breakTime !== '00:00:00') {
                $breakTimeClean = substr($breakTime, 0, 8); // Take only HH:MM:SS
                $breakParts = explode(':', $breakTimeClean);
                if (count($breakParts) === 3) {
                    $breakSeconds = ($breakParts[0] * 3600) + ($breakParts[1] * 60) + $breakParts[2];
                    $totalSeconds -= $breakSeconds;
                }
            }

            // Convert to hours and format as HH:MM:SS
            $workingSeconds = max(0, $totalSeconds);
            $hours = floor($workingSeconds / 3600);
            $minutes = floor(($workingSeconds % 3600) / 60);
            $seconds = $workingSeconds % 60;

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } catch (\Exception $e) {
            // Return null if calculation fails
            return null;
        }
    }

     public function storeReportStatus(Request $request)
    {
        $request->validate([
            'is_submitted' => 'required|boolean',
        ]);

        ReportSubmission::updateOrCreate(
            [
                'employee_id' => Auth::guard('employee')->id(),
                'report_date' => now()->toDateString(),
            ],
            [
                'is_submitted' => $request->is_submitted,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Report status saved successfully'
        ]);
    }
}
