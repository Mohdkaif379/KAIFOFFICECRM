<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\BudgetHistory;
use App\Exports\MonthlyExpenseExport;
use App\Traits\Loggable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    use Loggable;

    public function index(Request $request)
    {
        $query = Expense::with('admin');

        // Default to current month and year if not provided
        $month = $request->filled('month') ? $request->month : date('m');
        $year = $request->filled('year') ? $request->year : date('Y');

        $query->whereYear('expense_date', $year)
              ->whereMonth('expense_date', $month);

        $expenses = $query->orderBy('expense_date', 'desc')->paginate(10);
        $budget = ExpenseBudget::getCurrentBudget();

        // Calculate monthly spent and remaining
        $monthlySpent = $expenses->sum('amount');
        $monthlyRemaining = $budget->remaining_amount - $monthlySpent;

        return view('expenses.index', compact('expenses', 'budget', 'month', 'year', 'monthlySpent', 'monthlyRemaining'));
    }

    public function create()
    {
        return view('expenses.create');
    }

public function store(Request $request)
{
    $request->validate([
        'title' => 'required|string|max:255',
        'amount' => 'required|numeric',
        'category' => 'required|string|max:100',
        'expense_date' => 'required|date',
    ]);

    $budget = ExpenseBudget::getCurrentBudget();

    // ✅ Logged-in admin / sub-admin (actor)
    $actor = Auth::guard('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    // ✅ Create expense
    $expense = Expense::create([
        'title' => $request->title,
        'amount' => $request->amount,
        'category' => $request->category,
        'expense_date' => $request->expense_date,
        'created_by' => $actor?->id,
    ]);

    // ✅ Deduct from budget (allow negative)
    $budget->remaining_amount -= $request->amount;
    $budget->save();

    // ✅ Log activity
    $this->logActivity(
        'create',
        'Expense',
        $expense->id,
        "Created expense: {$expense->title} for ₹{$expense->amount}"
    );

    /* ===============================
       🔔 NOTIFICATIONS (SKIP SELF)
    ================================= */

    // ✅ Super Admin + Sub Admins with expenses permission
    $admins = \App\Models\Admin::all()->filter(function ($admin) use ($actor) {

        // ❌ Skip the admin who added the expense
        if ($actor && $admin->id === $actor->id) {
            return false;
        }

        return $admin->role === 'super_admin'
            || ($admin->role === 'sub_admin' && $admin->hasPermission('expenses'));
    });

    foreach ($admins as $adminUser) {
        \App\Models\Notification::create([
            'admin_id' => $adminUser->id,
            'title' => 'New Expense Added',
            'message' => "{$actorName} added an expense '{$expense->title}' of Rs.{$expense->amount} under {$expense->category}.",
            'is_read' => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('admin.expenses.index')
        ->with('success', 'Expense added successfully!');
}


    public function edit(Expense $expense)
    {
        return view('expenses.edit', compact('expense'));
    }

    public function update(Request $request, Expense $expense)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'category' => 'required|string|max:100',
            'expense_date' => 'required|date',
        ]);

        $budget = ExpenseBudget::getCurrentBudget();
        $oldAmount = $expense->amount;
        $newAmount = $request->amount;

        // Remove budget check, allow negative remaining
        $expense->update($request->all());

        // Adjust budget (allow negative)
        $budget->remaining_amount += $oldAmount; // add back old
        $budget->remaining_amount -= $newAmount; // subtract new
        $budget->save();

        // Log activity
        $this->logActivity('update', 'Expense', $expense->id, "Updated expense: {$request->title} from ₹{$oldAmount} to ₹{$newAmount}");

        return redirect()->route('admin.expenses.index')->with('success', 'Expense updated successfully!');
    }

    public function destroy(Expense $expense)
    {
        $budget = ExpenseBudget::getCurrentBudget();
        $amount = $expense->amount;

        $expense->delete();

        // Add back to budget (allow negative)
        $budget->remaining_amount += $amount;
        $budget->save();

        // Log activity
        $this->logActivity('delete', 'Expense', $expense->id, "Deleted expense: {$expense->title} for ₹{$amount}");

        return redirect()->route('admin.expenses.index')->with('success', 'Expense deleted successfully!');
    }

    public function updateBudget(Request $request)
    {
        // Only super admin can update budget
        if (!auth('admin')->user()->is_super_admin ?? false) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'budget_amount' => 'required|numeric|min:0',
        ]);

        $budget = ExpenseBudget::getCurrentBudget();
        $oldBudgetAmount = $budget->budget_amount;
        $newBudgetAmount = $request->budget_amount;

        // Calculate new remaining amount
        $spentAmount = $budget->budget_amount - $budget->remaining_amount;
        $newRemainingAmount = max(0, $newBudgetAmount - $spentAmount);

        $budget->update([
            'budget_amount' => $newBudgetAmount,
            'remaining_amount' => $newRemainingAmount,
        ]);

        // Log history
        BudgetHistory::create([
            'action' => 'update',
            'amount' => $newBudgetAmount - $oldBudgetAmount,
            'old_budget' => $oldBudgetAmount,
            'new_budget' => $newBudgetAmount,
            'remaining' => $newRemainingAmount,
            'created_by' => Auth::guard('admin')->id(),
        ]);

        return redirect()->route('admin.expenses.index')->with('success', 'Budget updated successfully!');
    }

    public function addBudget(Request $request)
    {
        // Only super admin can add budget
        if (!auth('admin')->user()->is_super_admin ?? false) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'add_amount' => 'required|numeric|min:0',
        ]);

        $budget = ExpenseBudget::getCurrentBudget();
        $oldBudgetAmount = $budget->budget_amount;
        $addAmount = $request->add_amount;

        $budget->budget_amount += $addAmount;
        $budget->remaining_amount += $addAmount;
        $budget->save();

        // Log history
        BudgetHistory::create([
            'action' => 'add',
            'amount' => $addAmount,
            'old_budget' => $oldBudgetAmount,
            'new_budget' => $budget->budget_amount,
            'remaining' => $budget->remaining_amount,
            'created_by' => Auth::guard('admin')->id(),
        ]);

        return redirect()->route('admin.expenses.index')->with('success', 'Budget added successfully! Added ₹' . number_format($addAmount, 2));
    }

    public function export($month, $year)
    {
        // Only super admin can export reports
        if (!auth('admin')->user()->is_super_admin ?? false) {
            abort(403, 'Unauthorized');
        }

        return Excel::download(new MonthlyExpenseExport($month, $year), "expenses_{$month}_{$year}.xlsx");
    }
}
