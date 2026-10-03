<?php

namespace App\Actions\Content;

use App\Models\CommercialPartner;

/**
 * Creates/updates a partner's editable fields only — name, description,
 * url, display_order. `is_active` is deliberately never set here, the same
 * separation News/Events/CSR keep between field edits and their publish
 * workflow actions: activation is its own explicit admin action
 * (`Admin\CommercialPartnerController::activate()`/`deactivate()`), never a
 * side effect of a general field edit. The logo is handled separately too
 * (`UpdateCommercialPartnerImage`) since it is an upload, not a plain
 * field.
 *
 * New partners are created inactive (`is_active = false`), mirroring
 * `SaveProduct::create()` rather than `ProductCategory`'s DB-default-active
 * behaviour: a newly created partner has no logo yet (it's uploaded in a
 * second step, same as every other image-bearing entity in this app), so
 * defaulting to inactive avoids a half-finished entry appearing on the
 * public site before the admin uploads a logo and explicitly activates it.
 * This is a judgment call, not dictated by the approved requirements —
 * flagged in the implementation report.
 */
class SaveCommercialPartner
{
    /**
     * @param  array<string, mixed>  $data  Validated: name, description, url, display_order.
     */
    public function create(array $data): CommercialPartner
    {
        $data['is_active'] = false;

        return CommercialPartner::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommercialPartner $partner, array $data): CommercialPartner
    {
        $partner->fill($data)->save();

        return $partner;
    }
}
