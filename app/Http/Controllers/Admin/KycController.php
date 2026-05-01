<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserKycDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class KycController extends Controller
{
    /**
     * List KYC documents with filters
     */
    public function index(Request $request)
    {
        $query = UserKycDocument::with(['user', 'verifier']);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('document_type')) {
            $query->where('document_type', $request->get('document_type'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $documents = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $documents->items(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Show specific KYC document
     */
    public function show(string $id)
    {
        $document = UserKycDocument::with(['user', 'verifier'])->find($id);

        if (!$document) {
            return response()->json(['message' => 'KYC document not found'], 404);
        }

        return response()->json(['data' => $document]);
    }

    /**
     * Approve KYC document
     */
    public function approve(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = UserKycDocument::find($id);

        if (!$document) {
            return response()->json(['message' => 'KYC document not found'], 404);
        }

        if ($document->status !== 'pending') {
            return response()->json(['message' => 'Only pending documents can be approved'], 400);
        }

        $admin = Auth::guard('admin')->user();

        DB::transaction(function () use ($document, $admin, $request) {
            $document->update([
                'status' => 'approved',
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'metadata' => array_merge($document->metadata ?? [], [
                    'admin_notes' => $request->get('notes'),
                ]),
            ]);

            // Update user's KYC status
            $user = $document->user;
            if ($user && $user->profile) {
                $user->profile->update(['kyc_status' => 'verified']);
            }
        });

        return response()->json([
            'message' => 'KYC document approved successfully',
            'data' => $document->fresh()->load('user', 'verifier'),
        ]);
    }

    /**
     * Reject KYC document
     */
    public function reject(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $document = UserKycDocument::find($id);

        if (!$document) {
            return response()->json(['message' => 'KYC document not found'], 404);
        }

        if ($document->status !== 'pending') {
            return response()->json(['message' => 'Only pending documents can be rejected'], 400);
        }

        $admin = Auth::guard('admin')->user();

        DB::transaction(function () use ($document, $admin, $request) {
            $document->update([
                'status' => 'rejected',
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'metadata' => array_merge($document->metadata ?? [], [
                    'rejection_reason' => $request->get('rejection_reason'),
                ]),
            ]);

            // Update user's KYC status
            $user = $document->user;
            if ($user && $user->profile) {
                $user->profile->update(['kyc_status' => 'rejected']);
            }
        });

        return response()->json([
            'message' => 'KYC document rejected',
            'data' => $document->fresh()->load('user', 'verifier'),
        ]);
    }

    /**
     * Get KYC statistics
     */
    public function stats()
    {
        $stats = [
            'total' => UserKycDocument::count(),
            'pending' => UserKycDocument::where('status', 'pending')->count(),
            'approved' => UserKycDocument::where('status', 'approved')->count(),
            'rejected' => UserKycDocument::where('status', 'rejected')->count(),
            'by_type' => UserKycDocument::selectRaw('document_type, status, COUNT(*) as count')
                ->groupBy('document_type', 'status')
                ->get(),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Bulk approve/reject
     */
    public function bulkAction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:user_kyc_documents,id',
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = Auth::guard('admin')->user();
        $action = $request->get('action');
        $ids = $request->get('ids');
        $count = 0;

        DB::transaction(function () use ($ids, $action, $admin, $request, &$count) {
            foreach ($ids as $id) {
                $document = UserKycDocument::find($id);
                
                if ($document && $document->status === 'pending') {
                    if ($action === 'approve') {
                        $document->update([
                            'status' => 'approved',
                            'verified_by' => $admin->id,
                            'verified_at' => now(),
                        ]);
                        if ($document->user && $document->user->profile) {
                            $document->user->profile->update(['kyc_status' => 'verified']);
                        }
                    } else {
                        $document->update([
                            'status' => 'rejected',
                            'verified_by' => $admin->id,
                            'verified_at' => now(),
                            'metadata' => array_merge($document->metadata ?? [], [
                                'rejection_reason' => $request->get('reason'),
                            ]),
                        ]);
                        if ($document->user && $document->user->profile) {
                            $document->user->profile->update(['kyc_status' => 'rejected']);
                        }
                    }
                    $count++;
                }
            }
        });

        return response()->json([
            'message' => "{$count} documents " . ($action === 'approve' ? 'approved' : 'rejected'),
            'processed_count' => $count,
        ]);
    }
}
