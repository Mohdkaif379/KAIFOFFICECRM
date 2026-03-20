<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use App\Models\SignalingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Mail\InterviewInviteMail;
use Illuminate\Support\Facades\Mail;
use App\Models\Admin;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;


class InterviewController extends Controller
{
    /**
     * Display a listing of the interviews.
     */
    public function index()
    {
        $interviews = Interview::orderBy('created_at', 'desc')->paginate(10);
        return view('admin.interviews.index', compact('interviews'));
    }

    /**
     * Show the form for creating a new interview.
     */
    public function create()
    {
        return view('admin.interviews.create');
    }

    /**
     * Store a newly created interview in storage.
     */


public function store(Request $request)
{
    $validated = $request->validate([
        'candidate_name' => 'required|string|max:255',
        'candidate_email' => 'required|email|max:255|unique:interviews,candidate_email',
        'candidate_phone' => 'nullable|string|max:15',
        'candidate_resume_path' => 'nullable|file|mimes:pdf,doc,docx',
        'candidate_profile' => 'nullable|string',
        'candidate_experience' => 'nullable|string',
        'date' => 'required|date',
        'time' => 'required|date_format:H:i',
        'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled', 'rescheduled'])],
        'interview_code' => 'nullable|string|max:255',
        'password' => 'nullable|string|max:255',
        'results' => ['nullable', Rule::in(['pending', 'selected', 'rejected'])],
        'round_count' => 'nullable|integer|min:1',
        'rounds' => 'nullable|array',
        'rounds.*.round_number' => 'nullable|integer|min:1',
        'rounds.*.remarks' => 'nullable|string',
        'rounds.*.conducted_by' => 'nullable|string',
    ]);

    // ✅ Generate UUIDs
    $validated['unique_id']   = (string) Str::uuid();
    $validated['unique_link'] = (string) Str::uuid();

    // ✅ Empty results → null
    if (empty($validated['results'])) {
        $validated['results'] = null;
    }

    // ✅ Encrypt password
    if (!empty($validated['password'])) {
        $validated['password'] = Crypt::encryptString($validated['password']);
    }

    // ✅ Resume upload
    if ($request->hasFile('candidate_resume_path')) {
        $validated['candidate_resume_path'] =
            $request->file('candidate_resume_path')->store('resumes', 'public');
    }

    // ✅ Map rounds to round_details
    $validated['round_details'] = $validated['rounds'] ?? [];
    unset($validated['rounds']);

    // ✅ Create interview
    $interview = Interview::create($validated);

    /* ===============================
       🔔 SEND EMAIL (HR → Candidate, CC → Admins)
    ================================= */

    try {
        Mail::mailer('hr_smtp')
            ->to($interview->candidate_email)   // Candidate
            ->cc(env('ADMIN_MAIL_USERNAME'))             // Admin CC
            ->send(new InterviewInviteMail($interview));

    } catch (\Exception $e) {
        Log::error('Interview invite mail failed: ' . $e->getMessage());
    }

    /* ===============================
   📲 SEND WHATSAPP MESSAGE TO CANDIDATE
================================= */

try {

    if (!empty($interview->candidate_phone)) {

        $phone = preg_replace('/\D/', '', $interview->candidate_phone);

        // India code add agar nahi hai
        if (!str_starts_with($phone, '91')) {
            $phone = '91' . $phone;
        }

        $whatsAppMessage = $this->buildInterviewWhatsAppMessage($interview);

        Http::withHeaders([
            'x-api-key' => 'SECRET123' ,
        ])->post(
            'https://bot.bitmaxgroup.com/send',
            [
                'number'  => $phone,
                'message' => $whatsAppMessage,
            ]
        );
    }

} catch (\Exception $e) {
    Log::error('WhatsApp interview message failed: ' . $e->getMessage());
}


    /* ===============================
       🔔 NOTIFICATIONS (SKIP SELF)
    ================================= */

    $actor = auth('admin')->user();
    $actorName = $actor ? $actor->name : 'Admin';

    $admins = Admin::all()->filter(function ($admin) use ($actor) {

        // ❌ Skip creator
        if ($actor && $admin->id === $actor->id) {
            return false;
        }

        return $admin->role === 'super_admin'
            || ($admin->role === 'sub_admin' && $admin->hasPermission('interviews'));
    });

    foreach ($admins as $adminUser) {
        Notification::create([
            'admin_id' => $adminUser->id,
            'title'    => 'New Interview Scheduled',
            'message'  => "{$actorName} scheduled an interview for {$interview->candidate_name} on {$interview->date} at {$interview->time}.",
            'is_read'  => false,
        ]);
    }

    /* =============================== */

    return redirect()
        ->route('admin.interviews.index')
        ->with('success', 'Interview created and email sent successfully.');
}
private function buildInterviewWhatsAppMessage($interview)
{
    $dateTime = \Carbon\Carbon::parse($interview->date)
        ->format('d M Y') . ' at ' . $interview->time;

    $interviewLink = 'https://www.bitmaxgroup.com/management/interview/' . $interview->unique_link;

    return
        "Dear Candidate,\n\n" .
        "You have been shortlisted for the Virtual Interview process.\n\n" .
        "*Position:* {$interview->candidate_profile}\n" .
        "*Interview Mode:* Online\n" .
        "*Date & Time:* {$dateTime}\n" .
        "*Location / Link:* {$interviewLink}\n\n" .
        "Please confirm your availability by replying to this message.\n\n" .
        "Regards,\n" .
        "Sakshi Sharma\n" .
        "Senior HR Executive\n" .
        "9211318269";
}


    /**
     * Display the specified interview.
     */
    public function show(Interview $interview)
    {
        return view('admin.interviews.show', compact('interview'));
    }

    /**
     * Show the form for editing the specified interview.
     */
    public function edit(Interview $interview)
    {
        return view('admin.interviews.edit', compact('interview'));
    }

    /**
     * Update the specified interview in storage.
     */
    public function update(Request $request, Interview $interview)
    {
        $validated = $request->validate([
            'candidate_name' => 'required|string|max:255',
            'candidate_email' => ['required', 'email', 'max:255', Rule::unique('interviews')->ignore($interview->id)],
            'candidate_phone' => 'nullable|string|max:15',
            'candidate_resume_path' => 'nullable|file|mimes:pdf,doc,docx',
            'candidate_profile' => 'nullable|string',
            'candidate_experience' => 'nullable|string',
            'date' => 'required|date',
            'time' => 'required|date_format:H:i',
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled', 'rescheduled'])],
            'interview_code' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'results' => ['nullable', Rule::in(['pending', 'selected', 'rejected'])],
            'round_count' => 'nullable|integer|min:1',
            'rounds' => 'nullable|array',
            'rounds.*.round_number' => 'nullable|integer|min:1',
            'rounds.*.remarks' => 'nullable|string',
            'rounds.*.conducted_by' => 'nullable|string',
        ]);

        // Handle empty results as null
        if (empty($validated['results'])) {
            $validated['results'] = null;
        }

        // Encrypt the password if provided and different from current
        if (!empty($validated['password'])) {
            $currentPassword = $interview->decrypted_password;
            if ($validated['password'] !== $currentPassword) {
                $validated['password'] = Crypt::encryptString($validated['password']);
            } else {
                // Password is the same, keep current encrypted password
                unset($validated['password']);
            }
        } elseif (empty($validated['password'])) {
            // If password is empty, keep the current encrypted password
            unset($validated['password']);
        }

        // Handle candidate resume upload
        if ($request->hasFile('candidate_resume_path')) {
            if ($interview->candidate_resume_path) {
                Storage::disk('public')->delete($interview->candidate_resume_path);
            }
            $validated['candidate_resume_path'] = $request->file('candidate_resume_path')->store('resumes', 'public');
        }

        $interview->update($validated);

        return redirect()->route('admin.interviews.index')->with('success', 'Interview updated successfully.');
    }

    /**
     * Remove the specified interview from storage.
     */
    public function destroy(Interview $interview)
    {
        // Delete associated files
        if ($interview->candidate_resume_path) {
            Storage::disk('public')->delete($interview->candidate_resume_path);
        }

        $interview->delete();

        return redirect()->route('admin.interviews.index')->with('success', 'Interview deleted successfully.');
    }

    /**
     * Show the interview link page for candidates.
     */
    public function showInterviewLink($unique_link)
    {
        $interview = Interview::where('unique_link', $unique_link)->first();

        if (!$interview) {
            abort(404, 'Interview link not found.');
        }

        return view('interview.link', compact('interview'));
    }

    /**
     * Verify interview credentials.
     */
    public function verifyCredentials(Request $request, $unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            $request->validate([
                'interview_code' => 'required|string',
                'password' => 'required|string',
            ]);

            $decryptedPassword = $interview->decrypted_password;
            if ($request->interview_code === $interview->interview_code && $request->password === $decryptedPassword) {
                // Set session flag for this interview link
                session(['interview_verified_' . $unique_link => true]);
                return response()->json([
                    'success' => true,
                    'message' => 'Credentials verified successfully! Interview access granted.',
                    'is_started' => $interview->is_started
                ], 200, [], JSON_UNESCAPED_SLASHES);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid interview code or password.'
            ], 401, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Start the interview.
     */
    public function startInterview($unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            $interview->update(['is_started' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Interview started successfully.'
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Show the interview room for candidates.
     */
    public function showInterviewRoom($unique_link)
    {
        Log::info('Session flag:', ['key' => 'interview_verified_' . $unique_link, 'value' => session('interview_verified_' . $unique_link, false)]);
        $interview = Interview::where('unique_link', $unique_link)->first();
        Log::info('Interview status:', [
            'is_started' => $interview ? $interview->is_started : null,
            'link_status' => $interview ? $interview->link_status : null,
        ]);

        if (!$interview) {
            abort(404, 'Interview not found.');
        }

        // Bypass for debugging or admin (optional)
        if (
            request()->query('bypass') == '1'
            // || auth()->check() && auth()->user()->isAdmin() // Uncomment if you have admin logic
        ) {
            session(['interview_verified_' . $unique_link => true]);
        }

        // Fallback: Allow access if credentials are passed in GET (not secure, only for debugging)
        if (
            request()->has('interview_code') &&
            request()->has('password') &&
            request()->input('interview_code') === $interview->interview_code &&
            request()->input('password') === $interview->decrypted_password
        ) {
            session(['interview_verified_' . $unique_link => true]);
        }

        // Allow access if interview is started, even if session flag is missing
        if (!$interview->is_started) {
            return redirect()->route('interview.link', $unique_link)->with('error', 'Interview has not started yet.');
        }

        // Only check session flag if interview is not started
        if (!$interview->is_started && !session('interview_verified_' . $unique_link, false)) {
            return redirect()->route('interview.link', $unique_link)->with('error', 'Please verify your credentials to enter the interview room.');
        }

        Log::info('Loading interview room for candidate: Interview ID ' . $interview->id . ', Unique Link: ' . $interview->unique_link);

        return view('interview.room', compact('interview') + ['is_interviewer' => false, 'is_candidate' => true]);
    }

    /**
     * Show the interview room for admins/interviewers.
     */
    public function showInterviewRoomAdmin(Interview $interview)
    {
        Log::info('Loading interview room for interviewer: Interview ID ' . $interview->id);

        return view('interview.room', compact('interview') + ['is_interviewer' => true, 'is_candidate' => false]);
    }

    /**
     * Log JavaScript errors from the frontend.
     */
    public function logError(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string', // Remove "in:" for flexibility
                'filename' => 'nullable|string',
                'lineno' => 'nullable|integer',
                'colno' => 'nullable|integer',
                'error' => 'nullable|string',
            ]);

            Log::error('JavaScript Error: ' . $request->message, [
                'filename' => $request->filename,
                'lineno' => $request->lineno,
                'colno' => $request->colno,
                'error' => $request->error,
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
            ]);

            return response()->json(['success' => true], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Send WebRTC signaling message (including questions).
     */
    public function sendSignalingMessage(Request $request, $unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            $type = $request->query('type', $request->input('type'));
            $sender = $request->query('sender_type', $request->input('sender_type'));

            // Very important: raw body for SDP
            $contentType = $request->header('Content-Type');
            $sdp = null;

            if (str_contains($contentType, 'text/plain') || str_contains($contentType, 'application/sdp')) {
                $sdp = $request->getContent(); // RAW SDP!
            } else {
                $sdp = $request->input('sdp');
            }

            $msg = SignalingMessage::create([
                'interview_id' => $interview->id,
                'sender_type'  => $sender,
                'type'         => $type,
                'sdp'          => $sdp,
                'ice_candidate'=> $request->input('ice_candidate'),
                'text'         => $request->input('text'),
                'question_id'  => $request->input('question_id'),
            ]);

            return response()->json([
                'success' => true,
                'message_id' => $msg->id
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true, 'message' => $e->getMessage()], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Get pending WebRTC signaling messages.
     */
    public function getSignalingMessages(Request $request, $unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            $request->validate([
                'receiver_type' => 'required|string', // Remove "in:" for flexibility
                'last_message_id' => 'nullable|integer',
                'check_online' => 'nullable|boolean',
            ]);

            $receiverType = $request->receiver_type;

            // Check if peer is online (has sent messages recently)
            $peerOnline = false;
            if ($request->check_online) {
                $oppositeType = $receiverType === 'interviewer' ? 'candidate' : 'interviewer';
                $recentMessages = SignalingMessage::where('interview_id', $interview->id)
                    ->where('sender_type', $oppositeType)
                    ->where('created_at', '>=', now()->subSeconds(30)) // Active in last 30 seconds
                    ->exists();
                $peerOnline = $recentMessages;
            }

            $query = SignalingMessage::where('interview_id', $interview->id)
                ->where('delivered', false)
                ->where(function ($q) use ($receiverType) {
                    // Target must match receiver, or null (broadcast)
                    $q->where('target_type', $receiverType)
                      ->orWhereNull('target_type');
                });

            if ($request->last_message_id) {
                $query->where('id', '>', $request->last_message_id);
            }

            $messages = $query->orderBy('created_at', 'asc')->get();

            // Mark messages as delivered
            foreach ($messages as $message) {
                $message->markAsDelivered();
            }

            return response()->json([
                'success' => true,
                'peer_online' => $peerOnline,
                'messages' => $messages->map(function ($message) {
                    $response = [
                        'id' => $message->id,
                        'type' => $message->type,
                        'sender_type' => $message->sender_type,
                        'created_at' => $message->created_at->toISOString(),
                    ];

                    if ($message->type === 'offer' || $message->type === 'answer') {
                        Log::info('Sending SDP length=' . strlen($message->sdp));
                        $response['sdp'] = $message->sdp;
                    } elseif ($message->type === 'ice-candidate') {
                        $response['ice_candidate'] = $message->ice_candidate;
                    } elseif ($message->type === 'question') {
                        $response['text'] = $message->text;
                        $response['question_id'] = $message->question_id;
                    }

                    return $response;
                }),
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }

        /**
     * Clear old signaling messages for an interview.
     */
    public function clearSignalingMessages($unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            // Delete messages older than 1 hour
            SignalingMessage::where('interview_id', $interview->id)
                ->where('created_at', '<', now()->subHour())
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Signaling messages cleared successfully.'
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }
     public function toggleLinkStatus($id)
    {
        $interview = Interview::findOrFail($id);

        // Toggle logic (0 <-> 1)
        $interview->link_status = $interview->link_status == '1' ? '0' : '1';
        $interview->save();

        return redirect()->back()->with('success', 'Link status updated successfully');
    }

    /**
     * End the interview and deactivate the link (for both sides).
     */
    public function endInterview($unique_link)
    {
        try {
            $interview = Interview::where('unique_link', $unique_link)->first();

            if (!$interview) {
                return response()->json(['success' => false, 'message' => 'Interview not found.'], 404, [], JSON_UNESCAPED_SLASHES);
            }

            // Set link_status and is_started to 0 (inactive)
            $interview->update([
                'link_status' => '0',
                'is_started' => 0
            ]);

            // Broadcast a socket.io message to force end for the other side
            try {
                $http = new \GuzzleHttp\Client(['timeout' => 2]);
                $socketServer = 'https://socket.bitmaxgroup.com/interview-force-end';
                $http->post($socketServer, [
                    'json' => [
                        'room' => 'interview.' . $unique_link,
                        'action' => 'force-end'
                    ]
                ]);
            } catch (\Throwable $e) {
                Log::warning('Could not notify socket server for force end: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Interview ended and link deactivated.'
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => true], 500, [], JSON_UNESCAPED_SLASHES);
        }
    }
}

