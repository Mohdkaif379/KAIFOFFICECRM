<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use App\Mail\TaskAssigned;

class TaskController extends Controller
{
    /**
     * Display a listing of the tasks.
     */
    public function index()
    {
        $tasks = Task::with(['assignedEmployee', 'teamLead'])->paginate(10);
        return view('task.index', compact('tasks'));
    }

    /**
     * Show the form for creating a new task.
     */
    public function create()
    {
        // Get employees who are not already assigned to any task
        $assignedEmployeeIds = Task::whereNotNull('assigned_to')
            ->pluck('assigned_to')
            ->toArray();

        $teamLeadIds = Task::whereNotNull('team_lead_id')
            ->pluck('team_lead_id')
            ->toArray();

        $teamMemberIds = [];
        Task::whereNotNull('team_members')->each(function ($task) use (&$teamMemberIds) {
            if ($task->team_members) {
                $teamMemberIds = array_merge($teamMemberIds, $task->team_members);
            }
        });

        $excludedIds = array_unique(array_merge($assignedEmployeeIds, $teamLeadIds, $teamMemberIds));

        $employees = Employee::select('id', 'name', 'employee_code')
            ->where('status', 'active')
            ->whereNotIn('id', $excludedIds)
            ->get();

        return view('task.create', compact('employees'));
    }

    /**
     * Store a newly created task in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'task_name' => 'required|string|max:255',
            'description' => 'required|string',
            'assigned_to' => 'nullable|exists:employees,id',
            'assigned_team' => ['required', Rule::in(['Individual', 'Team'])],
            'team_members' => 'nullable|array',
            'team_members.*' => 'exists:employees,id',
            'team_lead_id' => 'nullable|required_if:assigned_team,Team|exists:employees,id',
            'team_created_by' => ['nullable', Rule::in(['admin', 'team_lead'])],
            'selected_team' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => ['required', Rule::in(['Not Started', 'In Progress', 'Completed', 'On Hold'])],
            'priority' => ['required', Rule::in(['Low', 'Medium', 'High'])],
            'progress' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validated['assigned_team'] === 'Individual') {
            $validated['team_members'] = null;
            $validated['team_lead_id'] = null;
        } elseif ($validated['assigned_team'] === 'Team') {
            $validated['assigned_to'] = null;
        }

        $task = Task::create($validated);

        $assignees = $this->getAssignees($task);
        foreach ($assignees as $employee) {
            Mail::to($employee->email)->send(new TaskAssigned($task, $employee));
        }

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task)
    {
        $task->load(['assignedEmployee', 'teamLead']);
        return view('task.show', compact('task'));
    }

    /**
     * Show the form for editing the specified task.
     */
    public function edit(Task $task)
    {
        // Get employees who are not already assigned to any task (excluding current task's assignments)
        $assignedEmployeeIds = Task::whereNotNull('assigned_to')
            ->where('id', '!=', $task->id)
            ->pluck('assigned_to')
            ->toArray();

        $teamLeadIds = Task::whereNotNull('team_lead_id')
            ->where('id', '!=', $task->id)
            ->pluck('team_lead_id')
            ->toArray();

        $teamMemberIds = [];
        Task::whereNotNull('team_members')
            ->where('id', '!=', $task->id)
            ->each(function ($t) use (&$teamMemberIds) {
                if ($t->team_members) {
                    $teamMemberIds = array_merge($teamMemberIds, $t->team_members);
                }
            });

        $excludedIds = array_unique(array_merge($assignedEmployeeIds, $teamLeadIds, $teamMemberIds));

        // Get available employees (not assigned to other tasks)
        $availableEmployees = Employee::select('id', 'name', 'employee_code')
            ->where('status', 'active')
            ->whereNotIn('id', $excludedIds)
            ->get();

        // Get currently assigned employees for this task (to include them in the dropdown)
        $currentAssignedIds = [];
        if ($task->assigned_to) {
            $currentAssignedIds[] = $task->assigned_to;
        }
        if ($task->team_lead_id) {
            $currentAssignedIds[] = $task->team_lead_id;
        }
        if ($task->team_members) {
            $currentAssignedIds = array_merge($currentAssignedIds, $task->team_members);
        }

        $currentEmployees = Employee::select('id', 'name', 'employee_code')
            ->whereIn('id', $currentAssignedIds)
            ->get();

        // Merge available and current employees
        $employees = $availableEmployees->merge($currentEmployees)->unique('id');

        $tasks = Task::select('id', 'task_name')->get();
        return view('task.edit', compact('task', 'employees', 'tasks'));
    }

    /**
     * Update the specified task in storage.
     */
    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'task_name' => 'required|string|max:255',
            'description' => 'required|string',
            'assigned_to' => 'nullable|exists:employees,id',
            'assigned_team' => ['required', Rule::in(['Individual', 'Team'])],
            'team_members' => 'nullable|array',
            'team_members.*' => 'exists:employees,id',
            'team_lead_id' => 'nullable|required_if:assigned_team,Team|exists:employees,id',
            'team_created_by' => ['nullable', Rule::in(['admin', 'team_lead'])],
            'selected_team' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => ['required', Rule::in(['Not Started', 'In Progress', 'Completed', 'On Hold'])],
            'priority' => ['required', Rule::in(['Low', 'Medium', 'High'])],
            'progress' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validated['assigned_team'] === 'Individual') {
            $validated['team_members'] = null;
            $validated['team_lead_id'] = null;
        } elseif ($validated['assigned_team'] === 'Team') {
            $validated['assigned_to'] = null;
        }

        $oldAssignedTo = $task->assigned_to;
        $oldTeamLead = $task->team_lead_id;
        $oldTeamMembers = $task->team_members;

        $task->update($validated);

        if ($task->assigned_to != $oldAssignedTo || $task->team_lead_id != $oldTeamLead || $task->team_members != $oldTeamMembers) {
            $assignees = $this->getAssignees($task);
            foreach ($assignees as $employee) {
                Mail::to($employee->email)->send(new TaskAssigned($task, $employee));
            }
        }

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    /**
     * Remove the specified task from storage.
     */
    public function destroy(Task $task)
    {
        $task->delete();
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    /**
     * Get the assignees for a task.
     */
    private function getAssignees(Task $task)
    {
        $assignees = [];
        if ($task->assigned_to) {
            $employee = $task->assignedEmployee;
            if ($employee) {
                $assignees[] = $employee;
            }
        } elseif ($task->assigned_team === 'Team') {
            if ($task->team_lead_id) {
                $lead = $task->teamLead;
                if ($lead) {
                    $assignees[] = $lead;
                }
            }
            if ($task->team_members) {
                $members = Employee::whereIn('id', $task->team_members)->get();
                $assignees = array_merge($assignees, $members->toArray());
            }
        }
        return array_unique($assignees, SORT_REGULAR);
    }
}
