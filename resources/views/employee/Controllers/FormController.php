<?php

namespace App\Http\Controllers;

use App\Models\TripForm;
use Illuminate\Http\Request;
use App\Models\TourConveyanceForm;
use App\Models\ConveyanceDetail;
use Illuminate\Support\Facades\DB;


class FormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
  
public function index()
{
   $forms = TourConveyanceForm::with('conveyanceDetails')->latest()->get();

    return view('admin.form.index', compact('forms'));
}
    /**
     * Display the specified resource.
     */
    public function show()
    {
        $admin = auth('admin')->user();
        $form = null;
        return view('admin.form.trip', compact('admin', 'form'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $form = TourConveyanceForm::with('conveyanceDetails')->findOrFail($id);
        $admin = auth('admin')->user();
        return view('admin.form.trip', compact('form', 'admin'));
    }

    public function store(Request $request)
{
    DB::beginTransaction();

    try {
        $form = TourConveyanceForm::create([
            'form_code'          => $request->form_code,
            'company_name'       => $request->company_name,
            'company_address'    => $request->company_address,
            'form_heading'       => $request->form_heading,
            'form_subheading'    => $request->form_subheading,
            'form_date'          => $request->form_date,

            'employee_name'      => $request->employee_name,
            'employee_id'        => $request->employee_id,
            'designation'        => $request->designation,
            'department'         => $request->department,
            'reporting_manager'  => $request->reporting_manager,
            'cost_center'        => $request->cost_center,

            'purpose'            => $request->purpose,
            'tour_location'      => $request->tour_location,
            'project_code'       => $request->project_code,
            'tour_from'          => $request->tour_from,
            'tour_to'            => $request->tour_to,

            'advance_taken'      => $request->advance_taken ?? 0,
            'total_expense'      => $request->total_expense ?? 0,
            'balance_payable'    => $request->balance_payable ?? 0,
            'balance_receivable' => $request->balance_receivable ?? 0,

            'manager_remarks'    => $request->manager_remarks,
            'status'             => 'Pending',

            'footer_heading'     => $request->footer_heading,
            'footer_subheading'  => $request->footer_subheading,
        ]);

        foreach ($request->conveyance_details as $row) {
            $form->conveyanceDetails()->create([
                'travel_date'   => $row['travel_date'],
                'mode'          => $row['mode'],
                'from_location' => $row['from_location'],
                'to_location'   => $row['to_location'],
                'distance'      => $row['distance'] ?? 0,
                'amount'        => $row['amount'] ?? 0,
            ]);
        }

        // Calculate total expense from conveyance details
        $totalExpense = $form->conveyanceDetails()->sum('amount');
        $form->update([
            'total_expense' => $totalExpense,
            'balance_payable' => max(0, $totalExpense - ($request->advance_taken ?? 0)),
            'balance_receivable' => max(0, ($request->advance_taken ?? 0) - $totalExpense),
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Form saved successfully'
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $form = TourConveyanceForm::findOrFail($id);

            $form->update([
                'form_code'          => $request->form_code,
                'company_name'       => $request->company_name,
                'company_address'    => $request->company_address,
                'form_heading'       => $request->form_heading,
                'form_subheading'    => $request->form_subheading,
                'form_date'          => $request->form_date,

                'employee_name'      => $request->employee_name,
                'employee_id'        => $request->employee_id,
                'designation'        => $request->designation,
                'department'         => $request->department,
                'reporting_manager'  => $request->reporting_manager,
                'cost_center'        => $request->cost_center,

                'purpose'            => $request->purpose,
                'tour_location'      => $request->tour_location,
                'project_code'       => $request->project_code,
                'tour_from'          => $request->tour_from,
                'tour_to'           => $request->tour_to,

                'advance_taken'      => $request->advance_taken ?? 0,
                'total_expense'      => $request->total_expense ?? 0,
                'balance_payable'    => $request->balance_payable ?? 0,
                'balance_receivable' => $request->balance_receivable ?? 0,

                'manager_remarks'    => $request->manager_remarks,
                'status'             => $request->status ?? 'Pending',

                'footer_heading'     => $request->footer_heading,
                'footer_subheading'  => $request->footer_subheading,
            ]);

            // Delete existing conveyance details
            $form->conveyanceDetails()->delete();

            // Create new conveyance details
            foreach ($request->conveyance_details as $row) {
                $form->conveyanceDetails()->create([
                    'travel_date'   => $row['travel_date'],
                    'mode'          => $row['mode'],
                    'from_location' => $row['from_location'],
                    'to_location'   => $row['to_location'],
                    'distance'      => $row['distance'] ?? 0,
                    'amount'        => $row['amount'] ?? 0,
                ]);
            }

            // Calculate total expense from conveyance details
            $totalExpense = $form->conveyanceDetails()->sum('amount');
            $form->update([
                'total_expense' => $totalExpense,
                'balance_payable' => max(0, $totalExpense - ($request->advance_taken ?? 0)),
                'balance_receivable' => max(0, ($request->advance_taken ?? 0) - $totalExpense),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Form updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $form = TourConveyanceForm::findOrFail($id);

            // Delete associated conveyance details
            $form->conveyanceDetails()->delete();

            // Delete the form
            $form->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Form deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show the form preview with two-page layout.
     */
    public function preview($id)
    {
        $form = TourConveyanceForm::with('conveyanceDetails')->findOrFail($id);
        $admin = auth('admin')->user();
        return view('admin.form.preview', compact('form', 'admin'));
    }


}
