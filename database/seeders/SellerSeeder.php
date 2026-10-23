<?php

namespace Database\Seeders;

use App\Enums\SellerStatus;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class SellerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->isLocal() && ! app()->environment('testing')) {
            return;
        }

        $this->ensureSampleDocumentsExist();

        // 1. Approved Demo Seller
        $approvedUser = User::firstOrCreate(
            ['email' => 'seller@example.com'],
            [
                'name' => 'Demo Seller',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $approvedProfile = SellerProfile::firstOrCreate(
            ['user_id' => $approvedUser->id],
            [
                'store_name' => 'Khilauna Toys & Games',
                'store_slug' => 'khilauna-toys',
                'business_type' => 'company',
                'description' => 'Official demo seller offering toys, collectibles, action figures, and board games.',
                'support_email' => 'seller@example.com',
                'support_phone' => '9876543210',
                'pickup_address_line1' => 'Plot 42, Toy Street, MIDC Industrial Area',
                'pickup_address_line2' => 'Phase 2, Andheri East',
                'pickup_city' => 'Mumbai',
                'pickup_state' => 'Maharashtra',
                'pickup_postal_code' => '400093',
                'pickup_country' => 'India',
                'pan' => 'ABCDE1234F',
                'gstin' => '27ABCDE1234F1Z5',
                'bank_account_holder' => 'Khilauna Retail LLP',
                'bank_account_number' => '123456789012',
                'ifsc' => 'HDFC0001234',
                'status' => SellerStatus::Approved,
                'current_step' => 5,
                'submitted_at' => now()->subDays(10),
                'approved_at' => now()->subDays(5),
                'reviewed_at' => now()->subDays(5),
            ]
        );

        $this->attachSampleDocuments($approvedProfile);

        // 2. Pending Seller (Awaiting Admin Review)
        $pendingUser = User::firstOrCreate(
            ['email' => 'pending.seller@example.com'],
            [
                'name' => 'Pending Seller',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $pendingProfile = SellerProfile::firstOrCreate(
            ['user_id' => $pendingUser->id],
            [
                'store_name' => 'Apex Toy Studio',
                'store_slug' => 'apex-toy-studio',
                'business_type' => 'individual',
                'description' => 'Handcrafted wooden toys and educational board games.',
                'support_email' => 'pending.seller@example.com',
                'support_phone' => '9811122233',
                'pickup_address_line1' => '12 Craft Guild Lane, Whitefield',
                'pickup_city' => 'Bengaluru',
                'pickup_state' => 'Karnataka',
                'pickup_postal_code' => '560066',
                'pickup_country' => 'India',
                'pan' => 'PQXYZ9876K',
                'gstin' => '29PQXYZ9876K1Z8',
                'bank_account_holder' => 'Pending Seller',
                'bank_account_number' => '987654321098',
                'ifsc' => 'SBIN0004321',
                'status' => SellerStatus::Pending,
                'current_step' => 5,
                'submitted_at' => now()->subHours(12),
            ]
        );

        $this->attachSampleDocuments($pendingProfile);

        // 3. Rejected Seller (With Rejection Reason)
        $rejectedUser = User::firstOrCreate(
            ['email' => 'rejected.seller@example.com'],
            [
                'name' => 'Rejected Seller',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $rejectedProfile = SellerProfile::firstOrCreate(
            ['user_id' => $rejectedUser->id],
            [
                'store_name' => 'Pixel Novelties',
                'store_slug' => 'pixel-novelties',
                'business_type' => 'individual',
                'description' => 'Pop culture merchandise and figurines.',
                'support_email' => 'rejected.seller@example.com',
                'support_phone' => '9822233344',
                'pickup_address_line1' => '88 Sector 18',
                'pickup_city' => 'Noida',
                'pickup_state' => 'Uttar Pradesh',
                'pickup_postal_code' => '201301',
                'pickup_country' => 'India',
                'pan' => 'LMNOP4567Q',
                'gstin' => '09LMNOP4567Q1Z2',
                'bank_account_holder' => 'Rejected Seller',
                'bank_account_number' => '555566667777',
                'ifsc' => 'ICIC0000001',
                'status' => SellerStatus::Rejected,
                'current_step' => 5,
                'submitted_at' => now()->subDays(3),
                'reviewed_at' => now()->subDay(),
                'rejection_reason' => 'PAN card name does not match bank account holder details. Please re-upload legible documentation.',
            ]
        );

        $this->attachSampleDocuments($rejectedProfile);

        // 4. Approved Seller with Updated Bank Details (Triggers Admin Alert)
        $bankUpdatedUser = User::firstOrCreate(
            ['email' => 'bankchanged.seller@example.com'],
            [
                'name' => 'Bank Alert Seller',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $bankUpdatedProfile = SellerProfile::firstOrCreate(
            ['user_id' => $bankUpdatedUser->id],
            [
                'store_name' => 'Galaxy Hobby Store',
                'store_slug' => 'galaxy-hobby-store',
                'business_type' => 'individual',
                'description' => 'Model kits, drones, and remote controlled vehicles.',
                'support_email' => 'bankchanged.seller@example.com',
                'support_phone' => '9833344455',
                'pickup_address_line1' => '404 Tech Park, Hitec City',
                'pickup_city' => 'Hyderabad',
                'pickup_state' => 'Telangana',
                'pickup_postal_code' => '500081',
                'pickup_country' => 'India',
                'pan' => 'RSTUV5678W',
                'gstin' => '36RSTUV5678W1Z1',
                'bank_account_holder' => 'Galaxy Hobby Retail',
                'bank_account_number' => '444455556666',
                'ifsc' => 'KKBK0001234',
                'status' => SellerStatus::Approved,
                'current_step' => 5,
                'submitted_at' => now()->subDays(20),
                'approved_at' => now()->subDays(15),
                'reviewed_at' => now()->subDays(15),
                'bank_updated_at' => now()->subHours(2),
            ]
        );

        $this->attachSampleDocuments($bankUpdatedProfile);
    }

    /**
     * Ensure dummy KYC documents exist in local storage for safe viewing/download.
     */
    protected function ensureSampleDocumentsExist(): void
    {
        $dummyPdf = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n183\n%%EOF";

        if (! Storage::disk('local')->exists('seller-documents/sample-id-proof.pdf')) {
            Storage::disk('local')->put('seller-documents/sample-id-proof.pdf', $dummyPdf);
        }

        if (! Storage::disk('local')->exists('seller-documents/sample-address-proof.pdf')) {
            Storage::disk('local')->put('seller-documents/sample-address-proof.pdf', $dummyPdf);
        }
    }

    /**
     * Attach sample KYC documents to the seller profile if not already attached.
     */
    protected function attachSampleDocuments(SellerProfile $profile): void
    {
        SellerDocument::firstOrCreate(
            [
                'seller_profile_id' => $profile->id,
                'type' => 'id_proof',
            ],
            [
                'path' => 'seller-documents/sample-id-proof.pdf',
                'original_name' => 'PAN_Card.pdf',
            ]
        );

        SellerDocument::firstOrCreate(
            [
                'seller_profile_id' => $profile->id,
                'type' => 'address_proof',
            ],
            [
                'path' => 'seller-documents/sample-address-proof.pdf',
                'original_name' => 'GST_Certificate.pdf',
            ]
        );
    }
}
