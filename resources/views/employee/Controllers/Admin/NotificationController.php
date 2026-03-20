<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Notification;

class NotificationController extends Controller
{
    public function index()
    {
        $admin = Auth::guard('admin')->user();
        $notifications = Notification::where('admin_id', $admin->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Optionally mark all as read
        // Notification::where('admin_id', $admin->id)->where('is_read', false)->update(['is_read' => true]);

        return view('admin.notifications.index', compact('notifications'));
    }

    public function markAsRead($id)
    {
        // Parse ID to handle cases like "630:1" by taking the first part
        $parsedId = is_numeric($id) ? (int) $id : (int) explode(':', $id)[0];

        $admin = auth('admin')->user();
        $notification = \App\Models\Notification::where('id', $parsedId)
            ->where('admin_id', $admin->id)
            ->where('is_read', false)
            ->first();

        if ($notification) {
            $notification->is_read = true;
            $notification->save();
            return response()->json(['success' => true]);
        }
        return response()->json(['success' => false], 404);
    }

    public function destroy($id)
{
    $notification = Notification::findOrFail($id);
    $notification->delete();

    return response()->json([
        'success' => true,
        'message' => 'Notification deleted successfully'
    ]);
}

    public function ajax()
    {
        $admin = auth('admin')->user();
        if (!$admin) {
            return response()->json([
                'unread_count' => 0,
                'notifications' => []
            ]);
        }
        $unreadCount = Notification::where('admin_id', $admin->id)
            ->where('is_read', false)
            ->count();
        $notifications = Notification::where('admin_id', $admin->id)
            ->where('is_read', false)
            ->latest()
            ->take(5)
            ->get(['id', 'title', 'message', 'created_at']);
        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $notifications
        ]);
    }

    public function markAllRead()
    {
        $admin = auth('admin')->user();
        if (!$admin) {
            return response()->json(['success' => false], 401);
        }
        Notification::where('admin_id', $admin->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

}
