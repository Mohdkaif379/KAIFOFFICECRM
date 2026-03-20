<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Employee;
use App\Models\Rating;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index()
    {
        $activities = Activity::with('employees')->get();
        return view('activities.index', compact('activities'));
    }

    public function create(Request $request)
    {
        $title = $request->input('title', '');
        $employees = Employee::all();
        return view('activities.create', compact('title', 'employees'));
    }

public function store(Request $request)
{
    $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'status' => 'required|in:pending,active,completed',
        'schedule_at' => 'nullable|date',
        'employees' => 'nullable|array',
        'employees.*' => 'exists:employees,id',
        'criteria' => 'nullable|array',
        'scoring_scope' => 'required|in:selected,all',
        'best_employee_id' => 'nullable|exists:employees,id',
        'best_employee_description' => 'nullable|string',
        'keep_best_employee' => 'boolean',
        'enable_best_employee' => 'boolean',
        'criteria.*.name' => 'required_with:criteria|string|max:255',
        'criteria.*.description' => 'nullable|string',
        'criteria.*.max_points' => 'required_with:criteria|integer|min:1',
    ]);


   $activity = Activity::create($request->only(['title', 'description', 'status', 'schedule_at', 'scoring_scope', 'best_employee_id', 'best_employee_description', 'keep_best_employee', 'enable_best_employee']));


    if ($request->has('employees')) {
        $activity->employees()->sync($request->input('employees'));
    }

    // ✅ Criteria save
    if ($request->has('criteria')) {
        foreach ($request->input('criteria') as $criterion) {
            $activity->criteria()->create($criterion);
        }
    }

    return redirect()->route('activities.index')->with('success', 'Activity created successfully.');
}



    public function show(Activity $activity)
    {
        // Get employees based on scoring scope
        if ($activity->scoring_scope === 'all') {
            $employees = Employee::all();
        } else {
            $employees = $activity->employees;
        }

        // Get all points for this activity
        $points = \App\Models\Point::where('activity_id', $activity->id)->get();

        // Get criteria for this activity
        $criterias = $activity->criteria;

        // Check if employees already have ratings today
        $today = now()->toDateString();
        $ratedEmployeeIds = \App\Models\Rating::where('rating_date', $today)->pluck('employee_id')->toArray();

        return view('activities.show', compact('activity', 'employees', 'points', 'criterias', 'ratedEmployeeIds'));
    }

    public function edit(Activity $activity)
    {
        $employees = \App\Models\Employee::all();
        return view('activities.edit', compact('activity', 'employees'));
    }

public function update(Request $request, Activity $activity)
{
    $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'status' => 'required|in:pending,active,completed',
        'schedule_at' => 'nullable|date',
        'employees' => 'nullable|array',
        'employees.*' => 'exists:employees,id',
        'scoring_scope' => 'required|in:selected,all',
        'best_employee_id' => 'nullable|exists:employees,id',
        'best_employee_description' => 'nullable|string',
        'keep_best_employee' => 'boolean',
        'criteria' => 'nullable|array',
        'criteria.*.name' => 'required_with:criteria|string|max:255',
        'criteria.*.description' => 'nullable|string',
        'criteria.*.max_points' => 'required_with:criteria|integer|min:1',
    ]);

    
    $activity->update($request->only(['title', 'description', 'status', 'schedule_at', 'scoring_scope', 'best_employee_id', 'best_employee_description', 'keep_best_employee', 'enable_best_employee']));


    if ($request->has('employees')) {
        $activity->employees()->sync($request->input('employees'));
    }

    // ✅ Purane criteria delete karke naya save
    $activity->criteria()->delete();

    if ($request->has('criteria')) {
        foreach ($request->input('criteria') as $criterion) {
            $activity->criteria()->create($criterion);
        }
    }

    return redirect()->route('activities.index')->with('success', 'Activity updated successfully.');
}


    public function destroy(Activity $activity)
    {
        $activity->delete();

        return redirect()->route('activities.index')->with('success', 'Activity deleted successfully.');
    }

    public function addToRatings(Activity $activity, Employee $employee)
    {
        // Calculate total points for this employee in this activity
        $totalPoints = \App\Models\Point::where('activity_id', $activity->id)
            ->where('to_employee_id', $employee->id)
            ->sum('points');

        // Check if rating already exists for this employee and activity date (optional)
        $existingRating = Rating::where('employee_id', $employee->id)
            ->whereDate('rating_date', now()->toDateString())
            ->first();

        if ($existingRating) {
            return redirect()->back()->with('error', 'Action already performed for this employee today.');
        }

        if ($totalPoints > 0) {
            // Create new rating with actual total points
            Rating::create([
                'employee_id' => $employee->id,
                'stars' => $totalPoints,
                'rating_date' => now(),
            ]);

            return redirect()->back()->with('success', 'Points added to ratings successfully.');
        }

        return redirect()->back()->with('error', 'No points found for this employee in this activity.');
    }

    public function rejectRating(Activity $activity, Employee $employee)
    {
        // Check if rating already exists for this employee and activity date
        $existingRating = Rating::where('employee_id', $employee->id)
            ->whereDate('rating_date', now()->toDateString())
            ->first();

        if ($existingRating) {
            return redirect()->back()->with('error', 'Action already performed for this employee today.');
        }

        // Create a rating with 0 stars to indicate rejection
        Rating::create([
            'employee_id' => $employee->id,
            'stars' => 0,
            'rating_date' => now(),
        ]);

        return redirect()->back()->with('success', 'Employee rejected successfully.');
    }
}
