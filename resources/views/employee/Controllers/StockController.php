<?php

namespace App\Http\Controllers;

use App\Models\StockItem;
use App\Models\AssignedItem;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    /**
     * Display a listing of the stock items.
     */
    public function index()
    {
        $stockItems = StockItem::paginate(10);
        return view('admin.stock.index', compact('stockItems'));
    }

    /**
     * Show the form for creating a new stock item.
     */
    public function create()
    {
        return view('admin.stock.create');
    }

    /**
     * Store a newly created stock item in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'required|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
        ]);

        StockItem::create($validated);

        return redirect()->route('admin.stock.index')->with('success', 'Stock item created successfully.');
    }

    /**
     * Display the specified stock item.
     */
    public function show(StockItem $stockItem)
    {
        return view('admin.stock.show', compact('stockItem'));
    }

    /**
     * Show the form for editing the specified stock item.
     */
    public function edit(StockItem $stockItem)
    {
        return view('admin.stock.edit', compact('stockItem'));
    }

    /**
     * Update the specified stock item in storage.
     */
    public function update(Request $request, StockItem $stockItem)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'required|integer|min:0',
            'price' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
        ]);

        $stockItem->update($validated);

        return redirect()->route('admin.stock.index')->with('success', 'Stock item updated successfully.');
    }

    /**
     * Remove the specified stock item from storage.
     */
    public function destroy(StockItem $stockItem)
    {
        $stockItem->delete();
        return redirect()->route('admin.stock.index')->with('success', 'Stock item deleted successfully.');
    }

    /**
     * Show the form for assigning items to employees.
     */
    public function assignForm()
    {
        $stockItems = StockItem::all();
        $employees = Employee::select('id', 'name', 'employee_code')->get();
        return view('admin.stock.assign', compact('stockItems', 'employees'));
    }


    /**
     * Assign items to employees.
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'stock_item_id' => 'required|exists:stock_items,id',
            'employee_id' => 'required|exists:employees,id',
            'quantity_assigned' => 'required|integer|min:1',
            'assigned_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $stockItem = StockItem::findOrFail($validated['stock_item_id']);

        if ($validated['quantity_assigned'] > $stockItem->quantity) {
            return back()->withErrors(['quantity_assigned' => 'Cannot assign more than available quantity.'])->withInput();
        }

        AssignedItem::create($validated);

        // Update stock quantity
        $stockItem->decrement('quantity', $validated['quantity_assigned']);

        return redirect()->route('admin.stock.index')->with('success', 'Item assigned successfully.');
    }

    /**
     * Display assigned items for a specific employee.
     */
    public function employeeAssignedItems(Employee $employee)
    {
        $assignedItems = $employee->assignedItems()->with('stockItem')->get();
        return view('admin.stock.employee-assigned', compact('employee', 'assignedItems'));
    }

    /**
     * Display all assigned items for all employees.
     */
    public function allAssigned()
    {
        $assignedItems = AssignedItem::with('employee', 'stockItem')->get();
        return view('admin.stock.all-assigned', compact('assignedItems'));
    }

    /**
     * Show the form for editing the specified assigned item.
     */
    public function editAssigned(AssignedItem $assignedItem)
    {
        $stockItems = StockItem::all();
        $employees = Employee::select('id', 'name', 'employee_code')->get();
        return view('admin.stock.edit-assigned', compact('assignedItem', 'stockItems', 'employees'));
    }

    /**
     * Update the specified assigned item in storage.
     */
    public function updateAssigned(Request $request, AssignedItem $assignedItem)
    {
        $validated = $request->validate([
            'stock_item_id' => 'required|exists:stock_items,id',
            'employee_id' => 'required|exists:employees,id',
            'quantity_assigned' => 'required|integer|min:1',
            'assigned_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $oldQuantity = $assignedItem->quantity_assigned;
        $oldStockItemId = $assignedItem->stock_item_id;

        $assignedItem->update($validated);

        // Adjust stock quantities
        $stockItem = StockItem::findOrFail($validated['stock_item_id']);
        if ($oldStockItemId != $validated['stock_item_id']) {
            // Return old stock
            $oldStockItem = StockItem::findOrFail($oldStockItemId);
            $oldStockItem->increment('quantity', $oldQuantity);
            // Deduct new stock
            $stockItem->decrement('quantity', $validated['quantity_assigned']);
        } else {
            // Same stock item, adjust difference
            $difference = $oldQuantity - $validated['quantity_assigned'];
            if ($difference > 0) {
                $stockItem->increment('quantity', $difference);
            } elseif ($difference < 0) {
                $stockItem->decrement('quantity', abs($difference));
            }
        }

        return redirect()->route('admin.stock.all-assigned')->with('success', 'Assigned item updated successfully.');
    }

    /**
     * Remove the specified assigned item from storage.
     */
    public function destroyAssigned(AssignedItem $assignedItem)
    {
        $stockItem = StockItem::findOrFail($assignedItem->stock_item_id);
        $stockItem->increment('quantity', $assignedItem->quantity_assigned);

        $assignedItem->delete();

        return redirect()->route('admin.stock.all-assigned')->with('success', 'Assigned item deleted successfully.');
    }
}
