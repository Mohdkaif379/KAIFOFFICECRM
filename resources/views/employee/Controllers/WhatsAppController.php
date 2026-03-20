<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Employee;

class WhatsAppController extends Controller
{
    private function bot()
    {
        return Http::withHeaders([
            'x-api-key' => config('services.whatsapp.api_key')
        ])->timeout(10);
    }

    public function index()
    {
       $employees = Employee::select('id', 'name', 'phone', 'status')
    ->whereNotNull('phone')
    ->where('status', 'active')
    ->get();


        return view('admin.whatsapp', [
            'employees' => $employees,
            'groups' => $this->getGroups(),
            'connectionStatus' => $this->getConnectionStatus(),
            'qrCode' => $this->getQrCode()
        ]);
    }

    /* ---------------- STATUS ---------------- */

    private function getConnectionStatus()
    {
        try {
            return $this->bot()
                ->get(config('services.whatsapp.bot_url').'/status')
                ->json();
        } catch (\Exception $e) {
            return ['status' => 'disconnected', 'user' => null];
        }
    }

    public function getStatus()
    {
        return response()->json($this->getConnectionStatus());
    }

    /* ---------------- QR ---------------- */

    private function getQrCode()
    {
        try {
            $res = $this->bot()
                ->get(config('services.whatsapp.bot_url').'/qr')
                ->json();

            return $res['qr'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getQrCodeAjax()
    {
        return response()->json(['qr' => $this->getQrCode()]);
    }

    /* ---------------- GROUPS ---------------- */

    private function getGroups()
    {
        try {
            return $this->bot()
                ->get(config('services.whatsapp.bot_url').'/groups')
                ->json();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getGroupsAjax()
    {
        return response()->json($this->getGroups());
    }

    /* ---------------- SEND MESSAGE ---------------- */

    public function sendMessage(Request $request)
    {
        $request->validate([
            'number' => 'required|string',
            'message' => 'nullable|string',
            'caption' => 'nullable|string',
            'media_file' => 'nullable|file',
        ]);

        // If media file is present, use media sending method
        if ($request->hasFile('media_file')) {
            return $this->sendMediaMessage($request);
        }

        // Otherwise, require message for text-only sending
        $request->validate([
            'message' => 'required|string',
        ]);

        try {
            $this->bot()->post(
                config('services.whatsapp.bot_url').'/send',
                $request->only('number', 'message')
            );

            return back()->with('success', 'Message sent successfully ✅');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendGroupMessage(Request $request)
    {
        $request->validate([
            'groupId' => 'required|string',
            'message' => 'nullable|string',
            'media_file' => 'nullable|file',
        ]);

        // If media file is present, use media sending method
        if ($request->hasFile('media_file')) {
            return $this->sendGroupMediaMessage($request);
        }

        // Otherwise, require message for text-only sending
        $request->validate([
            'message' => 'required|string',
        ]);

        try {
            $this->bot()->post(
                config('services.whatsapp.bot_url').'/send-group',
                $request->only('groupId', 'message')
            );

            return back()->with('success', 'Group message sent ✅');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendBulkMessage(Request $request)
    {
        $request->validate([
            'phone_numbers' => 'required|string',
            'message' => 'nullable|string',
            'media_file' => 'nullable|file',
        ]);

        // If media file is present, use media sending method
        if ($request->hasFile('media_file')) {
            return $this->sendBulkMediaMessage($request);
        }

        // Otherwise, require message for text-only sending
        $request->validate([
            'message' => 'required|string',
        ]);

        $numbers = array_filter(array_map('trim', explode("\n", $request->phone_numbers)));
        $sent = 0;

        foreach ($numbers as $phone) {
            if (!str_starts_with($phone, '91')) {
                $phone = '91'.$phone;
            }

            try {
                $this->bot()->post(
                    config('services.whatsapp.bot_url').'/send',
                    ['number' => $phone, 'message' => $request->message]
                );
                $sent++;
            } catch (\Exception $e) {
                // skip failed numbers
            }
        }

        return back()->with(
            $sent ? 'success' : 'error',
            "{$sent} message(s) sent successfully"
        );
    }

    /* ---------------- SEND MEDIA MESSAGES ---------------- */

    public function sendMediaMessage(Request $request)
    {
        $request->validate([
            'number' => 'required|string',
            'message' => 'nullable|string',
            'media_file' => 'required|file|max:51200',
        ]);

        try {
            $file = $request->file('media_file');

            Http::withHeaders([
                'x-api-key' => config('services.whatsapp.api_key'),
            ])->attach(
                'media',
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName()
            )->post(
                config('services.whatsapp.bot_url').'/send-media',
                [
                    'number'  => $request->number,
                    'caption' => $request->message ?? '',
                ]
            );

            return back()->with('success', 'Media message sent successfully ✅');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendBulkMediaMessage(Request $request)
    {
        $request->validate([
            'phone_numbers' => 'required|string',
            'message' => 'nullable|string',
            'media_file' => 'required|file',
        ]);

        $numbers = array_filter(array_map('trim', explode("\n", $request->phone_numbers)));
        $sent = 0;

        try {
            $file = $request->file('media_file');

            foreach ($numbers as $phone) {
                if (!str_starts_with($phone, '91')) {
                    $phone = '91'.$phone;
                }

                try {
                    Http::withHeaders([
                        'x-api-key' => config('services.whatsapp.api_key'),
                    ])->attach(
                        'media',
                        fopen($file->getRealPath(), 'r'),
                        $file->getClientOriginalName()
                    )->post(
                        config('services.whatsapp.bot_url').'/send-media',
                        [
                            'number'  => $phone,
                            'caption' => $request->message ?? '',
                        ]
                    );

                    $sent++;
                } catch (\Exception $e) {
                    // skip failed numbers
                }
            }

            return back()->with(
                $sent ? 'success' : 'error',
                "{$sent} media message(s) sent ✅"
            );

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sendGroupMediaMessage(Request $request)
    {
        $request->validate([
            'groupId' => 'required|string',
            'message' => 'nullable|string',
            'media_file' => 'required|file',
        ]);

        try {
            $file = $request->file('media_file');

            Http::withHeaders([
                'x-api-key' => config('services.whatsapp.api_key'),
            ])->attach(
                'file',
                fopen($file->getRealPath(), 'r'),
                $file->getClientOriginalName()
            )->post(
                config('services.whatsapp.bot_url').'/send-media',
                [
                    'number'  => $request->groupId,
                    'caption' => $request->message ?? '',
                ]
            );

            return back()->with('success', 'Group media sent ✅');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /* ---------------- START BOT ---------------- */

    public function startBot()
    {
        try {
            $this->bot()->post(config('services.whatsapp.bot_url').'/start');
            return response()->json([
                'success' => true,
                'message' => 'WhatsApp bot started successfully ✅'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /* ---------------- DELETE SESSION ---------------- */

    public function deleteSession()
    {
        try {
            $this->bot()->post(config('services.whatsapp.bot_url').'/delete-session');
            return response()->json([
                'success' => true,
                'message' => 'WhatsApp session deleted. Scan QR again ✅'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
