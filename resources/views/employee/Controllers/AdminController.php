<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Admin;
use App\Models\EvaluationReport;
use App\Models\EvaluationManager;
use App\Models\EvaluationHr;
use App\Models\EvaluationOverall;
use App\Models\EvaluationAssignment;
use App\Traits\Loggable;

class AdminController extends Controller
{
    use Loggable;
    

    public function showLoginForm()
    {
        // Check if admin is already logged in
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        // Get dynamic logo and company name for login page
        $logo = '';
        $company_name = 'Bitmax Group'; // Default
        $admin = Admin::where('role', 'super_admin')->first() ?? Admin::first();
        if ($admin) {
            if ($admin->company_logo && Storage::disk('public')->exists('company_logos/' . $admin->company_logo)) {
                $logo = asset('storage/company_logos/' . $admin->company_logo);
            } else {
                // Use static logo
                $logo = asset('images/logo.png');
            }
            $company_name = $admin->company_name ?? 'Bitmax Group';
        }

        return view('admin.login', compact('logo', 'company_name'));
    }

public function saveEvaluationPdf($id)
{
    $report = EvaluationReport::with([
        'employee',
        'evaluationManager',
        'evaluationHr',
        'evaluationOverall'
    ])->findOrFail($id);

    $employee = $report->employee;

    // ✅ Logged-in admin (who generated PDF)
    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    // ✅ Generate PDF
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
        'admin.evaluation-report-pdf',
        compact('report')
    );

    // ✅ Folder structure
    $folder = 'employee_documents/' . $employee->id;
    Storage::disk('public')->makeDirectory($folder);

    // ✅ File name
    $filename = 'evaluation-report-' . time() . '.pdf';

    // ✅ Save PDF
    Storage::disk('public')->put($folder . '/' . $filename, $pdf->output());

    // ✅ Save in documents table
    DB::table('documents')->insert([
        'employee_id'   => $employee->id,
        'document_type' => 'evaluation_report',
        'file_path'     => $folder . '/' . $filename,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    /* ==================================================
       🔔 EMPLOYEE NOTIFICATION
    ================================================== */

    \App\Models\Notification::create([
        'admin_id'    => $actor?->id,          // ✅ REQUIRED (sender)
        'employee_id' => $employee->id,       // ✅ receiver
        'title'       => 'Evaluation Report Available',
        'message'     => "Your evaluation report has been uploaded by {$actorName}.",
        'is_read'     => false,
    ]);

    /* ================================================== */

    return response()->json([
        'message' => 'Evaluation Report PDF saved and employee notified successfully!'
    ]);
}



    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (Auth::guard('admin')->attempt($credentials)) {
            $request->session()->regenerate();

            // Log login activity
            $admin = Auth::guard('admin')->user();
            $this->logActivity('login', null, null, "Admin {$admin->name} logged in");

            return redirect()->intended(route('admin.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    public function dashboard()
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to view dashboard
        if (!$admin->hasPermission('Dashboard')) {
            return redirect()->route('admin.login')->with('error', 'You do not have permission to access the dashboard.');
        }

        // Fetch dynamic stats (only show data for modules admin has access to)
        $totalUsers = $admin->hasPermission('employees') ? \App\Models\Employee::count() : 0;
        $activeTasks = $admin->hasPermission('tasks') ? \App\Models\Task::where('status', 'active')->count() : 0;
        $incompleteTasks = $admin->hasPermission('tasks') ? \App\Models\Task::where('status', '!=', 'completed')->count() : 0;
        $pendingReviews = $admin->hasPermission('reports') ? \App\Models\Report::where('admin_status', 'pending')->count() : 0;
        $totalSalaryExpenses = $admin->hasPermission('employees') ? \App\Models\Employee::sum(DB::raw('COALESCE(basic_salary, 0) + COALESCE(hra, 0) + COALESCE(conveyance, 0) + COALESCE(medical, 0)')) : 0;
        $totalOtherExpenses = \App\Models\Expense::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');
        $systemAlerts = 0; // Placeholder for system alerts, can be implemented later if needed

        $tasks = $admin->hasPermission('tasks') ? \App\Models\Task::with(['assignedEmployee', 'teamLead'])->paginate(10) : collect();

        $recentEmployees = $admin->hasPermission('employees') ? \App\Models\Employee::orderBy('created_at', 'desc')->take(5)->get() : collect();

        // Executive performance stats (approved leads and proposals)
        $executiveStats = [];
        if ($admin->hasPermission('leads') || $admin->hasPermission('proposals')) {
            $executives = Admin::where('role', 'sub_admin')->get();

            foreach ($executives as $executive) {
                $approvedLeads = 0;
                $approvedProposals = 0;

                if ($admin->hasPermission('leads')) {
                    $approvedLeads = \App\Models\Lead::where('assigned_to', $executive->id)
                        ->where('status', 'Approved')
                        ->count();
                }

                if ($admin->hasPermission('proposals')) {
                    $approvedProposals = \App\Models\Proposal::where('created_by', $executive->id)
                        ->where('status', 'Approved')
                        ->count();
                }

                if ($approvedLeads > 0 || $approvedProposals > 0) {
                    $executiveStats[] = [
                        'executive' => $executive,
                        'approved_leads' => $approvedLeads,
                        'approved_proposals' => $approvedProposals,
                        'total_approvals' => $approvedLeads + $approvedProposals
                    ];
                }
            }

            // Sort by total approvals descending
            usort($executiveStats, function($a, $b) {
                return $b['total_approvals'] <=> $a['total_approvals'];
            });
        }

        // Recent leave requests
        $recentLeaveRequests = \App\Models\LeaveRequest::with('employee')
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get();

        // Employee report status for current date - show only 3 recent submissions
        $today = now()->toDateString();
        $recentReportSubmissions = \App\Models\ReportSubmission::where('report_date', $today)
            ->with('employee')
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get();

        $reportStatuses = $recentReportSubmissions->keyBy('employee_id');
        $employees = $recentReportSubmissions->map(function($submission) {
            return $submission->employee;
        });

        // Get total count of all employees for the progress calculation
        $totalEmployeesCount = \App\Models\Employee::count();

        // Get employee performance data for chart (last 30 days)
        $performanceData = $this->getDashboardPerformanceData();

        return view('admin.dashboard', compact('admin', 'tasks', 'totalUsers', 'activeTasks', 'incompleteTasks', 'pendingReviews', 'totalSalaryExpenses', 'totalOtherExpenses', 'systemAlerts', 'recentEmployees', 'executiveStats', 'recentLeaveRequests', 'reportStatuses', 'employees', 'today', 'performanceData', 'totalEmployeesCount'));
    }

    public function profile()
    {
        $admin = Auth::guard('admin')->user();
        return view('admin.profile', compact('admin'));
    }

    public function updateProfile(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins')->ignore($admin->id)],
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:500',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif',
            'company_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif',
            'company_name' => 'nullable|string|max:255',
            // 'dark_mode' => 'nullable|boolean',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'bio' => $request->bio,
            'company_name' => $request->company_name,
            // 'dark_mode' => $request->dark_mode ?? false,
        ];

        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($admin->profile_image && Storage::exists('public/profile_images/' . $admin->profile_image)) {
                Storage::delete('public/profile_images/' . $admin->profile_image);
            }

            // Store new image
            $imageName = time() . '.' . $request->profile_image->extension();
            $request->profile_image->storeAs('public/profile_images', $imageName);
            $data['profile_image'] = $imageName;
        }

        if ($request->hasFile('company_logo')) {
            // Delete old company logo if exists
            if ($admin->company_logo && Storage::exists('public/company_logos/' . $admin->company_logo)) {
                Storage::delete('public/company_logos/' . $admin->company_logo);
            }

            // Store new company logo
            $logoName = time() . '.' . $request->company_logo->extension();
            $request->company_logo->storeAs('public/company_logos', $logoName);
            $data['company_logo'] = $logoName;
        }

        $admin->update($data);

        return redirect()->route('admin.profile')->with('success', 'Profile updated successfully!');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $admin = Auth::guard('admin')->user();

        if (!Hash::check($request->current_password, $admin->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $admin->update([
            'password' => Hash::make($request->password),
        ]);

        return redirect()->route('admin.profile')->with('success', 'Password updated successfully!');
    }

    public function logout(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // Log logout activity before logging out
        if ($admin) {
            $this->logActivity('logout', null, null, "Admin {$admin->name} logged out");
        }

        Auth::guard('admin')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect(route('admin.login'));
    }

    // Sub-Admin Management Methods
    public function indexSubAdmins()
    {
        $subAdmins = Admin::where('role', 'sub_admin')->paginate(10);
        return view('admin.sub-admins.index', compact('subAdmins'));
    }

    public function createSubAdmin()
    {
        $modules = [
            'Dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'tasks' => 'Tasks',
            'activities' => 'Activities',
            'Employee Card' => 'Employee Card',
            'Assigned Items' => 'Assigned Items',
            'reports' => 'Reports',
            'hr-mis-reports' => 'HR MIS Reports',
            'attendance' => 'Attendance',
            'leave-requests' => 'Leave Requests',
            'salary-slips' => 'Salary Slips',
            'visitors' => 'Visitors',
            'invited-visitors' => 'Invited Visitors',
            'stock' => 'Stock Management',
            'performance' => 'Performance',
            'evaluation-report' => 'Evaluation Report',
            'interviews' => 'Interviews',
            'expenses' => 'Expenses',
            'form' => 'Form',
            'leads' => 'Leads',
            'interactions' => 'Interactions',
            'proposals' => 'Proposals',
            'executives' => 'Executives',
            'whatsapp' => 'WhatsApp Bot',
            'settings' => 'Settings',
            'logs' => 'Logs',
        ];

        return view('admin.sub-admins.create', compact('modules'));
    }

    public function storeSubAdmin(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admins',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:500',
            'permissions' => 'array',
        ]);

        $permissions = $request->permissions ?? [];

        Admin::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'bio' => $request->bio,
            'role' => 'sub_admin',
            'permissions' => $permissions,
        ]);

        return redirect()->route('admin.sub-admins.index')->with('success', 'Sub-admin created successfully!');
    }

    public function editSubAdmin($id)
    {
        $subAdmin = Admin::findOrFail($id);
        $modules = [
            'Dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'tasks' => 'Tasks',
            'activities' => 'Activities',
            'Employee Card' => 'Employee Card',
            'Assigned Items' => 'Assigned Items',
            'reports' => 'Reports',
            'hr-mis-reports' => 'HR MIS Reports',
            'attendance' => 'Attendance',
            'leave-requests' => 'Leave Requests',
            'salary-slips' => 'Salary Slips',
            'visitors' => 'Visitors',
            'invited-visitors' => 'Invited Visitors',
            'stock' => 'Stock Management',
            'performance' => 'Performance',
            'evaluation-report' => 'Evaluation Report',
            'interviews' => 'Interviews',
            'expenses' => 'Expenses',
            'form' => 'Form',
            'leads' => 'Leads',
            'interactions' => 'Interactions',
            'proposals' => 'Proposals',
            'executives' => 'Executives',
            'whatsapp' => 'WhatsApp Bot',
            'settings' => 'Settings',
            'logs' => 'Logs',
        ];

        return view('admin.sub-admins.edit', compact('subAdmin', 'modules'));
    }

    public function updateSubAdmin(Request $request, $id)
    {
        $subAdmin = \App\Models\Admin::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admins')->ignore($subAdmin->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:500',
            'permissions' => 'array',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'bio' => $request->bio,
            'permissions' => $request->permissions ?? [],
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $subAdmin->update($data);

        return redirect()->route('admin.sub-admins.index')->with('success', 'Sub-admin updated successfully!');
    }

    public function deleteSubAdmin($id)
    {
        $subAdmin = Admin::findOrFail($id);
        $subAdmin->delete();

        return redirect()->route('admin.sub-admins.index')->with('success', 'Sub-admin deleted successfully!');
    }

    public function show($id)
    {
        $subAdmin = Admin::findOrFail($id);
        $modules = [
            'Dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'tasks' => 'Tasks',
            'activities' => 'Activities',
            'Employee Card' => 'Employee Card',
            'Assigned Items' => 'Assigned Items',
            'reports' => 'Reports',
            'attendance' => 'Attendance',
            'leave-requests' => 'Leave Requests',
            'salary-slips' => 'Salary Slips',
            'visitors' => 'Visitors',
            'invited-visitors' => 'Invited Visitors',
            'stock' => 'Stock Management',
            'performance' => 'Performance',
            'evaluation-report' => 'Evaluation Report',
            'interviews' => 'Interviews',
            'expenses' => 'Expenses',
            'form' => 'Form',
            'leads' => 'Leads',
            'interactions' => 'Interactions',
            'proposals' => 'Proposals',
            'executives' => 'Executives',
            'whatsapp' => 'WhatsApp Bot',
            'settings' => 'Settings',
            'logs' => 'Logs',
        ];

        return view('admin.sub-admins.show', compact('subAdmin', 'modules'));
    }

    public function performance(Request $request)
    {
        // Check if request is AJAX
        if ($request->ajax() || $request->wantsJson()) {
            return $this->getPerformanceData($request);
        }

        $period = $request->get('period', 'monthly');
        $employeeId = $request->get('employee_id');
        $view = $request->get('view', 'dashboard'); // Default to dashboard view

        // Get date range based on period
        $now = now();
        if ($period === 'all') {
            $startDate = now()->startOfYear(); // dummy for view
            $endDate = now()->endOfYear();
        } else {
            switch ($period) {
                case 'daily':
                    $startDate = $now->startOfDay();
                    $endDate = $now->endOfDay();
                    break;
                case 'weekly':
                    $startDate = $now->startOfWeek();
                    $endDate = $now->endOfWeek();
                    break;
                case 'monthly':
                default:
                    $startDate = $now->startOfMonth();
                    $endDate = $now->endOfMonth();
                    break;
            }
        }

        // Get all employees for dropdown
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();

        if ($view === 'by-report') {
            // Data based on evaluation reports
            $reportsQuery = \App\Models\EvaluationReport::with(['employee', 'evaluationOverall']);

            // Apply date filter if not 'all'
            if ($period !== 'all') {
                $reportsQuery->whereBetween('evaluation_date', [$startDate->toDateString(), $endDate->toDateString()]);
            }

            // Apply employee filter if specified
            if ($employeeId) {
                $reportsQuery->where('employee_id', $employeeId);
            }

            $reports = $reportsQuery->get();

            // Group by employee and calculate metrics based on reports
            $employeePerformance = [];
            $ratingDistribution = [];
            $totalRatings = 0;
            $averageRating = 0;
            $totalRatingSum = 0;

            foreach ($reports as $report) {
                if (!$report->evaluationOverall) continue;

                $empId = $report->employee_id;
                $overallRating = $report->evaluationOverall->overall_rating;

                if (!isset($employeePerformance[$empId])) {
                    $employeePerformance[$empId] = [
                        'employee' => $report->employee,
                        'total_ratings' => 0,
                        'average_rating' => 0,
                        'rating_sum' => 0,
                        'total_stars' => 0,
                        'rating_counts' => [],
                        'performance_score' => 0
                    ];
                }

                $employeePerformance[$empId]['total_ratings']++;
                $employeePerformance[$empId]['rating_sum'] += $overallRating;
                $employeePerformance[$empId]['total_stars'] += $overallRating;
                // Convert overall rating to star rating (1-5 scale)
                $stars = min(5, max(1, round($overallRating / 20))); // 0-100 to 1-5 scale
                if (!isset($employeePerformance[$empId]['rating_counts'][$stars])) {
                    $employeePerformance[$empId]['rating_counts'][$stars] = 0;
                }
                $employeePerformance[$empId]['rating_counts'][$stars]++;
                $employeePerformance[$empId]['average_rating'] = round($employeePerformance[$empId]['rating_sum'] / $employeePerformance[$empId]['total_ratings'], 1);

                // Calculate performance score (weighted average)
                $employeePerformance[$empId]['performance_score'] = $this->calculatePerformanceScore($employeePerformance[$empId]);

                // Use converted stars for ratingDistribution
                if (!isset($ratingDistribution[$stars])) {
                    $ratingDistribution[$stars] = 0;
                }
                $ratingDistribution[$stars]++;
                $totalRatings++;
                $totalRatingSum += $overallRating;
            }

            if ($totalRatings > 0) {
                $averageRating = round($totalRatingSum / $totalRatings, 1);
            }
        } else {
            // Original dashboard view based on ratings table
            $ratingsQuery = \App\Models\Rating::with('employee');

            if ($period !== 'all') {
                $ratingsQuery->whereBetween('rating_date', [$startDate->toDateString(), $endDate->toDateString()]);
            }

            // Apply employee filter if specified
            if ($employeeId) {
                $ratingsQuery->where('employee_id', $employeeId);
            }

            $ratings = $ratingsQuery->get();

            // Group by employee and calculate metrics based on ratings table
            $employeePerformance = [];
            $ratingDistribution = [];
            $totalRatings = 0;
            $averageRating = 0;
            $totalRatingSum = 0;

            foreach ($ratings as $rating) {
                $empId = $rating->employee_id;
                $stars = $rating->stars;

                if (!isset($employeePerformance[$empId])) {
                    $employeePerformance[$empId] = [
                        'employee' => $rating->employee,
                        'total_ratings' => 0,
                        'average_rating' => 0,
                        'rating_sum' => 0,
                        'total_stars' => 0,
                        'rating_counts' => [],
                        'performance_score' => 0
                    ];
                }

                $employeePerformance[$empId]['total_ratings']++;
                $employeePerformance[$empId]['rating_sum'] += $stars;
                $employeePerformance[$empId]['total_stars'] += $stars;
                // Use actual stars for rating_counts
                if (!isset($employeePerformance[$empId]['rating_counts'][$stars])) {
                    $employeePerformance[$empId]['rating_counts'][$stars] = 0;
                }
                $employeePerformance[$empId]['rating_counts'][$stars]++;
                $employeePerformance[$empId]['average_rating'] = round($employeePerformance[$empId]['rating_sum'] / $employeePerformance[$empId]['total_ratings'], 1);

                // Calculate performance score (weighted average)
                $employeePerformance[$empId]['performance_score'] = $this->calculatePerformanceScore($employeePerformance[$empId]);

                // Use actual stars for ratingDistribution
                if (!isset($ratingDistribution[$stars])) {
                    $ratingDistribution[$stars] = 0;
                }
                $ratingDistribution[$stars]++;
                $totalRatings++;
                $totalRatingSum += $stars;
            }

            if ($totalRatings > 0) {
                $averageRating = round($totalRatingSum / $totalRatings, 1);
            }
        }

        // Sort employees by performance score descending (better ranking)
        usort($employeePerformance, function($a, $b) {
            return $b['performance_score'] <=> $a['performance_score'];
        });

        return view('admin.performance', compact(
            'employeePerformance',
            'ratingDistribution',
            'totalRatings',
            'averageRating',
            'period',
            'startDate',
            'endDate',
            'employees',
            'view'
        ));
    }

    /**
     * Get performance data for AJAX requests
     */
    private function getPerformanceData(Request $request)
    {
        $period = $request->get('period', 'all');
        $employeeId = $request->get('employee_id');
        $view = $request->get('view', 'dashboard');

        // Get date range based on period
        $now = now();
        if ($period === 'all') {
            $startDate = now()->startOfYear();
            $endDate = now()->endOfYear();
        } else {
            switch ($period) {
                case 'daily':
                    $startDate = $now->startOfDay();
                    $endDate = $now->endOfDay();
                    break;
                case 'weekly':
                    $startDate = $now->startOfWeek();
                    $endDate = $now->endOfWeek();
                    break;
                case 'monthly':
                default:
                    $startDate = $now->startOfMonth();
                    $endDate = $now->endOfMonth();
                    break;
            }
        }

        if ($view === 'by-report') {
            // Data based on evaluation reports
            $reportsQuery = \App\Models\EvaluationReport::with(['employee', 'evaluationOverall']);

            if ($period !== 'all') {
                $reportsQuery->whereBetween('evaluation_date', [$startDate->toDateString(), $endDate->toDateString()]);
            }

            if ($employeeId) {
                $reportsQuery->where('employee_id', $employeeId);
            }

            $reports = $reportsQuery->get();

            $employeePerformance = [];
            $ratingDistribution = [];
            $totalRatings = 0;
            $averageRating = 0;
            $totalRatingSum = 0;

            foreach ($reports as $report) {
                if (!$report->evaluationOverall) continue;

                $empId = $report->employee_id;
                $overallRating = $report->evaluationOverall->overall_rating;

                if (!isset($employeePerformance[$empId])) {
                    $employeePerformance[$empId] = [
                        'employee' => $report->employee,
                        'total_ratings' => 0,
                        'average_rating' => 0,
                        'rating_sum' => 0,
                        'total_stars' => 0,
                        'rating_counts' => [],
                        'performance_score' => 0
                    ];
                }

                $employeePerformance[$empId]['total_ratings']++;
                $employeePerformance[$empId]['rating_sum'] += $overallRating;
                $employeePerformance[$empId]['total_stars'] += $overallRating;
                $stars = min(5, max(1, round($overallRating / 20)));
                if (!isset($employeePerformance[$empId]['rating_counts'][$stars])) {
                    $employeePerformance[$empId]['rating_counts'][$stars] = 0;
                }
                $employeePerformance[$empId]['rating_counts'][$stars]++;
                $employeePerformance[$empId]['average_rating'] = round($employeePerformance[$empId]['rating_sum'] / $employeePerformance[$empId]['total_ratings'], 1);
                $employeePerformance[$empId]['performance_score'] = $this->calculatePerformanceScore($employeePerformance[$empId]);

                if (!isset($ratingDistribution[$stars])) {
                    $ratingDistribution[$stars] = 0;
                }
                $ratingDistribution[$stars]++;
                $totalRatings++;
                $totalRatingSum += $overallRating;
            }

            if ($totalRatings > 0) {
                $averageRating = round($totalRatingSum / $totalRatings, 1);
            }
        } else {
            // Dashboard view based on ratings table
            $ratingsQuery = \App\Models\Rating::with('employee');

            if ($period !== 'all') {
                $ratingsQuery->whereBetween('rating_date', [$startDate->toDateString(), $endDate->toDateString()]);
            }

            if ($employeeId) {
                $ratingsQuery->where('employee_id', $employeeId);
            }

            $ratings = $ratingsQuery->get();

            $employeePerformance = [];
            $ratingDistribution = [];
            $totalRatings = 0;
            $averageRating = 0;
            $totalRatingSum = 0;

            foreach ($ratings as $rating) {
                $empId = $rating->employee_id;
                $stars = $rating->stars;

                if (!isset($employeePerformance[$empId])) {
                    $employeePerformance[$empId] = [
                        'employee' => $rating->employee,
                        'total_ratings' => 0,
                        'average_rating' => 0,
                        'rating_sum' => 0,
                        'total_stars' => 0,
                        'rating_counts' => [],
                        'performance_score' => 0
                    ];
                }

                $employeePerformance[$empId]['total_ratings']++;
                $employeePerformance[$empId]['rating_sum'] += $stars;
                $employeePerformance[$empId]['total_stars'] += $stars;
                if (!isset($employeePerformance[$empId]['rating_counts'][$stars])) {
                    $employeePerformance[$empId]['rating_counts'][$stars] = 0;
                }
                $employeePerformance[$empId]['rating_counts'][$stars]++;
                $employeePerformance[$empId]['average_rating'] = round($employeePerformance[$empId]['rating_sum'] / $employeePerformance[$empId]['total_ratings'], 1);
                $employeePerformance[$empId]['performance_score'] = $this->calculatePerformanceScore($employeePerformance[$empId]);

                if (!isset($ratingDistribution[$stars])) {
                    $ratingDistribution[$stars] = 0;
                }
                $ratingDistribution[$stars]++;
                $totalRatings++;
                $totalRatingSum += $stars;
            }

            if ($totalRatings > 0) {
                $averageRating = round($totalRatingSum / $totalRatings, 1);
            }
        }

        // Sort employees by performance score descending
        usort($employeePerformance, function($a, $b) {
            return $b['performance_score'] <=> $a['performance_score'];
        });

        return response()->json([
            'totalRatings' => $totalRatings,
            'averageRating' => $averageRating,
            'ratingDistribution' => $ratingDistribution,
            'employeePerformance' => array_values($employeePerformance)
        ]);
    }

    /**
     * Calculate performance score based on ratings
     * Higher score = better performance
     */
    private function calculatePerformanceScore($employeeData)
    {
        $avgRating = $employeeData['average_rating'];
        $totalRatings = $employeeData['total_ratings'];

        // Base score from average rating (0-5 points)
        $baseScore = $avgRating;

        // Bonus for consistency (more ratings = more reliable data)
        $consistencyBonus = min($totalRatings * 0.1, 1.0); // Max 1.0 bonus

        // Bonus for high ratings (5-star ratings get extra points)
        $highRatingBonus = (isset($employeeData['rating_counts'][5]) ? $employeeData['rating_counts'][5] : 0) / max($totalRatings, 1) * 0.5;

        return round($baseScore + $consistencyBonus + $highRatingBonus, 1);
    }

    public function evaluationReport(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to view evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to view evaluation reports.');
        }

        // Get selected month, default to current
        $selectedMonth = $request->get('month', now()->format('Y-m'));
        $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Get selected employee filter
        $employeeId = $request->get('employee_id');

        // Generate weeks for the month
        $weeks = [];
        $current = $monthStart->copy()->startOfWeek(\Carbon\Carbon::MONDAY); // Start from Monday
        $weekNumber = 1;

        while ($current->lte($monthEnd)) {
            $weekStart = $current->copy();
            $weekEnd = $current->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);

            // Ensure week end doesn't go beyond month end
            if ($weekEnd->gt($monthEnd)) {
                $weekEnd = $monthEnd->copy();
            }

            $weeks[] = [
                'title' => "Week {$weekNumber}: " . $weekStart->format('M d') . ' - ' . $weekEnd->format('M d'),
                'start_date' => $weekStart->format('Y-m-d'),
                'end_date' => $weekEnd->format('Y-m-d'),
            ];

            $current->addWeek();
            $weekNumber++;
        }

        // Month card
        $monthCard = [
            'title' => $monthStart->format('F Y'),
            'start_date' => $monthStart->format('Y-m-d'),
            'end_date' => $monthEnd->format('Y-m-d'),
        ];

        // Fetch existing reports for each period based on admin role
        $existingReports = [];

        // For each week
        foreach ($weeks as $week) {
            $key = $week['start_date'] . '-' . $week['end_date'];
            $query = EvaluationReport::with('employee')
                ->where('review_from', $week['start_date'])
                ->where('review_to', $week['end_date']);

            // Filter reports based on admin role - allow sub-admins with permission to see all reports
            if ($admin->role === 'super_admin') {
                // Super admin can see all reports
            } elseif ($admin->role === 'sub_admin') {
                // Sub-admins with evaluation-report permission can see all reports
                // No assignment-based filtering to allow visibility across sub-admins
            }

            if ($employeeId) {
                $query->where('employee_id', $employeeId);
            }
            $existingReports[$key] = $query->get();
        }

        // For the month
        $monthKey = $monthCard['start_date'] . '-' . $monthCard['end_date'];
        $query = EvaluationReport::with('employee')
            ->where('review_from', $monthCard['start_date'])
            ->where('review_to', $monthCard['end_date']);

        // Apply same role-based filtering for monthly reports
        if ($admin->role === 'super_admin') {
            // Super admin can see all reports
        } elseif ($admin->role === 'sub_admin') {
            // Sub-admins with evaluation-report permission can see all reports
            // No assignment-based filtering to allow visibility across sub-admins
        }

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }
        $existingReports[$monthKey] = $query->get();

        // Generate month options for selector (last 12 months)
        $monthOptions = [];
        for ($i = 0; $i < 12; $i++) {
            $date = now()->subMonths($i);
            $monthOptions[$date->format('Y-m')] = $date->format('F Y');
        }

        // Get all employees for manager and employee selection
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();

        // Get sub-admins for assignments
        $subAdmins = Admin::where('role', 'sub_admin')->get();

        // Get current assignments
        $step1Assignments = EvaluationAssignment::where('step', 'step1')->first();
        $step2Assignments = EvaluationAssignment::where('step', 'step2')->first();

        return view('admin.evaluation-report', compact('weeks', 'monthCard', 'selectedMonth', 'monthOptions', 'existingReports', 'employees', 'employeeId', 'subAdmins', 'step1Assignments', 'step2Assignments'));
    }

    public function addEvaluationReport(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to add evaluation reports
        if (!$admin->hasPermission('performance') || ($admin->role === 'sub_admin' && !$admin->hasPermission('evaluation-report'))) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to add evaluation reports.');
        }

        // Get all employees for the dropdown
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();

        // Get pre-filled dates from query parameters
        $reviewFrom = $request->get('review_from');
        $reviewTo = $request->get('review_to');

        // For new reports, existingReport is null
        $existingReport = null;
        $overall = $existingReport->overallEvaluation ?? null;


        return view('admin.add-evaluation-report', compact('employees', 'reviewFrom', 'reviewTo', 'existingReport','overall'));
    }

    public function storeEvaluationReport(Request $request)
    {
        // Debug: Log that the method was called
        Log::info('storeEvaluationReport called', $request->all());

        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to add evaluation reports
        if (!$admin->hasPermission('performance') || ($admin->role === 'sub_admin' && !$admin->hasPermission('evaluation-report'))) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to add evaluation reports.');
        }

        $request->validate([
            'employee_name' => 'required|string',
            'review_from' => 'required|date',
            'review_to' => 'required|date',
            'evaluation_date' => 'required|date',
            // Manager evaluation fields
            'project_delivery' => 'nullable|string',
            'code_quality' => 'nullable|string',
            'performance' => 'nullable|string',
            'task_completion' => 'nullable|string',
            'innovation' => 'nullable|string',
            'code_efficiency' => 'nullable|integer|min:1|max:5',
            'uiux' => 'nullable|integer|min:1|max:5',
            'debugging' => 'nullable|integer|min:1|max:5',
            'version_control' => 'nullable|integer|min:1|max:5',
            'documentation' => 'nullable|integer|min:1|max:5',
            'manager_comments' => 'nullable|string',
            // HR evaluation fields
            'teamwork' => 'nullable|string',
            'communication' => 'nullable|string',
            'attendance' => 'nullable|string',
            'professionalism' => 'nullable|integer|min:1|max:5',
            'team_collaboration' => 'nullable|integer|min:1|max:5',
            'learning' => 'nullable|integer|min:1|max:5',
            'initiative' => 'nullable|integer|min:1|max:5',
            'time_management' => 'nullable|integer|min:1|max:5',
            'hr_comments' => 'nullable|string',
            // Overall evaluation fields
            'technical_skills' => 'nullable|numeric|min:0|max:40',
            'task_delivery_score' => 'nullable|numeric|min:0|max:25',
            'quality_work' => 'nullable|numeric|min:0|max:15',
            'communication_score' => 'nullable|numeric|min:0|max:10',
            'behavior_teamwork' => 'nullable|numeric|min:0|max:10',
            'performance_grade' => 'nullable|string',
            'final_feedback' => 'nullable|string',
        ]);

        // Extract employee ID from the employee_name field (format: "E101 - Aman Singh")
        $employeeNameParts = explode(' - ', $request->employee_name);
        $employeeCode = $employeeNameParts[0];

        // Find the employee by employee_code to get the actual ID
        $employee = \App\Models\Employee::where('employee_code', $employeeCode)->first();
        if (!$employee) {
            return redirect()->back()->with('error', 'Employee not found with code: ' . $employeeCode);
        }

        // Create the main evaluation report
        try {
            $evaluationReport = EvaluationReport::create([
                'employee_id' => $employee->id,
                'review_from' => $request->review_from,
                'review_to' => $request->review_to,
                'evaluation_date' => $request->evaluation_date,
                'manager_submitted' => false,
                'hr_submitted' => false,
                'overall_submitted' => false,
                'manager_id' => $admin->id, // Assuming current admin is manager
                'hr_id' => $admin->id, // Assuming current admin is HR
                'final_approver_id' => $admin->id,
            ]);
            Log::info('EvaluationReport created', ['id' => $evaluationReport->id]);
        } catch (\Exception $e) {
            Log::error('Failed to create EvaluationReport', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to save evaluation report: ' . $e->getMessage());
        }
        // Calculate manager total (sum of ratings * (6 / 5), out of 30)
        $managerRatings = [
            $request->code_efficiency ?? 0,
            $request->uiux ?? 0,
            $request->debugging ?? 0,
            $request->version_control ?? 0,
            $request->documentation ?? 0,
        ];
        $managerTotal = array_sum($managerRatings) * (6 / 5);

        // Create evaluation manager
        try {
            EvaluationManager::create([
                'report_id' => $evaluationReport->id,
                'project_delivery' => $request->project_delivery,
                'code_quality' => $request->code_quality,
                'performance' => $request->performance,
                'task_completion' => $request->task_completion,
                'innovation' => $request->innovation,
                'code_efficiency' => $request->code_efficiency,
                'uiux' => $request->uiux,
                'debugging' => $request->debugging,
                'version_control' => $request->version_control,
                'documentation' => $request->documentation,
                'manager_total' => $managerTotal,
                'manager_comments' => $request->manager_comments,
            ]);
            Log::info('EvaluationManager created', ['report_id' => $evaluationReport->id]);
        } catch (\Exception $e) {
            Log::error('Failed to create EvaluationManager', ['error' => $e->getMessage()]);
        }
        // Calculate HR total (sum of ratings * (4 / 5), out of 20)
        $hrRatings = [
            $request->professionalism ?? 0,
            $request->team_collaboration ?? 0,
            $request->learning ?? 0,
            $request->initiative ?? 0,
            $request->time_management ?? 0,
        ];
        $hrTotal = array_sum($hrRatings) * (4 / 5);

        // Create evaluation hr
        try {
            EvaluationHr::create([
                'report_id' => $evaluationReport->id,
                'teamwork' => $request->teamwork,
                'communication' => $request->communication,
                'attendance' => $request->attendance,
                'professionalism' => $request->professionalism,
                'team_collaboration' => $request->team_collaboration,
                'learning' => $request->learning,
                'initiative' => $request->initiative,
                'time_management' => $request->time_management,
                'hr_total' => $hrTotal,
                'hr_comments' => $request->hr_comments,
            ]);
            Log::info('EvaluationHr created', ['report_id' => $evaluationReport->id]);
        } catch (\Exception $e) {
            Log::error('Failed to create EvaluationHr', ['error' => $e->getMessage()]);
        }

        // Calculate overall rating (sum of sliders, out of 100)
        $overallRating = ($request->technical_skills ?? 0) + ($request->task_delivery_score ?? 0) +
                        ($request->quality_work ?? 0) + ($request->communication_score ?? 0) +
                        ($request->behavior_teamwork ?? 0);

        // Determine performance grade based on overall rating
        $performanceGrade = $request->performance_grade ?? 'Satisfactory';
        if ($overallRating >= 80) {
            $performanceGrade = 'Excellent';
        } elseif ($overallRating >= 60) {
            $performanceGrade = 'Good';
        } elseif ($overallRating >= 40) {
            $performanceGrade = 'Satisfactory';
        } else {
            $performanceGrade = 'Needs Improvement';
        }

        // Create evaluation overall
        try {
            EvaluationOverall::create([
                'report_id' => $evaluationReport->id,
                'technical_skills' => $request->technical_skills,
                'task_delivery' => $request->task_delivery_score,
                'quality_work' => $request->quality_work,
                'communication' => $request->communication_score,
                'behavior_teamwork' => $request->behavior_teamwork,
                'overall_rating' => $overallRating,
                'performance_grade' => $performanceGrade,
                'final_feedback' => $request->final_feedback,
            ]);
            Log::info('EvaluationOverall created', ['report_id' => $evaluationReport->id]);
        } catch (\Exception $e) {
            Log::error('Failed to create EvaluationOverall', ['error' => $e->getMessage()]);
        }

        // Update the main report with overall score and grade
        $evaluationReport->update([
            'overall_score' => $overallRating,
            'performance_grade' => $performanceGrade,
        ]);

     /* ===============================
   🔔 NOTIFICATION : Evaluation Report Created (SKIP SELF)
================================ */

$actor = auth('admin')->user();
$actorName = $actor ? $actor->name : 'Admin';

$employeeName = $employee->name ?? ('Employee ID: ' . $employee->id);

// ✅ Super Admin + Sub Admins with performance permission
$admins = \App\Models\Admin::all()->filter(function ($adminUser) use ($actor) {

    // ❌ Skip the admin who created the report
    if ($actor && $adminUser->id === $actor->id) {
        return false;
    }

    return $adminUser->role === 'super_admin'
        || ($adminUser->role === 'sub_admin' && $adminUser->hasPermission('performance'));
});

foreach ($admins as $adminUser) {
    \App\Models\Notification::create([
        'admin_id' => $adminUser->id,
        'title' => 'Evaluation Report Created',
        'message' => "{$actorName} created an evaluation report for {$employeeName} ({$request->review_from} to {$request->review_to}).",
        'is_read' => false,
    ]);
}

/* =============================== */


        Log::info('Evaluation report created successfully', ['report_id' => $evaluationReport->id]);

        return redirect()->route('admin.evaluation-report')->with('success', 'Evaluation report submitted successfully!');
    }

    public function editEvaluationReport($id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to edit evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to edit evaluation reports.');
        }

        $report = EvaluationReport::with(['employee', 'evaluationManager', 'evaluationHr', 'evaluationOverall'])->findOrFail($id);

        // Get all employees for the dropdown
        $employees = \App\Models\Employee::select('id', 'name', 'employee_code')->orderBy('name')->get();

        return view('admin.edit-evaluation-report', compact('report', 'employees'));
    }

    public function updateEvaluationReport(Request $request, $id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to update evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to update evaluation reports.');
        }

        $request->validate([
            'employee_name' => 'required|string',
            'review_from' => 'required|date',
            'review_to' => 'required|date',
            'evaluation_date' => 'required|date',
            // Manager evaluation fields
            'project_delivery' => 'nullable|string',
            'code_quality' => 'nullable|string',
            'performance' => 'nullable|string',
            'task_completion' => 'nullable|string',
            'innovation' => 'nullable|string',
            'code_efficiency' => 'nullable|integer|min:1|max:5',
            'uiux' => 'nullable|integer|min:1|max:5',
            'debugging' => 'nullable|integer|min:1|max:5',
            'version_control' => 'nullable|integer|min:1|max:5',
            'documentation' => 'nullable|integer|min:1|max:5',
            'manager_comments' => 'nullable|string',
            // HR evaluation fields
            'teamwork' => 'nullable|string',
            'communication' => 'nullable|string',
            'attendance' => 'nullable|string',
            'professionalism' => 'nullable|integer|min:1|max:5',
            'team_collaboration' => 'nullable|integer|min:1|max:5',
            'learning' => 'nullable|integer|min:1|max:5',
            'initiative' => 'nullable|integer|min:1|max:5',
            'time_management' => 'nullable|integer|min:1|max:5',
            'hr_comments' => 'nullable|string',
            // Overall evaluation fields
            'technical_skills' => 'nullable|numeric|min:0|max:40',
            'task_delivery_score' => 'nullable|numeric|min:0|max:25',
            'quality_work' => 'nullable|numeric|min:0|max:15',
            'communication_score' => 'nullable|numeric|min:0|max:10',
            'behavior_teamwork' => 'nullable|numeric|min:0|max:10',
            'performance_grade' => 'nullable|string',
            'final_feedback' => 'nullable|string',
        ]);

        $report = EvaluationReport::findOrFail($id);

        // Extract employee ID from the employee_name field (format: "E101 - Aman Singh")
        $employeeNameParts = explode(' - ', $request->employee_name);
        $employeeCode = $employeeNameParts[0];

        // Find the employee by employee_code to get the actual ID
        $employee = \App\Models\Employee::where('employee_code', $employeeCode)->first();
        if (!$employee) {
            return redirect()->back()->with('error', 'Employee not found with code: ' . $employeeCode);
        }

        // Update the main evaluation report
        $report->update([
            'employee_id' => $employee->id,
            'review_from' => $request->review_from,
            'review_to' => $request->review_to,
            'evaluation_date' => $request->evaluation_date,
        ]);

        // Update or create evaluation manager
        if ($report->evaluationManager) {
            $updateData = [];
            if ($request->project_delivery !== null) $updateData['project_delivery'] = $request->project_delivery;
            if ($request->code_quality !== null) $updateData['code_quality'] = $request->code_quality;
            if ($request->performance !== null) $updateData['performance'] = $request->performance;
            if ($request->task_completion !== null) $updateData['task_completion'] = $request->task_completion;
            if ($request->innovation !== null) $updateData['innovation'] = $request->innovation;
            if ($request->code_efficiency !== null) $updateData['code_efficiency'] = $request->code_efficiency;
            if ($request->uiux !== null) $updateData['uiux'] = $request->uiux;
            if ($request->debugging !== null) $updateData['debugging'] = $request->debugging;
            if ($request->version_control !== null) $updateData['version_control'] = $request->version_control;
            if ($request->documentation !== null) $updateData['documentation'] = $request->documentation;
            if ($request->manager_comments !== null) $updateData['manager_comments'] = $request->manager_comments;

            if (!empty($updateData)) {
                $report->evaluationManager->update($updateData);

                // Recalculate manager total after update
                $manager = $report->evaluationManager->fresh();
                $managerRatings = [
                    $manager->code_efficiency ?? 0,
                    $manager->uiux ?? 0,
                    $manager->debugging ?? 0,
                    $manager->version_control ?? 0,
                    $manager->documentation ?? 0,
                ];
                $managerTotal = array_sum($managerRatings) * (6 / 5);
                $report->evaluationManager->update(['manager_total' => $managerTotal]);
            }
        } else {
            // Calculate manager total (sum of ratings * (6 / 5), out of 30)
            $managerRatings = [
                $request->code_efficiency ?? 0,
                $request->uiux ?? 0,
                $request->debugging ?? 0,
                $request->version_control ?? 0,
                $request->documentation ?? 0,
            ];
            $managerTotal = array_sum($managerRatings) * (6 / 5);

            EvaluationManager::create([
                'report_id' => $report->id,
                'project_delivery' => $request->project_delivery,
                'code_quality' => $request->code_quality,
                'performance' => $request->performance,
                'task_completion' => $request->task_completion,
                'innovation' => $request->innovation,
                'code_efficiency' => $request->code_efficiency,
                'uiux' => $request->uiux,
                'debugging' => $request->debugging,
                'version_control' => $request->version_control,
                'documentation' => $request->documentation,
                'manager_total' => $managerTotal,
                'manager_comments' => $request->manager_comments,
            ]);
        }

        // Update or create evaluation hr
        if ($report->evaluationHr) {
            $updateData = [];
            if ($request->teamwork !== null) $updateData['teamwork'] = $request->teamwork;
            if ($request->communication !== null) $updateData['communication'] = $request->communication;
            if ($request->attendance !== null) $updateData['attendance'] = $request->attendance;
            if ($request->professionalism !== null) $updateData['professionalism'] = $request->professionalism;
            if ($request->team_collaboration !== null) $updateData['team_collaboration'] = $request->team_collaboration;
            if ($request->learning !== null) $updateData['learning'] = $request->learning;
            if ($request->initiative !== null) $updateData['initiative'] = $request->initiative;
            if ($request->time_management !== null) $updateData['time_management'] = $request->time_management;
            if ($request->hr_comments !== null) $updateData['hr_comments'] = $request->hr_comments;

            if (!empty($updateData)) {
                $report->evaluationHr->update($updateData);

                // Recalculate HR total after update
                $hr = $report->evaluationHr->fresh();
                $hrRatings = [
                    $hr->professionalism ?? 0,
                    $hr->team_collaboration ?? 0,
                    $hr->learning ?? 0,
                    $hr->initiative ?? 0,
                    $hr->time_management ?? 0,
                ];
                $hrTotal = array_sum($hrRatings) * (4 / 5);
                $report->evaluationHr->update(['hr_total' => $hrTotal]);
            }
        } else {
            // Calculate HR total (sum of ratings * (4 / 5), out of 20)
            $hrRatings = [
                $request->professionalism ?? 0,
                $request->team_collaboration ?? 0,
                $request->learning ?? 0,
                $request->initiative ?? 0,
                $request->time_management ?? 0,
            ];
            $hrTotal = array_sum($hrRatings) * (4 / 5);

            EvaluationHr::create([
                'report_id' => $report->id,
                'teamwork' => $request->teamwork,
                'communication' => $request->communication,
                'attendance' => $request->attendance,
                'professionalism' => $request->professionalism,
                'team_collaboration' => $request->team_collaboration,
                'learning' => $request->learning,
                'initiative' => $request->initiative,
                'time_management' => $request->time_management,
                'hr_total' => $hrTotal,
                'hr_comments' => $request->hr_comments,
            ]);
        }

        // Calculate overall rating (sum of sliders, out of 100)
        $overallRating = ($request->technical_skills ?? 0) + ($request->task_delivery_score ?? 0) +
                        ($request->quality_work ?? 0) + ($request->communication_score ?? 0) +
                        ($request->behavior_teamwork ?? 0);

        // Determine performance grade based on overall rating
        $performanceGrade = $request->performance_grade ?? 'Satisfactory';
        if ($overallRating >= 80) {
            $performanceGrade = 'Excellent';
        } elseif ($overallRating >= 60) {
            $performanceGrade = 'Good';
        } elseif ($overallRating >= 40) {
            $performanceGrade = 'Satisfactory';
        } else {
            $performanceGrade = 'Needs Improvement';
        }

        // Update or create evaluation overall
        if ($report->evaluationOverall) {
            $updateData = [];
            if ($request->technical_skills !== null) $updateData['technical_skills'] = $request->technical_skills;
            if ($request->task_delivery_score !== null) $updateData['task_delivery'] = $request->task_delivery_score;
            if ($request->quality_work !== null) $updateData['quality_work'] = $request->quality_work;
            if ($request->communication_score !== null) $updateData['communication'] = $request->communication_score;
            if ($request->behavior_teamwork !== null) $updateData['behavior_teamwork'] = $request->behavior_teamwork;
            if ($request->performance_grade !== null) $updateData['performance_grade'] = $request->performance_grade;
            if ($request->final_feedback !== null) $updateData['final_feedback'] = $request->final_feedback;

            if (!empty($updateData)) {
                $report->evaluationOverall->update($updateData);

                // Recalculate overall rating after update
                $overall = $report->evaluationOverall->fresh();
                $overallRating = ($overall->technical_skills ?? 0) + ($overall->task_delivery ?? 0) +
                                ($overall->quality_work ?? 0) + ($overall->communication ?? 0) +
                                ($overall->behavior_teamwork ?? 0);

                // Determine performance grade based on overall rating
                $performanceGrade = $overall->performance_grade ?? 'Satisfactory';
                if ($overallRating >= 80) {
                    $performanceGrade = 'Excellent';
                } elseif ($overallRating >= 60) {
                    $performanceGrade = 'Good';
                } elseif ($overallRating >= 40) {
                    $performanceGrade = 'Satisfactory';
                } else {
                    $performanceGrade = 'Needs Improvement';
                }

                $report->evaluationOverall->update([
                    'overall_rating' => $overallRating,
                    'performance_grade' => $performanceGrade,
                ]);
            }
        } else {
            EvaluationOverall::create([
                'report_id' => $report->id,
                'technical_skills' => $request->technical_skills ?? 0,
                'task_delivery' => $request->task_delivery_score ?? 0,
                'quality_work' => $request->quality_work ?? 0,
                'communication' => $request->communication_score ?? 0,
                'behavior_teamwork' => $request->behavior_teamwork ?? 0,
                'overall_rating' => $overallRating,
                'performance_grade' => $performanceGrade,
                'final_feedback' => $request->final_feedback,
            ]);
        }

        // Update the main report with overall score and grade
        $report->update([
            'overall_score' => $overallRating,
            'performance_grade' => $performanceGrade,
        ]);

/* ===============================
   🔔 NOTIFICATION : Evaluation Report Updated (SKIP SELF)
================================ */

$actor = auth('admin')->user();
$actorName = $actor ? $actor->name : 'Admin';

$employeeName = $employee->name ?? ('Employee ID: ' . $employee->id);

// ✅ Super Admin + Sub Admins with performance permission
$admins = \App\Models\Admin::all()->filter(function ($adminUser) use ($actor) {

    // ❌ Skip the admin who updated the report
    if ($actor && $adminUser->id === $actor->id) {
        return false;
    }

    return $adminUser->role === 'super_admin'
        || ($adminUser->role === 'sub_admin' && $adminUser->hasPermission('performance'));
});

foreach ($admins as $adminUser) {
    \App\Models\Notification::create([
        'admin_id' => $adminUser->id,
        'title' => 'Evaluation Report Updated',
        'message' => "{$actorName} updated the evaluation report for {$employeeName}.",
        'is_read' => false,
    ]);
}

/* =============================== */

        return redirect()->route('admin.evaluation-report')->with('success', 'Evaluation report updated successfully!');
    }

    public function showEvaluationReport($id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to view evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to view evaluation reports.');
        }

        $report = EvaluationReport::with(['employee', 'evaluationManager', 'evaluationHr', 'evaluationOverall'])
            ->findOrFail($id);

        return view('admin.show-evaluation-report', compact('report'));
    }

    public function getEvaluationReportData($id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to view evaluation reports
        if (!$admin->hasPermission('performance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $report = EvaluationReport::with(['employee', 'evaluationManager', 'evaluationHr', 'evaluationOverall'])
            ->findOrFail($id);

        return response()->json([
            'report' => $report,
            'employee' => $report->employee,
            'evaluationManager' => $report->evaluationManager,
            'evaluationHr' => $report->evaluationHr,
            'evaluationOverall' => $report->evaluationOverall
        ]);
    }

    public function deleteEvaluationReport($id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to delete evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to delete evaluation reports.');
        }

        $report = EvaluationReport::findOrFail($id);

        // Delete related records first
        $report->evaluationManager()->delete();
        $report->evaluationHr()->delete();
        $report->evaluationOverall()->delete();

        // Delete the main report
        $report->delete();

        return redirect()->route('admin.evaluation-report')->with('success', 'Evaluation report deleted successfully!');
    }

    public function downloadEvaluationReportPdf($id)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to view evaluation reports
        if (!$admin->hasPermission('performance')) {
            return redirect()->route('admin.dashboard')->with('error', 'You do not have permission to download evaluation reports.');
        }

        $report = EvaluationReport::with(['employee', 'evaluationManager', 'evaluationHr', 'evaluationOverall'])
            ->findOrFail($id);

        // Get evaluation assignments
        $step1Assignments = EvaluationAssignment::where('step', 'step1')->first();
        $step2Assignments = EvaluationAssignment::where('step', 'step2')->first();

        // Get dynamic logo and company name from admin table
        $logo = '';
        if ($admin->company_logo && Storage::disk('public')->exists('company_logos/' . $admin->company_logo)) {
            $imagePath = storage_path('app/public/company_logos/' . $admin->company_logo);
            if (file_exists($imagePath)) {
                $imageData = base64_encode(file_get_contents($imagePath));
                $extension = pathinfo($admin->company_logo, PATHINFO_EXTENSION);
                $logo = 'data:image/' . $extension . ';base64,' . $imageData;
            }
        } else {
            // Use static logo
            $staticLogoPath = public_path('images/logo.png');
            if (file_exists($staticLogoPath)) {
                $imageData = base64_encode(file_get_contents($staticLogoPath));
                $logo = 'data:image/png;base64,' . $imageData;
            }
        }
        $company_name = $admin->company_name ?? 'Bitmax Group';

        $pdf = Pdf::loadView('admin.evaluation-report-pdf', compact('report', 'logo', 'company_name', 'step1Assignments', 'step2Assignments'));

        $filename = 'evaluation-report-' . $report->employee->name . '-' . $report->id . '.pdf';

        return $pdf->download($filename);
    }

public function logs()
{
    $admin = Auth::guard('admin')->user();

    // Check if admin has permission to view logs
    if (!$admin->hasPermission('logs')) {
        return redirect()->route('admin.dashboard')
            ->with('error', 'You do not have permission to view logs');
    }

    $logs = \App\Models\ActivityLog::with('user')
        ->whereHasMorph('user', [\App\Models\Admin::class], function ($query) {
            $query->where('role', 'sub_admin');
        })
        ->orderBy('created_at', 'desc')
        ->paginate(20);

    return view('admin.logs', compact('admin', 'logs'));
}

public function search(Request $request)
{
    $query = $request->get('q');
    if (!$query) {
        return response()->json([]);
    }

    $results = [];

    try {
        // Search Employees
        $employees = \App\Models\Employee::where('name', 'like', "%{$query}%")
            ->orWhere('employee_code', 'like', "%{$query}%")
            ->limit(5)
            ->get();
        foreach ($employees as $emp) {
            $results[] = [
                'name' => $emp->name . ' (' . $emp->employee_code . ')',
                'module' => 'Employee',
                'url' => route('employees.show', $emp->id)
            ];
        }

        // Search Tasks
        $tasks = \App\Models\Task::where('task_name', 'like', "%{$query}%")
            ->limit(5)
            ->get();
        foreach ($tasks as $task) {
            $results[] = [
                'name' => $task->task_name,
                'module' => 'Task',
                'url' => route('tasks.show', $task->id)
            ];
        }

        // Search Sub Admins
        $subAdmins = \App\Models\Admin::where('role', 'sub_admin')
            ->where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get();
        foreach ($subAdmins as $admin) {
            $results[] = [
                'name' => $admin->name,
                'module' => 'Sub Admin',
                'url' => route('admin.sub-admins.show', $admin->id)
            ];
        }

        // Search Visitors
        $visitors = \App\Models\Visitor::where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get();
        foreach ($visitors as $visitor) {
            $results[] = [
                'name' => $visitor->name,
                'module' => 'Visitor',
                'url' => route('visitors.show', $visitor->id)
            ];
        }

        // Search Invited Visitors
        $invitedVisitors = \App\Models\InvitedVisitor::where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get();
        foreach ($invitedVisitors as $iv) {
            $results[] = [
                'name' => $iv->name,
                'module' => 'Invited Visitor',
                'url' => route('invited-visitors.show', $iv->id)
            ];
        }
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]);
    }

    return response()->json($results);
}

    public function updateEvaluationAssignments(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // Only super admin can update assignments
        if ($admin->role !== 'super_admin') {
            return redirect()->back()->with('error', 'Unauthorized access.');
        }

        $request->validate([
            'step1_admins' => 'array',
            'step2_admins' => 'array',
        ]);

        // Update step 1 assignments (Manager Evaluation)
        EvaluationAssignment::updateOrCreate(
            ['step' => 'step1'],
            ['assigned_admins' => $request->step1_admins ?? []]
        );

        // Update step 2 assignments (HR Evaluation)
        EvaluationAssignment::updateOrCreate(
            ['step' => 'step2'],
            ['assigned_admins' => $request->step2_admins ?? []]
        );

        // Log the activity
        $this->logActivity('update_evaluation_assignments', null, null, "Admin {$admin->name} updated evaluation assignments");

        return redirect()->back()->with('success', 'Evaluation assignments updated successfully.');
    }

    /**
     * Save evaluation report draft for step-by-step submission
     */
    public function saveEvaluationDraft(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        // Check if admin has permission to add evaluation reports
        if (!$admin->hasPermission('performance') || ($admin->role === 'sub_admin' && !$admin->hasPermission('evaluation-report'))) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'step' => 'required|in:employee_details,manager_evaluation,hr_evaluation,overall_evaluation',
            'report_id' => 'nullable|exists:evaluation_reports,id',
            // Employee details validation
            'employee_name' => 'required_if:step,employee_details|string',
            'review_from' => 'required_if:step,employee_details|date',
            'review_to' => 'required_if:step,employee_details|date',
            'evaluation_date' => 'required_if:step,employee_details|date',
            // Manager evaluation validation
            'project_delivery' => 'nullable|string',
            'code_quality' => 'nullable|string',
            'performance' => 'nullable|string',
            'task_completion' => 'nullable|string',
            'innovation' => 'nullable|string',
            'code_efficiency' => 'nullable|integer|min:1|max:5',
            'uiux' => 'nullable|integer|min:1|max:5',
            'debugging' => 'nullable|integer|min:1|max:5',
            'version_control' => 'nullable|integer|min:1|max:5',
            'documentation' => 'nullable|integer|min:1|max:5',
            'manager_comments' => 'nullable|string',
            // HR evaluation validation
            'teamwork' => 'nullable|string',
            'communication' => 'nullable|string',
            'attendance' => 'nullable|string',
            'professionalism' => 'nullable|integer|min:1|max:5',
            'team_collaboration' => 'nullable|integer|min:1|max:5',
            'learning' => 'nullable|integer|min:1|max:5',
            'initiative' => 'nullable|integer|min:1|max:5',
            'time_management' => 'nullable|integer|min:1|max:5',
            'hr_comments' => 'nullable|string',
            // Overall evaluation validation
            'technical_skills' => 'nullable|numeric|min:0|max:40',
            'task_delivery_score' => 'nullable|numeric|min:0|max:25',
            'quality_work' => 'nullable|numeric|min:0|max:15',
            'communication_score' => 'nullable|numeric|min:0|max:10',
            'behavior_teamwork' => 'nullable|numeric|min:0|max:10',
            'performance_grade' => 'nullable|string',
            'final_feedback' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $report = null;
            if ($request->report_id) {
                $report = EvaluationReport::find($request->report_id);
            }

            // Create or update report based on step
            if ($request->step === 'employee_details') {
                // Extract employee ID from the employee_name field (format: "E101 - Aman Singh")
                $employeeNameParts = explode(' - ', $request->employee_name);
                $employeeCode = $employeeNameParts[0];

                // Find the employee by employee_code to get the actual ID
                $employee = \App\Models\Employee::where('employee_code', $employeeCode)->first();
                if (!$employee) {
                    return response()->json(['error' => 'Employee not found'], 404);
                }

                if (!$report) {
                    $report = EvaluationReport::create([
                        'employee_id' => $employee->id,
                        'review_from' => $request->review_from,
                        'review_to' => $request->review_to,
                        'evaluation_date' => $request->evaluation_date,
                        'status' => 'draft',
                        'current_step' => 'employee_details',
                        'manager_id' => $admin->id,
                        'hr_id' => $admin->id,
                        'final_approver_id' => $admin->id,
                    ]);
                } else {
                    $report->update([
                        'employee_id' => $employee->id,
                        'review_from' => $request->review_from,
                        'review_to' => $request->review_to,
                        'evaluation_date' => $request->evaluation_date,
                        'current_step' => 'employee_details',
                    ]);
                }
            } else {
                // For other steps, ensure report exists
                if (!$report) {
                    return response()->json(['error' => 'Report not found'], 404);
                }

                // Update current step
                $report->update(['current_step' => $request->step]);
            }

            // Handle step-specific data
            switch ($request->step) {
                case 'manager_evaluation':
                    $this->saveManagerEvaluation($report, $request);
                    break;
                case 'hr_evaluation':
                    $this->saveHrEvaluation($report, $request);
                    break;
                case 'overall_evaluation':
                    $this->saveOverallEvaluation($report, $request);
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'report_id' => $report->id,
                'message' => 'Draft saved successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Draft save failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to save draft'], 500);
        }
    }

    /**
     * Save manager evaluation data
     */
    private function saveManagerEvaluation($report, $request)
    {
        // Calculate manager total (sum of ratings * (6 / 5), out of 30)
        $managerRatings = [
            $request->code_efficiency ?? 0,
            $request->uiux ?? 0,
            $request->debugging ?? 0,
            $request->version_control ?? 0,
            $request->documentation ?? 0,
        ];
        $managerTotal = array_sum($managerRatings) * (6 / 5);

        EvaluationManager::updateOrCreate(
            ['report_id' => $report->id],
            [
                'project_delivery' => $request->project_delivery,
                'code_quality' => $request->code_quality,
                'performance' => $request->performance,
                'task_completion' => $request->task_completion,
                'innovation' => $request->innovation,
                'code_efficiency' => $request->code_efficiency,
                'uiux' => $request->uiux,
                'debugging' => $request->debugging,
                'version_control' => $request->version_control,
                'documentation' => $request->documentation,
                'manager_total' => $managerTotal,
                'manager_comments' => $request->manager_comments,
            ]
        );

        $report->update(['manager_submitted' => true]);
    }

    /**
     * Save HR evaluation data
     */
    private function saveHrEvaluation($report, $request)
    {
        // Calculate HR total (sum of ratings * (4 / 5), out of 20)
        $hrRatings = [
            $request->professionalism ?? 0,
            $request->team_collaboration ?? 0,
            $request->learning ?? 0,
            $request->initiative ?? 0,
            $request->time_management ?? 0,
        ];
        $hrTotal = array_sum($hrRatings) * (4 / 5);

        EvaluationHr::updateOrCreate(
            ['report_id' => $report->id],
            [
                'teamwork' => $request->teamwork,
                'communication' => $request->communication,
                'attendance' => $request->attendance,
                'professionalism' => $request->professionalism,
                'team_collaboration' => $request->team_collaboration,
                'learning' => $request->learning,
                'initiative' => $request->initiative,
                'time_management' => $request->time_management,
                'hr_total' => $hrTotal,
                'hr_comments' => $request->hr_comments,
            ]
        );

        $report->update(['hr_submitted' => true]);
    }

    /**
     * Save overall evaluation data
     */
    private function saveOverallEvaluation($report, $request)
    {
        // Calculate overall rating (sum of sliders, out of 100)
        $overallRating = ($request->technical_skills ?? 0) + ($request->task_delivery_score ?? 0) +
                        ($request->quality_work ?? 0) + ($request->communication_score ?? 0) +
                        ($request->behavior_teamwork ?? 0);

        // Determine performance grade based on overall rating
        $performanceGrade = $request->performance_grade ?? 'Satisfactory';
        if ($overallRating >= 80) {
            $performanceGrade = 'Excellent';
        } elseif ($overallRating >= 60) {
            $performanceGrade = 'Good';
        } elseif ($overallRating >= 40) {
            $performanceGrade = 'Satisfactory';
        } else {
            $performanceGrade = 'Needs Improvement';
        }

        EvaluationOverall::updateOrCreate(
            ['report_id' => $report->id],
            [
                'technical_skills' => $request->technical_skills ?? 0,
                'task_delivery' => $request->task_delivery_score ?? 0,
                'quality_work' => $request->quality_work ?? 0,
                'communication' => $request->communication_score ?? 0,
                'behavior_teamwork' => $request->behavior_teamwork ?? 0,
                'overall_rating' => $overallRating,
                'performance_grade' => $performanceGrade,
                'final_feedback' => $request->final_feedback,
            ]
        );

        $report->update([
            'overall_submitted' => true,
            'status' => 'completed',
            'overall_score' => $overallRating,
            'performance_grade' => $performanceGrade,
        ]);
    }

    /**
     * Get draft data for a specific report
     */
    public function getEvaluationDraft($id)
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin->hasPermission('performance')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $report = EvaluationReport::with(['employee', 'evaluationManager', 'evaluationHr', 'evaluationOverall'])
            ->findOrFail($id);

        return response()->json([
            'report' => $report,
            'employee' => $report->employee,
            'evaluationManager' => $report->evaluationManager,
            'evaluationHr' => $report->evaluationHr,
            'evaluationOverall' => $report->evaluationOverall
        ]);
    }

    /**
     * Get performance data for dashboard chart (last 30 days)
     */
    private function getDashboardPerformanceData()
    {
        $startDate = now()->subDays(30)->startOfDay();
        $endDate = now()->endOfDay();

        // Get evaluation reports from last 30 days
        $reports = EvaluationReport::with(['employee', 'evaluationOverall'])
            ->whereBetween('evaluation_date', [$startDate, $endDate])
            ->whereNotNull('overall_score')
            ->get();

        // Group by employee and calculate average performance
        $employeePerformance = [];
        foreach ($reports as $report) {
            if (!$report->evaluationOverall) continue;

            $empId = $report->employee_id;
            $score = $report->overall_score; // 0-100 scale

            if (!isset($employeePerformance[$empId])) {
                $employeePerformance[$empId] = [
                    'employee' => $report->employee,
                    'total_score' => 0,
                    'report_count' => 0,
                    'average_score' => 0
                ];
            }

            $employeePerformance[$empId]['total_score'] += $score;
            $employeePerformance[$empId]['report_count']++;
            $employeePerformance[$empId]['average_score'] = round($employeePerformance[$empId]['total_score'] / $employeePerformance[$empId]['report_count'], 1);
        }

        // Sort by average score descending and take top 10
        usort($employeePerformance, function($a, $b) {
            return $b['average_score'] <=> $a['average_score'];
        });

        $topPerformers = array_slice($employeePerformance, 0, 10);

        // Prepare data for Chart.js
        $labels = [];
        $scores = [];
        foreach ($topPerformers as $performance) {
            $labels[] = $performance['employee']->name;
            $scores[] = $performance['average_score'];
        }

        return [
            'labels' => $labels,
            'scores' => $scores,
            'hasData' => count($topPerformers) > 0
        ];
    }
}
