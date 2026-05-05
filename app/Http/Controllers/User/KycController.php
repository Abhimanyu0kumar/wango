<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserKycDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    /**
     * Get authenticated user's KYC status
     * GET /user/v1/kyc
     */
    public function show(Request $request)
    {
        $user = $this->getUser();

        $kycDocs = $user->kycDocuments;
        $kycStatus = $user->profile?->kyc_status ?? 0;

        $statusLabels = [
            0 => 'not_submitted',
            1 => 'pending_review',
            2 => 'verified',
            3 => 'rejected',
        ];

        return response()->json([
            'data' => [
                'kyc_status' => $kycStatus,
                'kyc_status_label' => $statusLabels[$kycStatus] ?? 'unknown',
                'documents' => $kycDocs
            ]
        ]);
    }

    /**
     * Submit KYC documents with file uploads
     * POST /user/v1/kyc
     */
    public function store(Request $request)
    {
        $user = $this->getUser();

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|in:passport,drivers_license,national_id,aadhaar,pan_card',
            'document_number' => 'required|string|max:50',
            'front_image' => 'required|image|mimes:jpeg,png,jpg,pdf|max:5120',
            'back_image' => 'nullable|image|mimes:jpeg,png,jpg,pdf|max:5120',
            'selfie_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if already verified
        if ($user->profile?->kyc_status === 2) {
            return response()->json(['message' => 'KYC already verified'], 400);
        }

        try {
            // Upload images
            $frontPath = $request->file('front_image')->store('kyc/' . $user->id, 'public');
            $frontUrl = Storage::url($frontPath);

            $backUrl = null;
            if ($request->hasFile('back_image')) {
                $backPath = $request->file('back_image')->store('kyc/' . $user->id, 'public');
                $backUrl = Storage::url($backPath);
            }

            $selfiePath = $request->file('selfie_image')->store('kyc/' . $user->id, 'public');
            $selfieUrl = Storage::url($selfiePath);

            // Deactivate any existing pending documents
            $user->kycDocuments()->where('status', 'pending')->update(['status' => 'replaced']);

            $kycDoc = UserKycDocument::create([
                'user_id' => $user->id,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'front_image_url' => $frontUrl,
                'back_image_url' => $backUrl,
                'selfie_image_url' => $selfieUrl,
                'status' => 'pending',
            ]);

            // Update profile KYC status to pending
            if ($user->profile) {
                $user->profile->update(['kyc_status' => 1]); // pending_review
            } else {
                $user->profile()->create(['kyc_status' => 1]);
            }

            return response()->json([
                'message' => 'KYC documents submitted successfully',
                'data' => $kycDoc
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to upload documents: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update KYC documents (if rejected)
     * PUT /user/v1/kyc
     */
    public function update(Request $request)
    {
        $user = $this->getUser();

        // Only allow update if rejected or not submitted
        if ($user->profile?->kyc_status === 2) {
            return response()->json(['message' => 'Cannot update verified KYC'], 400);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|in:passport,drivers_license,national_id,aadhaar,pan_card',
            'document_number' => 'required|string|max:50',
            'front_image' => 'required|image|mimes:jpeg,png,jpg,pdf|max:5120',
            'back_image' => 'nullable|image|mimes:jpeg,png,jpg,pdf|max:5120',
            'selfie_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Upload images
            $frontPath = $request->file('front_image')->store('kyc/' . $user->id, 'public');
            $frontUrl = Storage::url($frontPath);

            $backUrl = null;
            if ($request->hasFile('back_image')) {
                $backPath = $request->file('back_image')->store('kyc/' . $user->id, 'public');
                $backUrl = Storage::url($backPath);
            }

            $selfiePath = $request->file('selfie_image')->store('kyc/' . $user->id, 'public');
            $selfieUrl = Storage::url($selfiePath);

            // Mark old documents as replaced
            $user->kycDocuments()->whereIn('status', ['pending', 'rejected'])->update(['status' => 'replaced']);

            $kycDoc = UserKycDocument::create([
                'user_id' => $user->id,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'front_image_url' => $frontUrl,
                'back_image_url' => $backUrl,
                'selfie_image_url' => $selfieUrl,
                'status' => 'pending',
            ]);

            // Reset to pending review
            $user->profile?->update(['kyc_status' => 1]);

            return response()->json([
                'message' => 'KYC documents updated successfully',
                'data' => $kycDoc
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to upload documents: ' . $e->getMessage()], 500);
        }
    }
}
