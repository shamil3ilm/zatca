<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing organization branches (EGS units).
 *
 * Handles branch CRUD operations and credential storage for multi-EGS support.
 */
class BranchService
{
    public function __construct(
        private readonly CredentialStore $credentials,
    ) {}

    /**
     * Create a new branch for an organization.
     */
    public function create(Organization $organization, array $data): Branch
    {
        return DB::transaction(function () use ($organization, $data) {
            // Generate device serial if not provided
            $deviceSerial = $data['device_serial'] ?? Branch::generateDeviceSerial($organization);

            $branch = Branch::create([
                'org_id' => $organization->id,
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'device_serial' => $deviceSerial,
                'industry' => $data['industry'] ?? 'General',
                'street' => $data['street'],
                'building_number' => $data['building_number'],
                'additional_number' => $data['additional_number'] ?? null,
                'district' => $data['district'],
                'city' => $data['city'],
                'postal_code' => $data['postal_code'],
                'country_code' => $data['country_code'] ?? 'SA',
            ]);

            // Set as default if first branch
            if ($organization->branches()->count() === 1) {
                $branch->setAsDefault();
            }

            return $branch;
        });
    }

    /**
     * Update branch details.
     *
     * Note: device_serial cannot be changed after onboarding.
     */
    public function update(Branch $branch, array $data): Branch
    {
        $updateData = array_filter([
            'name' => $data['name'] ?? null,
            'name_ar' => $data['name_ar'] ?? null,
            'industry' => $data['industry'] ?? null,
            'street' => $data['street'] ?? null,
            'building_number' => $data['building_number'] ?? null,
            'additional_number' => $data['additional_number'] ?? null,
            'district' => $data['district'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
        ], fn ($v) => $v !== null);

        // Prevent device_serial change if already onboarded
        if ($branch->onboarding_status !== Branch::STATUS_PENDING && isset($data['device_serial'])) {
            unset($data['device_serial']);
        }

        $branch->update($updateData);

        return $branch->fresh();
    }

    /**
     * Delete branch.
     *
     * Cannot delete if branch has invoices or is the only active branch.
     */
    public function delete(Branch $branch): bool
    {
        // Check for invoices
        if ($branch->invoices()->exists()) {
            throw new \Exception('Cannot delete branch with existing invoices. Suspend instead.');
        }

        // Check if default and other branches exist
        if ($branch->is_default) {
            $otherBranch = Branch::where('org_id', $branch->org_id)
                ->where('id', '!=', $branch->id)
                ->active()
                ->first();

            if ($otherBranch) {
                $otherBranch->setAsDefault();
            }
        }

        // Delete credentials
        $this->deleteCredentials($branch);

        return $branch->delete();
    }

    /**
     * The branch an invoice belongs to when it names none.
     *
     * Returns the branch marked default, else any active branch, else creates
     * "Main Branch". ZATCA attributes every document to an EGS unit, including
     * a single-site taxpayer's, so this always returns one rather than null.
     */
    public function getOrCreateDefault(Organization $organization): Branch
    {
        $defaultBranch = $organization->branches()
            ->where('is_default', true)
            ->first();

        if ($defaultBranch) {
            return $defaultBranch;
        }

        // Check for any active branch
        $anyBranch = $organization->branches()->active()->first();
        if ($anyBranch) {
            $anyBranch->setAsDefault();

            return $anyBranch;
        }

        // Create default branch using organization address
        return $this->create($organization, [
            'name' => 'Main Branch',
            'name_ar' => 'الفرع الرئيسي',
            'street' => $organization->street ?? 'Main Street',
            'building_number' => $organization->building_number ?? '0001',
            'additional_number' => $organization->additional_street,
            'district' => $organization->district ?? 'Central',
            'city' => $organization->city ?? 'Riyadh',
            'postal_code' => $organization->postal_code ?? '00000',
        ]);
    }

    /**
     * Store branch credentials securely.
     */
    public function storeCredentials(Branch $branch, string $type, array $data): void
    {
        $this->credentials->put($branch->org_id, $branch->id, $type, $data);
    }

    /**
     * Get branch credentials.
     */
    public function getCredentials(Branch $branch, string $type): ?array
    {
        return $this->credentials->get($branch->org_id, $branch->id, $type);
    }

    /**
     * Delete branch credentials.
     */
    public function deleteCredentials(Branch $branch): void
    {
        $this->credentials->forget($branch->org_id, $branch->id);
    }

    /**
     * Check if branch has PCSID credentials.
     */
    public function hasPcsid(Branch $branch): bool
    {
        return $this->getCredentials($branch, 'pcsid') !== null;
    }

    /**
     * Get branches with expiring certificates.
     */
    public function getExpiringCertificates(int $days = 30): Collection
    {
        return Branch::certificateExpiringSoon($days)
            ->with('org')
            ->get();
    }
}
