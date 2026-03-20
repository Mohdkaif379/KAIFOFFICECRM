<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeCardController extends Controller
{
    public function index(Request $request)
    {
        $employees = Employee::orderBy('name')->get();
        $employee = null;

        // जब dropdown से select हो
        if ($request->filled('employee')) {
            $employee = Employee::find($request->employee);
        }

        return view('employee-card.index', compact('employees', 'employee'));
    }

    public function pdf(Employee $employee = null)
    {
        if (!$employee) {
            $employee = auth('employee')->user();
        }
        $pdf = Pdf::loadView('employee-card-pdf', compact('employee'));
        return $pdf->download('employee_'.$employee->id.'_card.pdf');
    }
}
